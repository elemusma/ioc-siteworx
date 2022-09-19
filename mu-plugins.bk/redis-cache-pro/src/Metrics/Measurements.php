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

use Countable;
use Traversable;
use ArrayAccess;
use ArrayIterator;
use IteratorAggregate;

class Measurements implements ArrayAccess, Countable, IteratorAggregate
{
    /**
     * The measurements contained in the collection.
     *
     * @var Measurement[]
     */
    protected $items = [];

    /**
     * Get all of the items in the collection.
     *
     * @return array
     */
    public function all()
    {
        return $this->items;
    }

    /**
     * Count the number of items in the collection.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Execute a callback over each item.
     *
     * @param  callable  $callback
     * @return $this
     */
    public function each(callable $callback)
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key) === false) {
                break;
            }
        }

        return $this;
    }

    /**
     * Run a filter over each of the items.
     *
     * @param  callable  $callback
     * @return self
     */
    public function filter(callable $callback)
    {
        $this->items = array_filter($this->items, $callback);

        return $this;
    }

    /**
     * Get an iterator for the items.
     *
     * @return \ArrayIterator
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * Get the latest value of a given metric.
     *
     * @param  string  $metric
     * @return mixed
     */
    public function latest(string $metric)
    {
        foreach (array_reverse($this->items) as $item) {
            if (! is_null($item->{$metric})) {
                return $item->{$metric};
            }
        }
    }

    /**
     * Get the average value of a given metric.
     *
     * @param  string  $metric
     * @return mixed
     */
    public function mean(string $metric)
    {
        $items = array_map(function ($item) use ($metric) {
            return $item->{$metric};
        }, $this->items);

        $items = array_filter($items, function ($value) {
            return ! is_null($value);
        });

        if ($count = count($items)) {
            return array_sum($items) / $count;
        }
    }

    /**
     * Get the median of a given metric.
     *
     * @param  string  $metric
     * @return mixed
     */
    public function median(string $metric)
    {
        $values = array_map(function ($item) use ($metric) {
            return $item->{$metric};
        }, $this->items);

        $values = array_filter($values, function ($value) {
            return ! is_null($value);
        });

        $count = count($values);

        if ($count === 0) {
            return;
        }

        sort($values);

        $middle = floor($count / 2);

        if ($count % 2) {
            return $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * Get the values of a given key.
     *
     * @param  string  $metric
     * @return array
     */
    public function pluck(string $metric)
    {
        return array_map(function ($item) use ($metric) {
            return $item->{$metric};
        }, $this->items);
    }

    /**
     * Push one or more items onto the end of the collection.
     *
     * @param Measurement[]  $metrics
     * @return self
     */
    public function push(Measurement ...$metrics): self
    {
        array_push($this->items, ...$metrics);

        return $this;
    }

    /**
     * Determine if an item exists at an offset.
     *
     * @param  mixed  $key
     * @return bool
     */
    public function offsetExists($key): bool
    {
        return isset($this->items[$key]);
    }

    /**
     * Get an item at a given offset.
     *
     * @param  mixed  $key
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($key)
    {
        return $this->items[$key];
    }

    /**
     * Set the item at a given offset.
     *
     * @param  mixed  $key
     * @param  mixed  $value
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function offsetSet($key, $value)
    {
        if (is_null($key)) {
            $this->items[] = $value;
        } else {
            $this->items[$key] = $value;
        }
    }

    /**
     * Unset the item at a given offset.
     *
     * @param  string  $key
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function offsetUnset($key)
    {
        unset($this->items[$key]);
    }
}
