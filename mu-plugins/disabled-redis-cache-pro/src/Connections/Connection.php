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

namespace RedisCachePro\Connections;

use Throwable;

use RedisCachePro\Exceptions\ConnectionException;

abstract class Connection
{
    /**
     * The amounts of time (μs) waited for the external cache to respond.
     *
     * @var float[]
     */
    protected $ioWait = [];

    /**
     * The configuration instance.
     *
     * @var \RedisCachePro\Configuration\Configuration
     */
    protected $config;

    /**
     * The logger instance.
     *
     * @var \RedisCachePro\Loggers\Logger
     */
    protected $log;

    /**
     * Run a command against Redis.
     *
     * @param  string  $command
     * @param  array  $parameters
     * @return mixed
     */
    public function command(string $name, array $parameters = [])
    {
        $method = strtolower($name);
        $command = strtoupper($name);

        $context = [
            'command' => $command,
            'parameters' => $parameters,
        ];

        if ($this->config->debug || $this->config->save_commands) {
            $context['backtrace'] = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS);

            if (\function_exists('wp_debug_backtrace_summary')) {
                $context['backtrace_summary'] = \wp_debug_backtrace_summary(__CLASS__);
            }
        }

        try {
            $start = $this->now();

            $result = $this->client->{$method}(...$parameters);

            $time = $this->now() - $start;
            $this->ioWait[] = $time;
        } catch (Throwable $exception) {
            $this->log->error("Failed to execute Redis `{$command}` command", $context + [
                'exception' => $exception,
            ]);

            throw ConnectionException::from($exception);
        }

        $arguments = \implode(' ', \array_map('json_encode', $parameters));
        $commandWithArgs = \trim("{$command} {$arguments}");
        $ms = \round($time * 1000, 4);

        $this->log->info("Executed Redis command `{$commandWithArgs}` in {$ms}ms", $context + [
            'result' => $result,
            'time' => $ms,
        ]);

        return $result;
    }

    /**
     * Execute the callback without data mutations on the connection,
     * such as serialization and compression algorithms.
     *
     * @param  callable  $callback
     * @return mixed
     */
    public function withoutMutations(callable $callback)
    {
        return $callback($this);
    }

    /**
     * Returns the system's current time in microseconds.
     * Will use high resolution time when available.
     *
     * @return float
     */
    protected function now()
    {
        static $supportsHRTime;

        if (\is_null($supportsHRTime)) {
            $supportsHRTime = \function_exists('hrtime');
        }

        return $supportsHRTime ? \hrtime(true) * 1e-9 : \microtime(true);
    }

    /**
     * Returns the amounts of microseconds (μs) waited for the external cache to respond.
     *
     * Supports passing `sum` and `median` to get calculated results.
     *
     * @param  $format
     * @return float
     */
    public function ioWait($format = null)
    {
        switch ($format) {
            case 'sum':
            case 'total':
                return array_sum($this->ioWait);
            case 'median':
                sort($this->ioWait);

                $count = count($this->ioWait);
                $middle = floor(($count - 1) / 2);

                if ($count === 0) {
                    return 0;
                }

                if ($count % 2) {
                    return $this->ioWait[$middle];
                }

                return ($this->ioWait[$middle] + $this->ioWait[$middle + 1]) / 2;
            default:
                return $this->ioWait;
        }
    }

    /**
     * Returns the memoized result from the given command.
     *
     * @param string  $command
     *
     * @return mixed
     */
    public function memoize($command)
    {
        static $cache;

        $command = \strtolower($command);

        if (! isset($cache[$command])) {
            $cache[$command] = \method_exists($this, $command)
                ? $this->{$command}()
                : $this->command($command);
        }

        return $cache[$command];
    }

    /**
     * Pass other method calls down to the underlying client.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->command($method, $parameters);
    }
}
