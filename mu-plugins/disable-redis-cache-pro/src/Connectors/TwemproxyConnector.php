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

use RuntimeException;

use RedisCachePro\Configuration\Configuration;

use RedisCachePro\Connections\TwemproxyConnection;
use RedisCachePro\Connections\ConnectionInterface;

class TwemproxyConnector extends PhpRedisConnector implements Connector
{
    /**
     * Create a new PhpRedis connection to an instance.
     *
     * @param  \RedisCachePro\Configuration\Configuration  $config
     * @return \RedisCachePro\Connections\PhpRedisConnection
     *
     * @throws \RuntimeException
     */
    public static function connectToInstance(Configuration $config): ConnectionInterface
    {
        if ($config->database) {
            throw new RuntimeException('Twemproxy does not database indices other than `0`.');
        }

        return new TwemproxyConnection(
            parent::connectToInstance($config)
        );
    }

    /**
     * Create a new clustered PhpRedis connection.
     *
     * @param  \RedisCachePro\Configuration\Configuration  $config
     *
     * @throws \RuntimeException
     */
    public static function connectToCluster(Configuration $config): ConnectionInterface
    {
        throw new RuntimeException('Twemproxy does not support clusters.');
    }

    /**
     * Create a new replicated PhpRedis connection.
     *
     * @param  \RedisCachePro\Configuration\Configuration  $config
     *
     * @throws \RuntimeException
     */
    public static function connectToReplicatedServers(Configuration $config): ConnectionInterface
    {
        throw new RuntimeException('Relay does not support replicated connections.');
    }
}
