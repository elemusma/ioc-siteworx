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

interface ObjectCacheInterface
{
    /**
     * Adds data to the cache, if the cache key doesn't already exist.
     *
     * @param  string  $key
     * @param  mixed  $data
     * @param  string  $group
     * @param  int  $expire
     * @return bool
     */
    public function add(string $key, $data, string $group = 'default', int $expire = 0): bool;

    /**
     * Set given groups as global.
     *
     * @param  array  $groups
     */
    public function add_global_groups(array $groups);

    /**
     * Set given groups as non-persistent.
     *
     * @param  array  $groups
     */
    public function add_non_persistent_groups(array $groups);

    /**
     * Set given groups as non-prefetchable.
     *
     * @param  array  $groups
     */
    public function add_non_prefetchable_groups(array $groups);

    /**
     * Boots the cache.
     *
     * @return bool
     */
    public function boot(): bool;

    /**
     * Closes the cache.
     *
     * @return bool
     */
    public function close(): bool;

    /**
     * Returns the configuration instance.
     *
     * @return \RedisCachePro\Configuration\Configuration
     */
    public function config(): Configuration;

    /**
     * Returns the connection instance.
     *
     * @return \RedisCachePro\Connections\ConnectionInterface
     */
    public function connection();

    /**
     * Decrements numeric cache item's value.
     *
     * @param  string  $key
     * @param  int  $offset
     * @param  string  $group
     * @return false|int
     */
    public function decr(string $key, int $offset = 1, string $group = 'default');

    /**
     * Removes the cache contents matching key and group.
     *
     * @param  string  $key
     * @param  string  $group
     * @return bool
     */
    public function delete(string $key, string $group = 'default'): bool;

    /**
     * Removes all cache items.
     *
     * @return bool
     */
    public function flush(): bool;

    /**
     * Retrieves the cache contents from the cache by key and group.
     *
     * @param  string  $key
     * @param  string  $group
     * @param  bool  $force
     * @param  bool  &$found
     * @return bool|mixed
     */
    public function get(string $key, string $group = 'default', bool $force = false, &$found = null);

    /**
     * Retrieves multiple values from the cache in one call.
     *
     * @param  array  $keys
     * @param  string  $group
     * @param  bool  $force
     * @return array
     */
    public function get_multiple(array $keys, string $group = 'default', bool $force = false);

    /**
     * Whether the key exists in the cache.
     *
     * @param  string  $key
     * @param  string  $group
     * @return bool
     */
    public function has(string $key, string $group = 'default'): bool;

    /**
     * Increment numeric cache item's value.
     *
     * @param  string  $key
     * @param  int  $offset
     * @param  string  $group
     * @return false|int
     */
    public function incr(string $key, int $offset = 1, string $group = 'default');

    /**
     * Replaces the contents of the cache with new data.
     *
     * @param  string  $key
     * @param  mixed  $data
     * @param  string  $group
     * @param  int  $expire
     * @return bool
     */
    public function replace(string $key, $data, string $group = 'default', int $expire = 0): bool;

    /**
     * Saves the data to the cache.
     *
     * @param  string  $key
     * @param  mixed  $data
     * @param  string  $group
     * @param  int  $expire
     * @return bool
     */
    public function set(string $key, $data, string $group = 'default', int $expire = 0): bool;

    /**
     * Switches the internal blog ID.
     *
     * @param  int $blog_id
     * @return bool
     */
    public function switch_to_blog(int $blog_id): bool;
}
