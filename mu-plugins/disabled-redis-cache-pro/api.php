<?php
/*
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
defined('ABSPATH') || exit;

require_once __DIR__ . '/bootstrap.php';

/**
 * Set up object cache.
 *
 * @since 2.0.0
 *
 * @global array $wp_object_cache_errors
 * @global \RedisCachePro\ObjectCaches\ObjectCacheInterface $wp_object_cache
 */
function wp_cache_init()
{
    global $wp_object_cache, $wp_object_cache_errors;

    $wp_object_cache_errors = [];

    try {
        if (! defined('WP_REDIS_CONFIG')) {
            throw new \RedisCachePro\Exceptions\ConfigurationMissingException;
        }

        $config = WP_REDIS_CONFIG;

        // set `debug` config value to `WP_DEBUG`, if not present already
        if (! isset($config['debug']) && defined('WP_DEBUG')) {
            $config['debug'] = (bool) WP_DEBUG;
        }

        // set `save_commands` config value to `SAVEQUERIES`, if not present already
        if (! isset($config['save_commands']) && defined('SAVEQUERIES')) {
            $config['save_commands'] = (bool) SAVEQUERIES;
        }

        $config = \RedisCachePro\Configuration\Configuration::from($config)->validate();

        $connection = $config->connector::connect($config);

        $objectCache = new $config->cache($connection, $config);

        // register additional global groups
        if ($config->global_groups) {
            $objectCache->add_global_groups($config->global_groups);
        }

        // register additional non-persistent groups
        if ($config->non_persistent_groups) {
            $objectCache->add_non_persistent_groups($config->non_persistent_groups);
        }

        // register non-prefetchable groups
        if ($config->non_prefetchable_groups) {
            $objectCache->add_non_prefetchable_groups($config->non_prefetchable_groups);
        }

        $objectCache->add_global_groups([
            'analytics',
        ]);

        $objectCache->add_non_prefetchable_groups([
            'analytics',
            'userlogins',
            'wc_session_id',
        ]);

        // set up multisite environments
        if (is_multisite()) {
            $objectCache->setMultisite(true);
        }

        $connection->memoize('ping');

        $wp_object_cache = $objectCache;
    } catch (Throwable $exception) {
        $error = sprintf('Failed to initialize object cache: %s', $exception->getMessage());

        $wp_object_cache_errors[] = $error;

        error_log("objectcache.critical: {$error}");

        if (! isset($config) || ! ($config instanceof \RedisCachePro\Configuration\Configuration)) {
            $config = (new \RedisCachePro\Configuration\Configuration)->init();
        }

        if ($config->debug) {
            error_log('objectcache.info: `debug` option is enabled. Throwing exception.');

            throw $exception;
        }

        error_log('objectcache.info: Failing over to in-memory object cache.');

        $wp_object_cache = new \RedisCachePro\ObjectCaches\ArrayObjectCache($config);
    }

    if (\is_multisite()) {
        \add_action('ms_loaded', [$wp_object_cache, 'boot'], 0);
    } else {
        $wp_object_cache->boot();
    }

    \register_shutdown_function([$wp_object_cache, 'close']);
}

/**
 * Adds data to the cache, if the cache key doesn't already exist.
 *
 * @since 2.0.0
 *
 * @param int|string $key    The cache key to use for retrieval later.
 * @param mixed      $data   The data to add to the cache.
 * @param string     $group  Optional. The group to add the cache to. Enables the same key
 *                           to be used across groups. Default empty.
 * @param int        $expire Optional. When the cache data should expire, in seconds.
 *                           Default 0 (no expiration).
 * @return bool False if cache key and group already exist, true on success.
 */
function wp_cache_add($key, $data, $group = '', $expire = 0)
{
    global $wp_object_cache;

    return $wp_object_cache->add((string) $key, $data, trim((string) $group) ?: 'default', (int) $expire);
}

/**
 * Closes the cache.
 *
 * To increase Query Monitor compatibility this method does nothing.
 * Instead the `wp_cache_init` method registers a shutdown
 * function that calls `$wp_object_cache->close()`.
 *
 * @since 2.0.0
 * @see wp_cache_init()
 *
 * @return true Always returns true.
 */
function wp_cache_close()
{
    return true;
}

/**
 * Decrements numeric cache item's value.
 *
 * @since 3.3.0
 *
 * @param int|string $key    The cache key to decrement.
 * @param int        $offset Optional. The amount by which to decrement the item's value. Default 1.
 * @param string     $group  Optional. The group the key is in. Default empty.
 * @return false|int False on failure, the item's new value on success.
 */
function wp_cache_decr($key, $offset = 1, $group = '')
{
    global $wp_object_cache;

    return $wp_object_cache->decr((string) $key, (int) $offset, \trim((string) $group) ?: 'default');
}

/**
 * Removes the cache contents matching key and group.
 *
 * @since 2.0.0
 *
 * @param int|string $key   What the contents in the cache are called.
 * @param string     $group Optional. Where the cache contents are grouped. Default empty.
 * @return bool True on successful removal, false on failure.
 */
function wp_cache_delete($key, $group = '')
{
    global $wp_object_cache;

    return $wp_object_cache->delete((string) $key, \trim((string) $group) ?: 'default');
}

/**
 * Removes all cache items.
 *
 * @since 2.0.0
 *
 * @return bool False on failure, true on success
 */
function wp_cache_flush()
{
    global $wp_object_cache;

    if (\function_exists('apply_filters')) {
        $backtrace = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 5);

        /**
         * Whether to flush the object cache.
         *
         * Returning a falsy value from the filter will short-circuit the flush.
         *
         * @param  bool  $should_flush  Whether to flush the object cache.
         * @param  array  $backtrace  The PHP backtrace with 5 stack frames.
         */
        $should_flush = \apply_filters('pre_objectcache_flush', true, $backtrace);

        if (! $should_flush) {
            return false;
        }
    }

    return $wp_object_cache->flush();
}

/**
 * Retrieves the cache contents from the cache by key and group.
 *
 * @since 2.0.0
 *
 * @param int|string  $key    The key under which the cache contents are stored.
 * @param string      $group  Optional. Where the cache contents are grouped. Default empty.
 * @param bool        $force  Optional. Whether to force an update of the local cache from the persistent
 *                            cache. Default false.
 * @param bool        &$found Optional. Whether the key was found in the cache (passed by reference).
 *                            Disambiguates a return of false, a storable value. Default null.
 * @return bool|mixed False on failure to retrieve contents or the cache
 *                    contents on success
 */
function wp_cache_get($key, $group = '', $force = false, &$found = null)
{
    global $wp_object_cache;

    return $wp_object_cache->get((string) $key, \trim((string) $group) ?: 'default', (bool) $force, $found);
}

/**
 * Retrieves multiple values from the cache in one call.
 *
 * @since 5.5.0
 *
 * @param array  $keys  Array of keys under which the cache contents are stored.
 * @param string $group Optional. Where the cache contents are grouped. Default empty.
 * @param bool   $force Optional. Whether to force an update of the local cache
 *                      from the persistent cache. Default false.
 * @return array Array of values organized into groups.
 */
function wp_cache_get_multiple($keys, $group = '', $force = false)
{
    global $wp_object_cache;

    return $wp_object_cache->get_multiple((array) $keys, \trim((string) $group) ?: 'default', (bool) $force);
}

/**
 * Increment numeric cache item's value.
 *
 * @since 3.3.0
 *
 * @param int|string $key    The key for the cache contents that should be incremented.
 * @param int        $offset Optional. The amount by which to increment the item's value. Default 1.
 * @param string     $group  Optional. The group the key is in. Default empty.
 * @return false|int False on failure, the item's new value on success.
 */
function wp_cache_incr($key, $offset = 1, $group = '')
{
    global $wp_object_cache;

    return $wp_object_cache->incr((string) $key, (int) $offset, \trim((string) $group) ?: 'default');
}

/**
 * Replaces the contents of the cache with new data.
 *
 * @since 2.0.0
 *
 * @param int|string $key    The key for the cache data that should be replaced.
 * @param mixed      $data   The new data to store in the cache.
 * @param string     $group  Optional. The group for the cache data that should be replaced.
 *                           Default empty.
 * @param int        $expire Optional. When to expire the cache contents, in seconds.
 *                           Default 0 (no expiration).
 * @return bool False if original value does not exist, true if contents were replaced
 */
function wp_cache_replace($key, $data, $group = '', $expire = 0)
{
    global $wp_object_cache;

    return $wp_object_cache->replace((string) $key, $data, \trim((string) $group) ?: 'default', (int) $expire);
}

/**
 * Saves the data to the cache.
 *
 * Differs from wp_cache_add() and wp_cache_replace() in that it will always write data.
 *
 * @since 2.0.0
 *
 * @param int|string $key    The cache key to use for retrieval later.
 * @param mixed      $data   The contents to store in the cache.
 * @param string     $group  Optional. Where to group the cache contents. Enables the same key
 *                           to be used across groups. Default empty.
 * @param int        $expire Optional. When to expire the cache contents, in seconds.
 *                           Default 0 (no expiration).
 * @return bool False on failure, true on success
 */
function wp_cache_set($key, $data, $group = '', $expire = 0)
{
    global $wp_object_cache;

    return $wp_object_cache->set((string) $key, $data, \trim((string) $group) ?: 'default', (int) $expire);
}

/**
 * Switches the internal blog ID.
 *
 * This changes the blog id used to create keys in blog specific groups.
 *
 * @since 3.5.0
 *
 * @param int $blog_id Site ID.
 */
function wp_cache_switch_to_blog($blog_id)
{
    global $wp_object_cache;

    $wp_object_cache->switch_to_blog((int) $blog_id);
}

/**
 * Adds a group or set of groups to the list of global groups.
 *
 * @since 2.6.0
 *
 * @param string|array $groups A group or an array of groups to add.
 */
function wp_cache_add_global_groups($groups)
{
    global $wp_object_cache;

    $wp_object_cache->add_global_groups((array) $groups);
}

/**
 * Adds a group or set of groups to the list of non-persistent groups.
 *
 * @since 2.6.0
 *
 * @param string|array $groups A group or an array of groups to add.
 */
function wp_cache_add_non_persistent_groups($groups)
{
    global $wp_object_cache;

    $wp_object_cache->add_non_persistent_groups((array) $groups);
}

/**
 * Reset internal cache keys and structures.
 *
 * If the cache back end uses global blog or site IDs as part of its cache keys,
 * this function instructs the back end to reset those keys and perform any cleanup
 * since blog or site IDs have changed since cache init.
 *
 * This function is deprecated. Use wp_cache_switch_to_blog() instead of this
 * function when preparing the cache for a blog switch. For clearing the cache
 * during unit tests, consider using wp_cache_init(). wp_cache_init() is not
 * recommended outside of unit tests as the performance penalty for using it is
 * high.
 *
 * @since 2.6.0
 */
function wp_cache_reset()
{
    _deprecated_function(__FUNCTION__, '3.5.0', 'WP_Object_Cache::reset()');
}

/**
 * Adds a group or set of groups to the list of non-prefetchable groups.
 *
 * This is a non-standard function that does not ship with WordPress,
 * be sure to copy it when uninstalling Object Cache Pro.
 *
 * @since 2.6.0
 *
 * @param string|array $groups A group or an array of groups to add.
 */
function wp_cache_add_non_prefetchable_groups($groups)
{
    global $wp_object_cache;

    $wp_object_cache->add_non_prefetchable_groups((array) $groups);
}

/**
 * Get data from the cache, or execute the given Closure and store the result.
 *
 * This is a non-standard function that does not ship with WordPress,
 * be sure to copy it when uninstalling Object Cache Pro.
 *
 * @param int|string  $key       The key under which the cache contents are stored.
 * @param int         $expire    Optional. When the cache data should expire, in seconds.
 *                               Default 0 (no expiration).
 * @param \Closure    $callback  The contents to store in the cache.
 * @param string      $group     Optional. Where the cache contents are grouped. Default empty.
 * @return bool|mixed False on failure to retrieve contents or the cache
 *                    contents on success
 */
function wp_cache_remember($key, $expire, Closure $callback, $group = '')
{
    $data = wp_cache_get($key, $group);

    if ($data !== false) {
        return $data;
    }

    $data = $callback();

    wp_cache_set($key, $data, $group, $expire);

    return $data;
}

/**
 * Get data from the cache, or execute the given Closure and store the result forever.
 *
 * This is a non-standard function that does not ship with WordPress,
 * be sure to copy it when uninstalling Object Cache Pro.
 *
 * @param int|string  $key       The key under which the cache contents are stored.
 * @param \Closure    $callback  The contents to store in the cache.
 * @param string      $group     Optional. Where the cache contents are grouped. Default empty.
 * @return bool|mixed False on failure to retrieve contents or the cache
 *                    contents on success
 */
function wp_cache_sear($key, Closure $callback, $group = '')
{
    return wp_cache_remember($key, 0, $callback, $group);
}
