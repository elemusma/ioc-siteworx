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

namespace RedisCachePro\Connections;

use Relay\Relay;

use RedisCachePro\Configuration\Configuration;

class RelayConnection extends Connection implements ConnectionInterface
{
    /**
     * The Redis client/cluster.
     *
     * @var \Relay\Relay
     */
    protected $client;

    /**
     * Create a new Relay instance connection.
     *
     * @param  \Relay\Relay  $client
     * @param  \RedisCachePro\Configuration\Configuration  $config
     */
    public function __construct(Relay $client, Configuration $config)
    {
        $this->client = $client;
        $this->config = $config;

        $this->log = $this->config->logger;

        $this->setSerializer();
        $this->setCompression();
    }

    /**
     * Set the connection's serializer.
     */
    protected function setSerializer()
    {
        if ($this->config->serializer === Configuration::SERIALIZER_PHP) {
            $this->client->setOption(Relay::OPT_SERIALIZER, (string) Relay::SERIALIZER_PHP);
        }

        if ($this->config->serializer === Configuration::SERIALIZER_IGBINARY) {
            $this->client->setOption(Relay::OPT_SERIALIZER, (string) Relay::SERIALIZER_IGBINARY);
        }
    }

    /**
     * Set the connection's compression algorithm.
     */
    protected function setCompression()
    {
        if ($this->config->compression === Configuration::COMPRESSION_NONE) {
            $this->client->setOption(Relay::OPT_COMPRESSION, (string) Relay::COMPRESSION_NONE);
        }

        if ($this->config->compression === Configuration::COMPRESSION_LZF) {
            $this->client->setOption(Relay::OPT_COMPRESSION, (string) Relay::COMPRESSION_LZF);
        }

        if ($this->config->compression === Configuration::COMPRESSION_ZSTD) {
            $this->client->setOption(Relay::OPT_COMPRESSION, (string) Relay::COMPRESSION_ZSTD);
        }

        if ($this->config->compression === Configuration::COMPRESSION_LZ4) {
            $this->client->setOption(Relay::OPT_COMPRESSION, (string) Relay::COMPRESSION_LZ4);
        }
    }

    /**
     * Execute the callback without data mutations on the connection,
     * such as serialization and compression algorithms.
     *
     * @param  callable  $callback
     * @return mixed
     */
    public function withoutMutations(callable $callback)
    {
        $this->client->setOption(Relay::OPT_SERIALIZER, (string) Relay::SERIALIZER_NONE);
        $this->client->setOption(Relay::OPT_COMPRESSION, (string) Relay::COMPRESSION_NONE);

        $result = $callback($this);

        $this->setSerializer();
        $this->setCompression();

        return $result;
    }

    /**
     * Dispatch invalidation events.
     *
     * Bypasses the `command()` method to avoid log spam.
     *
     * @return int|false
     */
    public function dispatchEvents()
    {
        return $this->client->dispatchEvents();
    }

    /**
     * Registers an event listener.
     *
     * Bypasses the `command()` method to avoid log spam.
     *
     * @param  callable  $callback
     * @return bool
     */
    public function listen(callable $callback)
    {
        return $this->client->listen($callback);
    }

    /**
     * Registers an event listener for flushes.
     *
     * Bypasses the `command()` method to avoid log spam.
     *
     * @param  callable  $callback
     * @return bool
     */
    public function onFlushed(callable $callback)
    {
        return $this->client->onFlushed($callback);
    }

    /**
     * Registers an event listener for invalidations.
     *
     * Bypasses the `command()` method to avoid log spam.
     *
     * @param  callable  $callback
     * @param  string  $pattern
     * @return bool
     */
    public function onInvalidated(callable $callback, string $pattern = null)
    {
        return $this->client->onInvalidated($callback, $pattern);
    }

    /**
     * Returns statistics about Relay.
     *
     * Bypasses the `command()` method to avoid log spam.
     *
     * @return array
     */
    public function stats()
    {
        return $this->client->stats();
    }

    /**
     * Returns information about the Relay license.
     *
     * Bypasses the `command()` method to avoid log spam.
     *
     * @return array
     */
    public function license()
    {
        return $this->client->license();
    }

    /**
     * Returns the connections socket identifier.
     *
     * Bypasses the `command()` method to avoid log spam.
     *
     * @return int|false
     */
    public function socketId()
    {
        return $this->client->socketId();
    }

    /**
     * Returns the connections socket key.
     *
     * Bypasses the `command()` method to avoid log spam.
     *
     * @return string|false
     */
    public function socketKey()
    {
        return $this->client->socketKey();
    }

    /**
     * Flush the selected Redis database.
     *
     * Relay will always use asynchronous flushing, regardless of
     * the `async_flush` configuration option or `$async` parameter.
     *
     * @param  bool|null  $async
     * @return true
     */
    public function flushdb($async = null)
    {
        $this->command('flushdb', [true]);

        return true;
    }

    /**
     * Returns the memoized result from the given command.
     *
     * @param  string  $command
     * @return mixed
     */
    public function memoize($command)
    {
        if ($command === 'ping') {
            return $this->client->idleTime() > 1000
                ? $this->client->ping()
                : true;
        }

        return parent::memoize($command);
    }
}
