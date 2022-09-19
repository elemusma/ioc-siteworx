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

/**
 * When the `split_alloptions` configuration option is enabled, the `alloptions` cache key is stored
 * in individual keys, instead of a single key. For some setups this helps to reduce data transfer
 * and will minimize race conditions when several processes update options simultaneously.
 */
trait SplitsAllOptionsIntoKeys
{
    /**
     * Returns a single `alloptions` array from the mapped keys.
     *
     * @param  string  $id
     * @return array|false
     */
    protected function getAllOptions(string $id)
    {
        $map = $this->getAllOptionsMap($id);

        if (empty($map)) {
            return false;
        }

        $this->storeReads++;

        $values = $this->connection->mget(
            $this->getAllOptionsKeys($id, $map)
        );

        return array_combine($map, $values);
    }

    /**
     * Returns the `alloptions` map.
     *
     * @param  string  $id
     * @return array|false
     */
    protected function getAllOptionsMap(string $id)
    {
        $this->storeReads++;

        return $this->connection->get("{$id}:map");
    }

    /**
     * Returns the fully qualified key names.
     *
     * @param  string  $id
     * @return array|false
     */
    protected function getAllOptionsKeys(string $id, $keys)
    {
        return array_map(function ($option) use ($id) {
            return "{$id}:{$option}";
        }, $keys);
    }

    /**
     * Keeps the `alloptions` map and keys in sync.
     *
     * 1. All keys present in memory, but not in given data, will be deleted
     * 2. All keys present in data, but not in memory, or with a different value will be set
     *
     * @param  string  $id
     * @param  mixed  $data
     * @return bool
     */
    protected function syncAllOptions(string $id, $data): bool
    {
        $runtimeCache = $this->hasInMemory($id, 'options')
            ? $this->getFromMemory($id, 'options')
            : [];

        $removedOptions = array_keys(array_diff_key($runtimeCache, $data));

        if (! empty($removedOptions)) {
            $this->storeWrites++;

            $this->connection->del(
                ...$this->getAllOptionsKeys($id, $removedOptions)
            );
        }

        $changedOptions = array_diff_assoc($data, $runtimeCache);

        if (! empty($changedOptions)) {
            $this->storeWrites++;

            $this->connection->mset(array_combine(
                $this->getAllOptionsKeys($id, array_keys($changedOptions)),
                array_values($changedOptions)
            ));
        }

        $map = array_keys($data);
        sort($map, SORT_STRING);

        if ($map !== $this->getAllOptionsMap($id)) {
            $this->storeWrites++;

            $this->connection->set("{$id}:map", $map);
        }

        return true;
    }

    /**
     * Deletes the `alloptions` map and all its keys.
     *
     * @param  string  $id
     * @return bool
     */
    protected function deleteAllOptions(string $id): bool
    {
        $map = $this->getAllOptionsMap($id);

        if (empty($map)) {
            return true;
        }

        $keys = $this->getAllOptionsKeys($id, $map);

        $this->storeWrites++;

        $command = $this->config->async_flush ? 'UNLINK' : 'DEL';

        return (bool) $this->connection->{$command}("{$id}:map", ...$keys);
    }
}
