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

namespace RedisCachePro\ObjectCaches;

use RedisCachePro\Connections\RelayConnection;
use RedisCachePro\Configuration\Configuration;

class RelayObjectCache extends PhpRedisObjectCache
{
    use Concerns\SplitsAllOptionsIntoKeys;

    /**
     * The client name.
     *
     * @var string
     */
    const Client = 'Relay';

    /**
     * Whether Relay is using waiting for invalidation events.
     *
     * @var bool
     */
    protected $shouldInvalidate;

    /**
     * Create new Relay object cache instance.
     *
     * @param  \RedisCachePro\Connections\ConnectionInterface  $connection
     * @param  \RedisCachePro\Configuration\Configuration  $config
     */
    public function __construct(RelayConnection $connection, Configuration $config)
    {
        $this->config = $config;
        $this->connection = $connection;
        $this->log = $this->config->logger;
        $this->shouldInvalidate = $this->config->relay_listeners;

        if ($this->shouldInvalidate) {
            $this->connection->onInvalidated(
                [$this, 'invalidated'],
                $config->prefix ? "{$config->prefix}*" : null
            );

            $this->connection->onFlushed(
                [$this, 'flushed']
            );
        }
    }

    /**
     * Adds data to the cache, if the cache key doesn't already exist.
     *
     * @param  string  $key
     * @param  mixed  $data
     * @param  string  $group
     * @param  int  $expire
     * @return bool
     */
    public function add(string $key, $data, string $group = 'default', int $expire = 0): bool
    {
        $this->shouldInvalidate
            && $this->connection->dispatchEvents();

        return parent::add($key, $data, $group, $expire);
    }

    /**
     * Decrements numeric cache item's value.
     *
     * @param  string  $key
     * @param  int  $offset
     * @param  string  $group
     * @return false|int
     */
    public function decr(string $key, int $offset = 1, string $group = 'default')
    {
        $this->shouldInvalidate
            && $this->connection->dispatchEvents();

        return parent::decr($key, $offset, $group);
    }

    /**
     * Removes the cache contents matching key and group.
     *
     * @param  string  $key
     * @param  string  $group
     * @return bool
     */
    public function delete(string $key, string $group = 'default'): bool
    {
        $this->shouldInvalidate
            && $this->connection->dispatchEvents();

        return parent::delete($key, $group);
    }

    /**
     * Retrieves the cache contents from the cache by key and group.
     *
     * @param  string  $key
     * @param  string  $group
     * @param  bool  $force
     * @param  bool  &$found
     * @return bool|mixed
     */
    public function get(string $key, string $group = 'default', bool $force = false, &$found = null)
    {
        $this->shouldInvalidate
            && $this->connection->dispatchEvents();

        return parent::get($key, $group, $force, $found);
    }

    /**
     * Retrieves multiple values from the cache in one call.
     *
     * @param  array  $keys
     * @param  string  $group
     * @param  bool  $force
     * @return array
     */
    public function get_multiple(array $keys, string $group = 'default', bool $force = false)
    {
        $this->shouldInvalidate
            && $this->connection->dispatchEvents();

        return parent::get_multiple($keys, $group, $force);
    }

    /**
     * Whether the key exists in the cache.
     *
     * @param  string  $key
     * @param  string  $group
     * @return bool
     */
    public function has(string $key, string $group = 'default'): bool
    {
        $this->shouldInvalidate
            && $this->connection->dispatchEvents();

        return parent::has($key, $group);
    }

    /**
     * Increment numeric cache item's value.
     *
     * @param  string  $key
     * @param  int  $offset
     * @param  string  $group
     * @return false|int
     */
    public function incr(string $key, int $offset = 1, string $group = 'default')
    {
        $this->shouldInvalidate
            && $this->connection->dispatchEvents();

        return parent::incr($key, $offset, $group);
    }

    /**
     * Replaces the contents of the cache with new data.
     *
     * @param  string  $key
     * @param  mixed  $data
     * @param  string  $group
     * @param  int  $expire
     * @return bool
     */
    public function replace(string $key, $data, string $group = 'default', int $expire = 0): bool
    {
        $this->shouldInvalidate
            && $this->connection->dispatchEvents();

        return parent::replace($key, $data, $group, $expire);
    }

    /**
     * Saves the data to the cache.
     *
     * @param  string  $key
     * @param  mixed  $data
     * @param  string  $group
     * @param  int  $expire
     * @return bool
     */
    public function set(string $key, $data, string $group = 'default', int $expire = 0): bool
    {
        $this->shouldInvalidate
            && $this->connection->dispatchEvents();

        return parent::set($key, $data, $group, $expire);
    }

    /**
     * Callback for the `invalidated` event to keep the in-memory cache in sync.
     *
     * @param  \Relay\Event  $event
     */
    public function invalidated($event)
    {
        $bits = explode(':', $event->key);

        $this->deleteFromMemory(...array_reverse(array_splice($bits, -2)));
    }

    /**
     * Callback for the `flushed` event to keep the in-memory cache fresh.
     */
    public function flushed()
    {
        $this->flushMemory();
    }

    /**
     * Returns various information about the object cache.
     *
     * @return object
     */
    public function info()
    {
        $info = parent::info();
        $stats = $this->connection->memoize('stats');

        $info->meta = [
            'Relay Memory' => sprintf(
                '%s of %s',
                size_format($stats['memory']['active'], 2),
                size_format($stats['memory']['total'], 2)
            ),
            'Relay Eviction' => ini_get('relay.eviction_policy'),
        ] + $info->meta;

        return $info;
    }
}
