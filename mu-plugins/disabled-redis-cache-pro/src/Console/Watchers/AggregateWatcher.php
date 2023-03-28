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

namespace RedisCachePro\Console\Watchers;

use cli\Notify;
use cli\Streams;

class AggregateWatcher extends Notify
{
    /**
     * Holds the command options.
     *
     * @var array
     */
    public $options;

    /**
     * The object cache instance.
     *
     * @var \RedisCachePro\ObjectCaches\ObjectCacheInterface;
     */
    public $cache;

    /**
     * Whether Relay is being used.
     *
     * @var bool
     */
    public $usingRelay;

    /**
     * Holds the timestamp of the next aggregate.
     *
     * @var int
     */
    protected $next;

    /**
     * The measurements to display.
     *
     * @var \RedisCachePro\Metrics\Measurements
     */
    protected $measurements;

    /**
     * Holds the default metrics.
     *
     * @var array
     */
    protected $defaultMetrics = [
        'ms-total',
        'ms-cache',
        'ms-cache-ratio',
        'hits',
        'misses',
        'hit-ratio',
        'store-reads',
        'store-writes',
        'store-hits',
        'store-misses',
        'redis-hit-ratio',
        'redis-ops-per-sec',
        'redis-memory-ratio',
        'relay-hits',
        'relay-misses',
        'relay-memory-ratio',
    ];

    /**
     * Prints the metrics to the screen.
     *
     * @param bool  $finish
     * @return void
     */
    public function display($finish = false)
    {
        if ($this->_current === 1) {
            Streams::line(\WP_CLI::colorize($this->_message));
        }

        if (! $this->measurements || ! $this->measurements->count()) {
            return;
        }

        $metrics = empty($this->options['metrics'])
            ? $this->defaultMetrics
            : $this->options['metrics'];

        $data = [
            $this->format('measurements', $this->measurements->count()),
        ];

        foreach ($metrics as $metric) {
            $method = 'get' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $metric)));

            if (! method_exists($this, $method)) {
                \WP_CLI::error("Invalid metric name: {$metric}.");
            }

            $data[] = $this->format($metric, $this->{$method}());
        }

        Streams::line(implode(' ', array_filter($data)));

        $this->measurements = null;
    }

    /**
     * Prepare the metrics.
     *
     * @return void
     */
    public function prepare()
    {
        $now = time();
        $window = $this->options['seconds'];

        if (! $this->next) {
            $this->next = $now + $window;
        }

        if ($now < $this->next) {
            return;
        }

        $this->next = $now + $window;

        $max = $now - 2;
        $min = $max - $window;
        $this->measurements = $this->cache->measurements($min, "({$max}");
    }

    /**
     * Format the given measurement in log format.
     *
     * @param  string  $metric
     * @param  mixed  $value
     * @return string
     */
    protected function format(string $metric, $value)
    {
        if (is_null($value)) {
            return;
        }

        $format = "\e[%sm%s\e[0m\e[2m=\e[0m%s";

        switch (strstr($metric, '-', true) ?: $metric) {
            case 'redis':
                return sprintf($format, 31, $metric, $value);
            case 'relay':
                return sprintf($format, 35, $metric, $value);
            case 'ms':
                return sprintf($format, 36, $metric, $value);
            case 'measurements':
                return sprintf($format, 32, $metric, $value);
            default:
                return sprintf($format, 34, $metric, $value);
        }
    }

    /**
     * @return int
     */
    protected function getHits()
    {
        return round($this->measurements->median('wp->hits'));
    }

    /**
     * @return int
     */
    protected function getMisses()
    {
        return round($this->measurements->median('wp->misses'));
    }

    /**
     * @return string
     */
    protected function getHitRatio()
    {
        $hitRatioMedian = $this->measurements->median('wp->hitRatio');

        return is_null($hitRatioMedian) ? null : number_format($hitRatioMedian, 1);
    }

    /**
     * @return int
     */
    protected function getBytes()
    {
        return round($this->measurements->median('wp->bytes'));
    }

    /**
     * @return int
     */
    protected function getPrefetches()
    {
        return round($this->measurements->median('wp->prefetches'));
    }

    /**
     * @return int
     */
    protected function getStoreReads()
    {
        return round($this->measurements->median('wp->storeReads'));
    }

    /**
     * @return int
     */
    protected function getStoreWrites()
    {
        return round($this->measurements->median('wp->storeWrites'));
    }

    /**
     * @return int
     */
    protected function getStoreHits()
    {
        return round($this->measurements->median('wp->storeHits'));
    }

    /**
     * @return int
     */
    protected function getStoreMisses()
    {
        return round($this->measurements->median('wp->storeMisses'));
    }

    /**
     * @return string
     */
    protected function getMsTotal()
    {
        $msTotalMedian = $this->measurements->median('wp->totalMs');

        return is_null($msTotalMedian) ? null : number_format($msTotalMedian, 2, '.', '');
    }

    /**
     * @return string
     */
    protected function getMsCache()
    {
        $msCacheMedian = $this->measurements->median('wp->cacheMs');

        return is_null($msCacheMedian) ? null : number_format($msCacheMedian, 2, '.', '');
    }

    /**
     * @return string
     */
    protected function getMsCacheRatio()
    {
        $msCacheRatioMedian = $this->measurements->median('wp->cacheRatioMs');

        return is_null($msCacheRatioMedian) ? null : number_format($msCacheRatioMedian, 2, '.', '');
    }

    /**
     * @return int
     */
    protected function getRedisHits()
    {
        return round($this->measurements->median('redis->hits'));
    }

    /**
     * @return int
     */
    protected function getRedisMisses()
    {
        return round($this->measurements->median('redis->misses'));
    }

    /**
     * @return string
     */
    protected function getRedisHitRatio()
    {
        $hitRatioMedian = $this->measurements->median('redis->hitRatio');

        return is_null($hitRatioMedian) ? null : number_format($hitRatioMedian, 1);
    }

    /**
     * @return int
     */
    protected function getRedisOpsPerSec()
    {
        $opsPerSec = $this->measurements->latest('redis->opsPerSec');

        return is_null($opsPerSec) ? null : round($opsPerSec);
    }

    /**
     * @return int
     */
    protected function getRedisEvictedKeys()
    {
        $evictedKeys = $this->measurements->latest('redis->evictedKeys');

        return is_null($evictedKeys) ? null : round($evictedKeys);
    }

    /**
     * @return int
     */
    protected function getRedisUsedMemory()
    {
        $usedMemory = $this->measurements->latest('redis->usedMemory');

        return is_null($usedMemory) ? null : round($usedMemory);
    }

    /**
     * @return int
     */
    protected function getRedisUsedMemoryRss()
    {
        $usedMemoryRss = $this->measurements->latest('redis->usedMemoryRss');

        return is_null($usedMemoryRss) ? null : round($usedMemoryRss);
    }

    /**
     * @return string
     */
    protected function getRedisMemoryRatio()
    {
        $memoryRatio = $this->measurements->latest('redis->memoryRatio');

        return is_null($memoryRatio) ? null : number_format($memoryRatio, 1);
    }

    /**
     * @return string
     */
    protected function getRedisMemoryFragmentationRatio()
    {
        $memoryFragmentationRatio = $this->measurements->latest('redis->memoryFragmentationRatio');

        return is_null($memoryFragmentationRatio) ? null : number_format($memoryFragmentationRatio, 1);
    }

    /**
     * @return int
     */
    protected function getRedisConnectedClients()
    {
        $connectedClients = $this->measurements->latest('redis->connectedClients');

        return is_null($connectedClients) ? null : round($connectedClients);
    }

    /**
     * @return int
     */
    protected function getRedisTrackingClients()
    {
        $trackingClients = $this->measurements->latest('redis->trackingClients');

        return is_null($trackingClients) ? null : round($trackingClients);
    }

    /**
     * @return int
     */
    protected function getRedisRejectedConnections()
    {
        $rejectedConnections = $this->measurements->latest('redis->rejectedConnections');

        return is_null($rejectedConnections) ? null : round($rejectedConnections);
    }

    /**
     * @return int
     */
    protected function getRedisKeys()
    {
        $keys = $this->measurements->latest('redis->keys');

        return is_null($keys) ? null : round($keys);
    }

    /**
     * @return int
     */
    protected function getRelayHits()
    {
        if ($this->usingRelay) {
            $hits = $this->measurements->latest('relay->hits');

            return is_null($hits) ? null : round($hits);
        }
    }

    /**
     * @return int
     */
    protected function getRelayMisses()
    {
        if ($this->usingRelay) {
            $misses = $this->measurements->latest('relay->misses');

            return is_null($misses) ? null : round($misses);
        }
    }

    /**
     * @return int
     */
    protected function getRelayMemoryActive()
    {
        if ($this->usingRelay) {
            $memoryActive = $this->measurements->latest('relay->memoryActive');

            return is_null($memoryActive) ? null : round($memoryActive);
        }
    }

    /**
     * @return int
     */
    protected function getRelayMemoryTotal()
    {
        if ($this->usingRelay) {
            $memoryTotal = $this->measurements->latest('relay->memoryTotal');

            return is_null($memoryTotal) ? null : round($memoryTotal);
        }
    }

    /**
     * @return string
     */
    protected function getRelayMemoryRatio()
    {
        if ($this->usingRelay) {
            $memoryRatio = $this->measurements->latest('relay->memoryRatio');

            return is_null($memoryRatio) ? null : number_format($memoryRatio, 1);
        }
    }
}
