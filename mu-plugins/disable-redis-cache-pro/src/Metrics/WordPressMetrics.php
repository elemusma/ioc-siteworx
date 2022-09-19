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

namespace RedisCachePro\Metrics;

use RedisCachePro\ObjectCaches\ObjectCacheInterface;

class WordPressMetrics
{
    /**
     * The amount of times the cache data was already cached in memory.
     *
     * @var int
     */
    public $hits;

    /**
     * The amount of times the cache did not have the object in memory.
     *
     * @var int
     */
    public $misses;

    /**
     * The in-memory hits-to-misses ratio.
     *
     * @var float
     */
    public $hitRatio;

    /**
     * The in-memory cache's size in bytes.
     *
     * @var int
     */
    public $bytes;

    /**
     * The amount of valid, prefetched keys.
     *
     * @var int
     */
    public $prefetches;

    /**
     * Amount of times the cache read from the external cache.
     *
     * @var int
     */
    public $storeReads;

    /**
     * Amount of times the cache wrote to the external cache.
     *
     * @var int
     */
    public $storeWrites;

    /**
     * The amount of times the external cache had the object already cached.
     *
     * @var int
     */
    public $storeHits;

    /**
     * Amount of times the external cache did not have the object.
     *
     * @var int
     */
    public $storeMisses;

    /**
     * The amount of time (μs) WordPress took to render the request.
     *
     * @var float
     */
    public $totalMs;

    /**
     * The amount of time (μs) waited for the external cache to respond.
     *
     * @var float
     */
    public $cacheMs;

    /**
     * The median amount of time (μs) waited for the external cache to respond.
     *
     * @var float
     */
    public $cacheMedianMs;

    /**
     * The percentage of time waited for the external cache to respond,
     * relative to the amount of time WordPress took to render the request.
     *
     * @var int
     */
    public $cacheRatioMs;

    /**
     * Creates a new instance from given object cache.
     *
     * @param  \RedisCachePro\ObjectCaches\ObjectCacheInterface  $cache
     * @return void
     */
    public function __construct(ObjectCacheInterface $cache)
    {
        global $timestart;

        $info = $cache->metrics();

        $this->hits = $info->hits;
        $this->misses = $info->misses;
        $this->hitRatio = $info->ratio;
        $this->bytes = $info->bytes;
        $this->prefetches = $info->prefetches;
        $this->storeReads = $info->storeReads;
        $this->storeWrites = $info->storeWrites;
        $this->storeHits = $info->storeHits;
        $this->storeMisses = $info->storeMisses;
        $this->cacheMs = round($cache->connection()->ioWait('sum') * 1000, 2);
        $this->cacheMedianMs = round($cache->connection()->ioWait('median') * 1000, 2);

        $requestStart = $_SERVER['REQUEST_TIME_FLOAT'] ?? $timestart;

        if ($requestStart) {
            $this->totalMs = round((microtime(true) - $requestStart) * 1000, 2);
            $this->cacheRatioMs = round($this->cacheMs / (($this->cacheMs + $this->totalMs) / 100), 2);
        }

        $this->dbQueries = get_num_queries();
    }

    /**
     * Returns the request metrics as array.
     *
     * @return array
     */
    public function toArray()
    {
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'hit-ratio' => number_format($this->hitRatio, 1),
            'bytes' => $this->bytes,
            'prefetches' => $this->prefetches,
            'store-reads' => $this->storeReads,
            'store-writes' => $this->storeWrites,
            'store-hits' => $this->storeHits,
            'store-misses' => $this->storeMisses,
            'ms-total' => sprintf('%.2f', $this->totalMs),
            'ms-cache' => sprintf('%.2f', $this->cacheMs),
            'ms-cache-median' => sprintf('%.2f', $this->cacheMedianMs),
            'ms-cache-ratio' => number_format($this->cacheRatioMs, 1),
        ];
    }

    /**
     * Returns the request metrics in string format.
     *
     * @return string
     */
    public function __toString()
    {
        $metrics = $this->toArray();

        return implode(' ', array_map(function ($metric, $value) {
            return "metric#${metric}={$value}";
        }, array_keys($metrics), $metrics));
    }
}
