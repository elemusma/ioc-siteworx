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

use Closure;
use Exception;
use ReflectionClass;

use RedisCachePro\Loggers\LoggerInterface;
use RedisCachePro\Configuration\Configuration;
use RedisCachePro\Exceptions\ObjectCacheException;

abstract class ObjectCache
{
    /**
     * The client name fallback.
     *
     * @var string
     */
    const Client = 'Unknown';

    /**
     * The configuration instance.
     *
     * @var \RedisCachePro\Configuration\Configuration
     */
    protected $config;

    /**
     * The connection instance.
     *
     * @var \RedisCachePro\Connections\Connection
     */
    protected $connection;

    /**
     * The logger instance.
     *
     * @var \RedisCachePro\Loggers\LoggerInterface
     */
    protected $log;

    /**
     * Holds the objects cached in runtime memory.
     *
     * @var array
     */
    protected $cache = [];

    /**
     * The amount of times the cache data was already cached in memory.
     *
     * @var int
     */
    protected $hits = 0;

    /**
     * The amount of times the cache did not have the object in memory.
     *
     * @var int
     */
    protected $misses = 0;

    /**
     * The blog id used as prefix in network environments.
     *
     * @var string
     */
    protected $blogId;

    /**
     * Whether the environment is a network.
     *
     * @var bool
     */
    protected $isMultisite = false;

    /**
     * Holds an internal cache copy of the configured prefix.
     *
     * @see self::id()
     *
     * @var string
     */
    private $prefix;

    /**
     * The list of global cache groups that are not
     * blog specific in a network environment.
     *
     * @var array
     */
    protected $globalGroups = [];

    /**
     * The list of non-persistent groups.
     *
     * @var array
     */
    protected $nonPersistentGroups = [];

    /**
     * The list of non-persistent group matches for fast lookups.
     *
     * @var array
     */
    protected $nonPersistentGroupMatches = [];

    /**
     * The list of non-prefetchable groups.
     *
     * @var array
     */
    protected $nonPrefetchableGroups = [];

    /**
     * The list of non-prefetchable group matches for fast lookups.
     *
     * @var array
     */
    protected $nonPrefetchableGroupMatches = [];

    /**
     * Set given groups as global.
     *
     * @param  array  $groups
     */
    public function add_global_groups(array $groups)
    {
        $this->globalGroups = \array_unique(
            \array_merge($this->globalGroups, \array_values($groups))
        );
    }

    /**
     * Set given groups as non-persistent.
     *
     * @param  array  $groups
     */
    public function add_non_persistent_groups(array $groups)
    {
        $this->nonPersistentGroups = \array_unique(
            \array_merge($this->nonPersistentGroups, \array_values($groups))
        );

        foreach (\array_values($groups) as $group) {
            if (\strpos($group, '*') === false) {
                unset($this->nonPersistentGroupMatches[$group]);
            } else {
                foreach (\array_keys($this->nonPersistentGroupMatches) as $nonPersistentGroupMatch) {
                    if (\fnmatch($group, $nonPersistentGroupMatch)) {
                        unset($this->nonPersistentGroupMatches[$nonPersistentGroupMatch]);
                    }
                }
            }
        }
    }

    /**
     * Set given groups as non-prefetchable.
     *
     * @param  array  $groups
     */
    public function add_non_prefetchable_groups(array $groups)
    {
        $this->nonPrefetchableGroups = \array_unique(
            \array_merge($this->nonPrefetchableGroups, \array_values($groups))
        );
    }

    /**
     * Returns the configuration instance.
     *
     * @return \RedisCachePro\Configuration\Configuration
     */
    public function config(): Configuration
    {
        return $this->config;
    }

    /**
     * Returns the client name the object cache is using.
     *
     * @return \RedisCachePro\Configuration\Configuration
     */
    public function clientName()
    {
        return static::Client;
    }

    /**
     * Returns the connection instance.
     *
     * @return \RedisCachePro\Connections\ConnectionInterface
     */
    public function connection()
    {
        return $this->connection;
    }

    /**
     * Decrement given value by given offset.
     *
     * Forces value to be a signed integer.
     *
     * @param  int  $value
     * @param  int  $offset
     * @return int
     */
    protected function decrement($value, int $offset): int
    {
        if (! \is_int($value)) {
            $value = 0;
        }

        $value -= $offset;

        return max(0, $value);
    }

    /**
     * Handles connection errors.
     *
     * When WP_DEBUG is enabled, the exception will be re-thrown,
     * otherwise a critical log entry is emitted.
     *
     * @param  \Exception  $exception
     * @param  array  $context
     */
    protected function error(Exception $exception, array $context = []): void
    {
        global $wp_object_cache_errors;

        $wp_object_cache_errors[] = $exception->getMessage();

        $this->log->error(
            $exception->getMessage(),
            \array_merge(['exception' => $exception], $context)
        );

        if ($this->config->debug) {
            throw ObjectCacheException::from($exception);
        }
    }

    /**
     * Returns an array of all global groups.
     *
     * @return array
     */
    public function globalGroups(): array
    {
        return $this->globalGroups;
    }

    /**
     * Returns an array of all non-prefetchable groups.
     *
     * @return array
     */
    public function nonPrefetchableGroups(): array
    {
        return $this->nonPrefetchableGroups;
    }

    /**
     * Build cache identifier for given key and group.
     *
     * The configured prefix is added to all identifiers.
     * In network environments the `blog_id` is added to the group.
     *
     * @param  string  $key
     * @param  string  $group
     * @return string
     */
    protected function id(string $key, string $group): string
    {
        static $cache = [];

        $cacheKey = $this->isMultisite ? "{$this->blogId}:{$group}:{$key}" : "{$group}:{$key}";

        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        if (is_null($this->prefix)) {
            $this->prefix = $this->config->prefix ?: '';
        }

        $key = \str_replace(':', '-', $key);
        $group = \str_replace(':', '-', $group);

        if ($this->isMultisite && ! $this->isGlobalGroup($group)) {
            $group = "{$this->blogId}:{$group}";
        }

        $id = "{$this->prefix}:{$group}:{$key}";
        $id = \str_replace(' ', '-', $id);
        $id = \trim($id, ':');
        $id = \strtolower($id);

        return $cache[$cacheKey] = $id;
    }

    /**
     * Increment given value by given offset.
     *
     * Forces value to be a signed integer.
     *
     * @param  int  $value
     * @param  int  $offset
     * @return int
     */
    protected function increment($value, int $offset): int
    {
        if (! \is_int($value)) {
            $value = 0;
        }

        $value += $offset;

        return max(0, $value);
    }

    /**
     * Whether the group is a global group.
     *
     * @param  string  $group
     * @return bool
     */
    public function isGlobalGroup(string $group): bool
    {
        return \in_array($group, $this->globalGroups);
    }

    /**
     * Whether the group is persistent.
     *
     * @param  string  $group
     * @return bool
     */
    public function isPersistentGroup(string $group): bool
    {
        if (isset($this->nonPersistentGroupMatches[$group])) {
            return ! $this->nonPersistentGroupMatches[$group];
        }

        return ! $this->isNonPersistentGroup($group);
    }

    /**
     * Whether the group is non-persistent.
     *
     * @param  string  $group
     * @return bool
     */
    public function isNonPersistentGroup(string $group): bool
    {
        if (isset($this->nonPersistentGroupMatches[$group])) {
            return $this->nonPersistentGroupMatches[$group];
        }

        foreach ($this->nonPersistentGroups as $nonPersistentGroup) {
            if (\strpos($nonPersistentGroup, '*') === false) {
                if ($group === $nonPersistentGroup) {
                    return $this->nonPersistentGroupMatches[$group] = true;
                }
            } else {
                if (\fnmatch($nonPersistentGroup, $group)) {
                    return $this->nonPersistentGroupMatches[$group] = true;
                }
            }
        }

        return $this->nonPersistentGroupMatches[$group] = false;
    }

    /**
     * Whether the group is prefetchable.
     *
     * @param  string  $group
     * @return bool
     */
    public function isPrefetchableGroup(string $group): bool
    {
        if (isset($this->nonPrefetchableGroupMatches[$group])) {
            return ! $this->nonPrefetchableGroupMatches[$group];
        }

        return ! $this->isNonPrefetchableGroup($group);
    }

    /**
     * Whether the group is non-prefetchable.
     *
     * @param  string  $group
     * @return bool
     */
    public function isNonPrefetchableGroup(string $group): bool
    {
        if (isset($this->nonPrefetchableGroupMatches[$group])) {
            return $this->nonPrefetchableGroupMatches[$group];
        }

        foreach ($this->nonPrefetchableGroups as $nonPrefetchableGroup) {
            if (\strpos($nonPrefetchableGroup, '*') === false) {
                if ($group === $nonPrefetchableGroup) {
                    return $this->nonPrefetchableGroupMatches[$group] = true;
                }
            } else {
                if (\fnmatch($nonPrefetchableGroup, $group)) {
                    return $this->nonPrefetchableGroupMatches[$group] = true;
                }
            }
        }

        return $this->nonPrefetchableGroupMatches[$group] = false;
    }

    /**
     * Whether the environment is a network.
     *
     * @return bool
     */
    public function isMultisite(): bool
    {
        return (bool) $this->isMultisite;
    }

    /**
     * Returns an array of all non-persistent groups.
     *
     * @return array
     */
    public function nonPersistentGroups(): array
    {
        return $this->nonPersistentGroups;
    }

    /**
     * Returns various information about the object cache.
     *
     * @return object
     */
    public function info()
    {
        global $wp_object_cache_errors;

        $metrics = self::metrics();

        return (object) [
            'status' => false,
            'hits' => $metrics->hits,
            'misses' => $metrics->misses,
            'ratio' => $metrics->ratio,
            'bytes' => $metrics->bytes,
            'groups' => (object) [
                'cache' => $metrics->groups,
                'global' => $this->globalGroups(),
                'non_persistent' => $this->nonPersistentGroups(),
                'non_prefetchable' => $this->nonPrefetchableGroups(),
            ],
            'errors' => empty($wp_object_cache_errors) ? null : $wp_object_cache_errors,
            'meta' => array_filter([
                'Cache' => (new ReflectionClass($this))->getShortName(),
                'Logger' => (new ReflectionClass($this->log))->getShortName(),
            ]),
        ];
    }

    /**
     * Returns metrics about the object cache.
     *
     * @return object
     */
    public function metrics()
    {
        $total = $this->hits + $this->misses;

        $groups = array_map(function ($keys) {
            return [
                'keys' => count($keys),
                'bytes' => strlen(serialize($keys)),
            ];
        }, $this->cache);

        return (object) [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'ratio' => $total > 0 ? round($this->hits / ($total / 100), 2) : 100,
            'bytes' => array_sum(array_column($groups, 'bytes')),
            'groups' => $groups,
        ];
    }

    /**
     * Returns the logger instance.
     *
     * @return \RedisCachePro\Loggers\LoggerInterface
     */
    public function logger(): LoggerInterface
    {
        return $this->log;
    }

    /**
     * Set the blog id.
     *
     * @param  int  $blogId
     */
    public function setBlogId(int $blogId)
    {
        $this->blogId = $blogId;
    }

    /**
     * Set whether the environment is a network.
     *
     * @param  bool  $isMultisite
     */
    public function setMultisite(bool $isMultisite)
    {
        $this->isMultisite = $isMultisite;
    }

    /**
     * Whether the key was cached in runtime memory.
     *
     * @param  string  $id
     * @param  string  $group
     * @return bool
     */
    protected function hasInMemory(string $id, string $group)
    {
        return isset($this->cache[$group][$id]);
    }

    /**
     * Retrieves the cache contents from the runtime memory cache.
     *
     * @param  string  $id
     * @param  string  $group
     * @return mixed
     */
    protected function getFromMemory(string $id, string $group)
    {
        if (\is_object($this->cache[$group][$id])) {
            return clone $this->cache[$group][$id];
        }

        return $this->cache[$group][$id];
    }

    /**
     * Stores the data in runtime memory.
     *
     * @param  string  $id
     * @param  mixed  $data
     * @param  string  $group
     * @return void
     */
    protected function storeInMemory(string $id, $data, string $group)
    {
        $this->cache[$group][$id] = \is_object($data) ? clone $data : $data;
    }

    /**
     * Removes the cache contents matching key and group from the runtime memory cache.
     *
     * @param  string  $key
     * @param  string  $group
     * @return bool
     */
    public function deleteFromMemory(string $key, string $group)
    {
        $id = $this->id($key, $group);

        if (! $this->hasInMemory($id, $group)) {
            return false;
        }

        unset($this->cache[$group][$id]);
        unset($this->prefetch[$group][$key]);

        return true;
    }

    /**
     * Flushes the runtime cache.
     *
     * @return bool
     */
    public function flushMemory(): bool
    {
        $this->cache = [];

        return true;
    }

    /**
     * Flushes the runtime cache.
     *
     * @deprecated  1.12.0  Use `ObjectCache::flushMemory()` instead
     *
     * @return bool
     */
    public function flushRuntimeCache(): bool
    {
        if (function_exists('_deprecated_function')) {
            \_deprecated_function(__METHOD__, '1.12.0', __CLASS__ . '::flushMemory()');
        }

        return $this->flushMemory();
    }

    /**
     * Removes all in-memory cache items for a single blog in multisite environments,
     * otherwise defaults to flushing the entire in-memory cache.
     *
     * Unless the `$flush_network` parameter is given this method
     * will default to `flush_network` configuration option.
     *
     * @param  int  $siteId
     * @param  string  $flush_network
     *
     * @return bool
     */
    public function flushBlog(int $siteId, string $flush_network = null): bool
    {
        if (is_null($flush_network)) {
            $flush_network = $this->config->flush_network;
        }

        if (! $this->isMultisite || $flush_network === Configuration::NETWORK_FLUSH_ALL) {
            return $this->flushMemory();
        }

        $originalBlogId = $this->blogId;
        $this->blogId = $siteId;

        if ($flush_network === Configuration::NETWORK_FLUSH_GLOBAL) {
            foreach ($this->globalGroups() as $group) {
                unset($this->cache[$group]);
            }
        }

        $prefix = trim(str_replace('cafebabe:', '', $this->id('*', dechex(3405691582))), '*');
        $prefixLength = strlen($prefix);

        foreach ($this->cache as $group => $keys) {
            foreach (array_keys($keys) as $key) {
                if (substr_compare($key, $prefix, 0, $prefixLength) === 0) {
                    unset($this->cache[$group][$key]);
                }
            }
        }

        $this->blogId = $originalBlogId;

        return true;
    }

    /**
     * Execute the given closure without data mutations on the connection,
     * such as serialization and compression algorithms.
     *
     * @param  Closure  $callback
     * @return mixed
     */
    public function withoutMutations(Closure $callback)
    {
        return $this->connection->withoutMutations(
            Closure::bind($callback, $this, $this)
        );
    }

    /**
     * Overload generic properties for compatibility.
     *
     * @param  string  $name
     * @return mixed
     */
    public function __get($name)
    {
        if ($name === 'cache_hits') {
            return $this->hits;
        }

        if ($name === 'cache_misses') {
            return $this->misses;
        }

        trigger_error(
            sprintf('Undefined property: %s::$%s', get_called_class(), $name),
            E_USER_WARNING
        );
    }

    /**
     * Overload generic properties for compatibility.
     *
     * @param  string  $name
     * @return bool
     */
    public function __isset($name)
    {
        return in_array($name, [
            'cache_hits',
            'cache_misses',
        ]);
    }
}
