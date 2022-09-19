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

use RedisCluster;

use RedisCachePro\Configuration\Configuration;

class PhpRedisClusterConnection extends PhpRedisConnection implements ConnectionInterface
{
    /**
     * Create a new PhpRedis cluster connection.
     *
     * @param  \RedisCluster  $client
     * @param  \RedisCachePro\Configuration\Configuration  $config
     */
    public function __construct(RedisCluster $client, Configuration $config)
    {
        $this->client = $client;
        $this->config = $config;

        $this->log = $this->config->logger;

        $this->setBackoff();
        $this->setSerializer();
        $this->setCompression();
    }

    /**
     * Pings first master node.
     *
     * To ping a specific node, pass name of key as a string, or a hostname and port as array.
     *
     * @param  string|array  $parameter
     * @return bool
     */
    public function ping($parameter = null)
    {
        if (\is_null($parameter)) {
            $masters = $this->client->_masters();
            $parameter = \reset($masters);
        }

        return $this->command('ping', [$parameter]);
    }

    /**
     * Fetches information from the first master node.
     *
     * To fetch information from a specific node, pass name of key as a string, or a hostname and port as array.
     *
     * @param  string|array  $parameter
     * @return bool
     */
    public function info($parameter = null)
    {
        if (\is_null($parameter)) {
            $masters = $this->client->_masters();
            $parameter = \reset($masters);
        }

        return $this->command('info', [$parameter]);
    }

    /**
     * Flush all nodes on the Redis cluster.
     *
     * @param  bool|null  $async
     * @return true
     */
    public function flushdb($async = null)
    {
        $useAsync = $async ?? $this->config->async_flush;

        foreach ($this->client->_masters() as $master) {
            $useAsync
                ? $this->command('rawCommand', [$master, 'flushdb', 'async'])
                : $this->command('flushdb', [$master]);
        }

        return true;
    }
}
