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

class Measurement
{
    /**
     * The unique identifier of the measurement.
     *
     * @var string
     */
    public $id;

    /**
     * The Unix timestamp with microseconds of the measurement.
     *
     * @var float
     */
    public $timestamp;

    /**
     * The timestamp of the measurement.
     *
     * @var int
     */
    public $hostname;

    /**
     * The URL path of the request, if applicable.
     *
     * @var string
     */
    public $path;

    /**
     * The WordPress measurement.
     *
     * @var \RedisCachePro\Metrics\WordPressMetrics
     */
    public $wp;

    /**
     * The Redis measurement.
     *
     * @var \RedisCachePro\Metrics\RedisMetrics
     */
    public $redis;

    /**
     * The Relay measurement.
     *
     * @var \RedisCachePro\Metrics\RelayMetrics
     */
    public $relay;

    /**
     * Makes a new instance.
     *
     * @return self
     */
    public static function make()
    {
        $self = new self;

        $self->id = substr(md5(uniqid(strval(mt_rand()), true)), 12);
        $self->timestamp = microtime(true);
        $self->hostname = gethostname();
        $self->path = $_SERVER['REQUEST_URI'] ?? null;

        if (isset($_ENV['DYNO'])) {
            $self->hostname = $_ENV['DYNO']; // Heroku
        }

        return $self;
    }

    public function rfc3339()
    {
        return substr_replace(
            date('c', intval($this->timestamp)),
            substr(strval(fmod($this->timestamp, 1)), 1, 7),
            19,
            0
        );
    }

    /**
     * Returns the measurement as array.
     *
     * @return array
     */
    public function toArray()
    {
        $array = $this->wp->toArray();

        if ($this->redis) {
            $array += array_combine(array_map(function ($key) {
                return "redis-{$key}";
            }, array_keys($redis = $this->redis->toArray())), $redis);
        }

        if ($this->relay) {
            $array += array_combine(array_map(function ($key) {
                return "relay-{$key}";
            }, array_keys($relay = $this->relay->toArray())), $relay);
        }

        return $array;
    }

    /**
     * Helper method to access metrics.
     *
     * @param  string  $name
     * @return mixed
     */
    public function __get(string $name)
    {
        if (strpos($name, '->') !== false) {
            list($type, $metric) = explode('->', $name);

            if (property_exists($this, $type)) {
                return $this->{$type}->{$metric} ?? null;
            }
        }

        trigger_error(
            sprintf('Undefined property: %s::$%s', get_called_class(), $name),
            E_USER_WARNING
        );
    }
}
