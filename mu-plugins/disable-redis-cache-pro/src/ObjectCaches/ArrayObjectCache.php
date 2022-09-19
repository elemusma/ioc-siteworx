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

use RedisCachePro\Configuration\Configuration;

class ArrayObjectCache extends ObjectCache implements ObjectCacheInterface
{
    /**
     * The client name.
     *
     * @var string
     */
    const Client = 'Array';

    /**
     * Create new array object cache instance.
     *
     * @param  \RedisCachePro\Configuration\Configuration  $config
     */
    public function __construct(Configuration $config)
    {
        $this->config = $config;
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

        if ($this->has($key, $group)) {
            return false;
        }

        return $this->set($key, $data, $group, $expire);
    }

    /**
     * Boots the cache.
     *
     * @return bool
     */
    public function boot(): bool
    {
        return true;
    }

    /**
     * Closes the cache.
     *
     * @return bool
     */
    public function close(): bool
    {
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
        if (! $this->has($key, $group)) {
            return false;
        }

        $id = $this->id($key, $group);

        $value = $this->cache[$group][$id];
        $value = $this->decrement($value, $offset);

        $this->cache[$group][$id] = $value;

        return $value;
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
        if (! $this->has($key, $group)) {
            return false;
        }

        $id = $this->id($key, $group);

        unset($this->cache[$group][$id]);

        return true;
    }

    /**
     * Removes all cache items.
     *
     * @return bool
     */
    public function flush(): bool
    {
        $this->cache = [];

        return true;
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
        if (! $this->has($key, $group)) {
            $found = false;
            $this->misses += 1;

            return false;
        }

        $found = true;
        $this->hits += 1;

        $id = $this->id($key, $group);

        if (\is_object($this->cache[$group][$id])) {
            return clone $this->cache[$group][$id];
        }

        return $this->cache[$group][$id];
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

        foreach ($keys as $key) {
            $values[$key] = $this->get((string) $key, $group, $force);
        }

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

        return isset($this->cache[$group][$id]);
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
        if (! $this->has($key, $group)) {
            return false;
        }

        $id = $this->id($key, $group);

        $value = $this->cache[$group][$id];
        $value = $this->increment($value, $offset);

        $this->cache[$group][$id] = $value;

        return $value;
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

        if (! $this->has($key, $group)) {
            return false;
        }

        return $this->set($key, $data, $group, $expire);
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
        if (\is_object($data)) {
            $data = clone $data;
        }

        $id = $this->id($key, $group);

        $this->cache[$group][$id] = $data;

        return true;
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
}
