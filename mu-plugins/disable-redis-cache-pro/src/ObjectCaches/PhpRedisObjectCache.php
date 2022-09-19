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

use Exception;
use ReflectionClass;

use RedisCachePro\Configuration\Configuration;
use RedisCachePro\Connections\PhpRedisConnection;

class PhpRedisObjectCache extends ObjectCache implements ObjectCacheInterface
{
    use Concerns\PrefetchesKeys,
        Concerns\FlushesNetworks,
        Concerns\TakesMeasurements,
        Concerns\SplitsAllOptionsIntoHash;

    /**
     * The client name.
     *
     * @var string
     */
    const Client = 'PhpRedis';

    /**
     * The amount of times Redis had the object already cached.
     *
     * @var int
     */
    protected $storeHits = 0;

    /**
     * Amount of times the Redis did not have the object.
     *
     * @var int
     */
    protected $storeMisses = 0;

    /**
     * Amount of times the cache read from Redis.
     *
     * @var int
     */
    protected $storeReads = 0;

    /**
     * Amount of times the cache wrote to Redis.
     *
     * @var int
     */
    protected $storeWrites = 0;

    /**
     * Create new PhpRedis object cache instance.
     *
     * @param  \RedisCachePro\Connections\ConnectionInterface  $connection
     * @param  \RedisCachePro\Configuration\Configuration  $config
     */
    public function __construct(PhpRedisConnection $connection, Configuration $config)
    {
        $this->config = $config;
        $this->connection = $connection;
        $this->log = $this->config->logger;
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
        if (function_exists('wp_suspend_cache_addition') && \wp_suspend_cache_addition()) {
            return false;
        }

        $id = $this->id($key, $group);

        if ($this->hasInMemory($id, $group)) {
            return false;
        }

        if ($this->isNonPersistentGroup($group)) {
            $this->storeInMemory($id, $data, $group);

            return true;
        }

        try {
            $result = (bool) $this->write($id, $data, $expire, 'nx');

            if ($result) {
                $this->storeInMemory($id, $data, $group);
            }

            return $result;
        } catch (Exception $exception) {
            $this->error($exception);

            return false;
        }
    }

    /**
     * Boots the cache.
     *
     * @return bool
     */
    public function boot(): bool
    {
        $this->prefetch();

        return true;
    }

    /**
     * Closes the cache.
     *
     * @return bool
     */
    public function close(): bool
    {
        $this->storePrefetches();
        $this->storeMeasurements();

        return true;
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
        $id = $this->id($key, $group);

        if ($this->isNonPersistentGroup($group)) {
            if (! $this->hasInMemory($id, $group)) {
                return false;
            }

            $value = $this->getFromMemory($id, $group);
            $value = $this->decrement($value, $offset);

            $this->storeInMemory($id, $value, $group);

            return $value;
        }

        try {
            $this->storeReads++;
            $value = $this->connection->get($id);

            if ($value === false) {
                return false;
            }

            $this->storeWrites++;
            $value = $this->decrement($value, $offset);
            $result = $this->connection->set($id, $value);

            if ($result) {
                $this->storeInMemory($id, $value, $group);
            }

            return $value;
        } catch (Exception $exception) {
            $this->error($exception);

            return false;
        }
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
        $id = $this->id($key, $group);

        if ($this->isNonPersistentGroup($group)) {
            if (! $this->hasInMemory($id, $group)) {
                return false;
            }

            unset($this->cache[$group][$id]);

            return true;
        }

        unset($this->cache[$group][$id]);
        unset($this->prefetch[$group][$key]);

        try {
            if ($this->isAllOptionsId($id)) {
                return $this->deleteAllOptions($id);
            }

            $this->storeWrites++;

            return (bool) $this->connection->del($id);
        } catch (Exception $exception) {
            $this->error($exception);
        }

        return false;
    }

    /**
     * Removes all items from Redis, the runtime cache and gathered prefetches.
     *
     * @return bool
     */
    public function flush(): bool
    {
        $this->cache = [];
        $this->prefetch = [];

        if ($this->isMultisite && $this->handleBlogFlush()) {
            return $this->flushBlog(\get_current_blog_id());
        }

        try {
            return $this->connection->flushdb();
        } catch (Exception $exception) {
            $this->error($exception);
        }

        return false;
    }

    /**
     * Flushes the runtime cache and gathered prefetches.
     *
     * @return bool
     */
    public function flushMemory(): bool
    {
        $this->prefetch = [];

        return parent::flushMemory();
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
        $id = $this->id($key, $group);

        $cachedInMemory = $this->hasInMemory($id, $group);

        if ($this->isNonPersistentGroup($group)) {
            if (! $cachedInMemory) {
                $found = false;
                $this->misses += 1;

                return false;
            }

            $found = true;
            $this->hits += 1;

            return $this->getFromMemory($id, $group);
        }

        if ($this->prefetched) {
            $this->prefetch[$group][$key] = true;
        }

        if ($cachedInMemory && ! $force) {
            $found = true;
            $this->hits += 1;

            return $this->getFromMemory($id, $group);
        }

        $found = false;

        try {
            if ($this->isAllOptionsId($id)) {
                $data = $this->getAllOptions($id);
            } else {
                $this->storeReads++;
                $data = $this->connection->get($id);
            }
        } catch (Exception $exception) {
            $this->error($exception);

            return false;
        }

        if ($data === false) {
            $this->misses += 1;
            $this->storeMisses += 1;

            return false;
        }

        $found = true;
        $this->hits += 1;
        $this->storeHits += 1;

        $this->storeInMemory($id, $data, $group);

        return $data;
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
        $values = [];

        if ($this->isNonPersistentGroup($group)) {
            foreach ($keys as $key) {
                $id = $this->id((string) $key, $group);

                if ($this->hasInMemory($id, $group)) {
                    $this->hits += 1;
                    $values[$key] = $this->getFromMemory($id, $group);
                } else {
                    $this->misses += 1;
                    $values[$key] = false;
                }
            }

            return $values;
        }

        if ($this->prefetched) {
            foreach ($keys as $key) {
                $this->prefetch[$group][$key] = true;
            }
        }

        if (! $force) {
            foreach ($keys as $key) {
                $id = $this->id((string) $key, $group);

                if ($this->hasInMemory($id, $group)) {
                    $this->hits += 1;
                    $values[$key] = $this->getFromMemory($id, $group);
                }
            }
        }

        $remainingKeys = array_values(array_diff($keys, array_keys($values)));

        if (empty($remainingKeys)) {
            return $values;
        }

        $ids = array_map(function ($key) use ($group) {
            return $this->id((string) $key, $group);
        }, $remainingKeys);

        try {
            $this->storeReads++;
            $data = $this->connection->mget($ids);

            foreach ($remainingKeys as $index => $key) {
                $values[$key] = $data[$index];

                if ($data[$index] === false) {
                    $this->misses += 1;
                    $this->storeMisses += 1;

                    continue;
                }

                $this->hits += 1;
                $this->storeHits += 1;

                if ($this->config->prefetch && ! $this->prefetched) {
                    $this->prefetches++;
                }

                $this->storeInMemory($ids[$index], $data[$index], $group);
            }
        } catch (Exception $exception) {
            $this->error($exception);

            foreach ($remainingKeys as $key) {
                $values[$key] = false;
            }
        }

        $order = array_flip(array_values($keys));

        uksort($values, function ($a, $b) use ($order) {
            return $order[$a] - $order[$b];
        });

        return $values;
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
        $id = $this->id($key, $group);

        if ($this->hasInMemory($id, $group)) {
            return true;
        }

        if ($this->isNonPersistentGroup($group)) {
            return false;
        }

        try {
            $this->storeReads++;

            return (bool) $this->connection->exists($id);
        } catch (Exception $exception) {
            $this->error($exception);
        }

        return false;
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
        $id = $this->id($key, $group);

        if ($this->isNonPersistentGroup($group)) {
            if (! $this->hasInMemory($id, $group)) {
                return false;
            }

            $value = $this->getFromMemory($id, $group);
            $value = $this->increment($value, $offset);

            $this->storeInMemory($id, $value, $group);

            return $value;
        }

        try {
            $this->storeReads++;
            $value = $this->connection->get($id);

            if ($value === false) {
                return false;
            }

            $this->storeWrites++;
            $value = $this->increment($value, $offset);
            $result = $this->connection->set($id, $value);

            if ($result) {
                $this->storeInMemory($id, $value, $group);
            }

            return $value;
        } catch (Exception $exception) {
            $this->error($exception);

            return false;
        }
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
        $id = $this->id($key, $group);

        if ($this->isNonPersistentGroup($group)) {
            if (! $this->hasInMemory($id, $group)) {
                return false;
            }

            $this->storeInMemory($id, $data, $group);

            return true;
        }

        try {
            $result = (bool) $this->write($id, $data, $expire, 'xx');

            if ($result) {
                $this->storeInMemory($id, $data, $group);
            }

            return $result;
        } catch (Exception $exception) {
            $this->error($exception);

            return false;
        }
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
        $id = $this->id($key, $group);

        if ($this->isNonPersistentGroup($group)) {
            $this->storeInMemory($id, $data, $group);

            return true;
        }

        try {
            $result = (bool) $this->write($id, $data, $expire);

            if ($result) {
                $this->storeInMemory($id, $data, $group);
            }

            return $result;
        } catch (Exception $exception) {
            $this->error($exception);

            return false;
        }
    }

    /**
     * Switches the internal blog ID.
     *
     * @param  int $blog_id
     * @return bool
     */
    public function switch_to_blog(int $blog_id): bool
    {
        if ($this->isMultisite) {
            $this->setBlogId($blog_id);

            return true;
        }

        return false;
    }

    /**
     * Writes the given data to Redis and enforces the `maxttl` configuration option.
     *
     * @param  string  $id
     * @param  mixed  $data
     * @param  int  $expire
     * @param  string  $option
     * @return bool
     */
    protected function write(string $id, $data, int $expire = 0, $option = null): bool
    {
        if ($expire < 0) {
            $expire = 0;
        }

        $maxttl = $this->config->maxttl;

        if ($maxttl && ($expire === 0 || $expire > $maxttl)) {
            $expire = $maxttl;
        }

        if ($this->isAllOptionsId($id)) {
            return $this->syncAllOptions($id, $data, $expire);
        }

        $this->storeWrites++;

        if ($expire && $option) {
            return $this->connection->set($id, $data, [$option, 'ex' => $expire]);
        }

        if ($expire) {
            return $this->connection->setex($id, $expire, $data);
        }

        if ($option) {
            return $this->connection->set($id, $data, [$option]);
        }

        return $this->connection->set($id, $data);
    }

    /**
     * Returns various information about the object cache.
     *
     * @return object
     */
    public function info()
    {
        $server = $this->connection->memoize('info');

        $info = parent::info();
        $info->status = (bool) $this->connection->memoize('ping');
        $info->prefetches = $this->prefetches;
        $info->storeReads = $this->storeReads;
        $info->storeWrites = $this->storeWrites;
        $info->storeHits = $this->storeHits;
        $info->storeMisses = $this->storeMisses;

        $info->meta = array_filter([
            'Redis Version' => $server['redis_version'],
            'Redis Memory' => size_format($server['used_memory'], 2),
            'Redis Eviction' => $server['maxmemory_policy'] ?? null,
            'Cache' => (new ReflectionClass($this))->getShortName(),
            'Connector' => (new ReflectionClass($this->config->connector))->getShortName(),
            'Connection' => (new ReflectionClass($this->connection))->getShortName(),
            'Logger' => (new ReflectionClass($this->log))->getShortName(),
        ]);

        return $info;
    }

    /**
     * Returns metrics about the object cache.
     *
     * @return object
     */
    public function metrics()
    {
        $metrics = parent::metrics();
        $metrics->prefetches = $this->prefetches;
        $metrics->storeReads = $this->storeReads;
        $metrics->storeWrites = $this->storeWrites;
        $metrics->storeHits = $this->storeHits;
        $metrics->storeMisses = $this->storeMisses;

        return $metrics;
    }
}
