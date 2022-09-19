<?php
/**
 * Copyright © Rhubarb Tech Inc. All Rights Reserved.
 *
 * All information contained herein is, and remains the property of Rhubarb Tech Incorporated.
 * The intellectual and technical concepts contained herein are proprietary to Rhubarb Tech Incorporated and
 * are protected by trade secret or copyright law. Dissemination and modification of this information or
 * reproduction of this material is strictly forbidden unless prior written permission is obtained from
 * Rhubarb Tech Incorporated.
 *
 * You should have received a copy of the `LICENSE` with this file. If not, please visit:
 * https://objectcache.pro/license.txt
 */

declare(strict_types=1);

namespace RedisCachePro\Connectors;

use Redis;
use RedisCluster;

use RedisCachePro\Configuration\Configuration;

use RedisCachePro\Connections\PhpRedisConnection;
use RedisCachePro\Connections\ConnectionInterface;
use RedisCachePro\Connections\PhpRedisClusterConnection;
use RedisCachePro\Connections\PhpRedisReplicatedConnection;

use RedisCachePro\Exceptions\PhpRedisMissingException;
use RedisCachePro\Exceptions\PhpRedisOutdatedException;

class PhpRedisConnector implements Connector
{
    /**
     * Ensure PhpRedis v3.1.1 or newer loaded.
     */
    public static function boot(): void
    {
        if (! extension_loaded('redis')) {
            throw new PhpRedisMissingException;
        }

        if (version_compare(phpversion('redis'), '3.1.1', '<')) {
            throw new PhpRedisOutdatedException;
        }
    }

    /**
     * Check whether the client supports the given feature.
     *
     * @return bool
     */
    public static function supports(string $feature): bool
    {
        switch ($feature) {
            case Configuration::SERIALIZER_PHP:
                return \defined('\Redis::SERIALIZER_PHP');
            case Configuration::SERIALIZER_IGBINARY:
                return \defined('\Redis::SERIALIZER_IGBINARY');
            case Configuration::COMPRESSION_LZF:
                return \defined('\Redis::COMPRESSION_LZF');
            case Configuration::COMPRESSION_LZ4:
                return \defined('\Redis::COMPRESSION_LZ4');
            case Configuration::COMPRESSION_ZSTD:
                return \defined('\Redis::COMPRESSION_ZSTD');
        }

        return false;
    }

    /**
     * Create a new PhpRedis connection.
     *
     * @param  \RedisCachePro\Configuration\Configuration  $config
     * @return \RedisCachePro\Connections\ConnectionInterface
     */
    public static function connect(Configuration $config): ConnectionInterface
    {
        if ($config->cluster) {
            return static::connectToCluster($config);
        }

        if ($config->servers) {
            return static::connectToReplicatedServers($config);
        }

        return static::connectToInstance($config);
    }

    /**
     * Create a new PhpRedis connection to an instance.
     *
     * @param  \RedisCachePro\Configuration\Configuration  $config
     * @return \RedisCachePro\Connections\PhpRedisConnection
     */
    public static function connectToInstance(Configuration $config): ConnectionInterface
    {
        $client = new Redis;
        $version = \phpversion('redis');

        $persistent = $config->persistent;
        $persistentId = \is_string($persistent) ? $persistent : '';

        $host = $config->host;

        if (\version_compare($version, '5.0.0', '>=') && $config->scheme) {
            $host = "{$config->scheme}://{$config->host}";
        }

        $host = \str_replace('unix://', '', $host);

        $parameters = [
            $host,
            $config->port ?? 0,
            $config->timeout,
            $persistentId,
            $config->retry_interval,
        ];

        if (\version_compare($version, '3.1.3', '>=')) {
            $parameters[] = $config->read_timeout;
        }

        $tlsContext = static::tlsOptions($config);

        if ($tlsContext && \version_compare($version, '5.3.0', '>=')) {
            $parameters[] = ['stream' => $tlsContext];
        }

        $method = $persistent ? 'pconnect' : 'connect';

        $client->{$method}(...$parameters);

        if ($config->username && $config->password) {
            $client->auth([$config->username, $config->password]);
        } elseif ($config->password) {
            $client->auth($config->password);
        }

        if ($config->database) {
            $client->select($config->database);
        }

        if ($config->read_timeout) {
            $client->setOption(Redis::OPT_READ_TIMEOUT, (string) $config->read_timeout);
        }

        return new PhpRedisConnection($client, $config);
    }

    /**
     * Create a new clustered PhpRedis connection.
     *
     * @param  \RedisCachePro\Configuration\Configuration  $config
     * @return \RedisCachePro\Connections\PhpRedisClusterConnection
     */
    public static function connectToCluster(Configuration $config): ConnectionInterface
    {
        if (\is_string($config->cluster)) {
            $client = new RedisCluster($config->cluster);
        } else {
            $parameters = [
                null,
                \array_values($config->cluster),
                $config->timeout,
                $config->read_timeout,
                $config->persistent,
            ];

            $version = \phpversion('redis');

            if (\version_compare($version, '4.3.0', '>=')) {
                $parameters[] = $config->password ?? '';
            }

            $tlsContext = static::tlsOptions($config);

            if ($tlsContext && \version_compare($version, '5.3.2', '>=')) {
                $parameters[] = $tlsContext;
            }

            $client = new RedisCluster(...$parameters);
        }

        if ($config->cluster_failover) {
            $client->setOption(RedisCluster::OPT_SLAVE_FAILOVER, $config->getClusterFailover());
        }

        return new PhpRedisClusterConnection($client, $config);
    }

    /**
     * Create a new replicated PhpRedis connection.
     *
     * @param  \RedisCachePro\Configuration\Configuration  $config
     * @return \RedisCachePro\Connections\PhpRedisReplicatedConnection
     */
    public static function connectToReplicatedServers(Configuration $config): ConnectionInterface
    {
        $replicas = [];

        foreach ($config->servers as $server) {
            $serverConfig = clone $config;
            $serverConfig->setUrl($server);

            if (Configuration::parseUrl($server)['role'] === 'master') {
                $master = static::connectToInstance($serverConfig);
            } else {
                $replicas[] = static::connectToInstance($serverConfig);
            }
        }

        return new PhpRedisReplicatedConnection($master, $replicas, $config);
    }

    /**
     * Returns the TLS context options for the transport.
     *
     * @param  \RedisCachePro\Configuration\Configuration  $config
     * @return array
     */
    protected static function tlsOptions(Configuration $config)
    {
        if (\defined('\WP_REDIS_PHPREDIS_OPTIONS')) {
            if (function_exists('_doing_it_wrong')) {
                $message = 'The `WP_REDIS_PHPREDIS_OPTIONS` constant is deprecated, use the `tls_options` configuration option instead. ';
                $message .= 'https://objectcache.pro/docs/configuration-options/#tls-options';

                \_doing_it_wrong(__METHOD__, $message, '1.12.1');
            }

            return \WP_REDIS_PHPREDIS_OPTIONS;
        }

        return $config->tls_options;
    }
}
