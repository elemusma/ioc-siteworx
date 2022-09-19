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

namespace RedisCachePro\ObjectCaches\Concerns;

use Exception;

use RedisCachePro\Metrics\Measurement;
use RedisCachePro\Metrics\Measurements;
use RedisCachePro\Metrics\RedisMetrics;
use RedisCachePro\Metrics\RelayMetrics;
use RedisCachePro\Metrics\WordPressMetrics;

use RedisCachePro\Connections\RelayConnection;
use RedisCachePro\Configuration\Configuration;

trait TakesMeasurements
{
    /**
     * Retrieve measurements of the given type and range.
     *
     * @param  string  $min
     * @param  string  $max
     * @param  string  $offset
     * @param  string  $count
     * @return \RedisCachePro\Metrics\Measurements
     */
    public function measurements($min, $max = '+inf', $offset = null, $count = null)
    {
        if (is_int($offset) && is_int($count)) {
            $options = ['limit' => [$offset, $count]];
        }

        $measurements = new Measurements;

        try {
            $this->storeReads++;

            $measurements->push(
                ...$this->connection->zRevRangeByScore(
                    $this->id('measurements', 'analytics'),
                    strval($max),
                    strval($min),
                    $options ?? []
                )
            );
        } catch (Exception $exception) {
            $this->error($exception);
        }

        return $measurements;
    }

    /**
     * Stores metrics for the current request.
     *
     * @return void
     */
    protected function storeMeasurements()
    {
        if (! defined('\WP_REDIS_ANALYTICS') || ! \WP_REDIS_ANALYTICS) {
            return;
        }

        $now = time();
        $id = $this->id('measurements', 'analytics');

        $measurement = Measurement::make();
        $measurement->wp = $this->measureWordPress();

        try {
            $lastSample = $this->connection->get("{$id}:sample");
            $this->storeReads++;

            if ($lastSample < $now - 3) {
                $measurement->redis = $this->measureRedis();

                if ($this->connection instanceof RelayConnection) {
                    $measurement->relay = $this->measureRelay();
                }

                $this->connection->set("{$id}:sample", $now);
                $this->storeWrites++;
            }

            $this->connection->zadd($id, $measurement->timestamp, $measurement);
            $this->storeWrites++;
        } catch (Exception $exception) {
            $this->error($exception);
        }
    }

    /**
     * Discard measurements older than an hour.
     *
     * @return void
     */
    public function pruneMeasurements()
    {
        try {
            $this->storeWrites++;

            $this->connection->zRemRangeByScore(
                $this->id('measurements', 'analytics'),
                strval(0),
                strval(microtime(true) - \HOUR_IN_SECONDS)
            );
        } catch (Exception $exception) {
            $this->error($exception);
        }
    }

    /**
     * Gather and return WordPress related metrics for the current request.
     *
     * @return \RedisCachePro\Metrics\WordPressMetrics
     */
    public function measureWordPress()
    {
        static $metrics;

        if (! $metrics) {
            $metrics = new WordPressMetrics($this);
        }

        return $metrics;
    }

    /**
     * Gather and return Redis metrics.
     *
     * @return \RedisCachePro\Metrics\RedisMetrics
     */
    public function measureRedis()
    {
        return new RedisMetrics($this);
    }

    /**
     * Gather and return Relay metrics.
     *
     * @return \RedisCachePro\Metrics\RelayMetrics
     */
    public function measureRelay()
    {
        return new RelayMetrics($this->connection);
    }
}
