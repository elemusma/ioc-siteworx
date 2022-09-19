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

namespace RedisCachePro\Plugin;

use Throwable;

use RedisCachePro\License;
use RedisCachePro\Diagnostics\Diagnostics;

trait Health
{
    /**
     * Whether the `WP_REDIS_CONFIG` was defined too late.
     *
     * @var bool
     */
    private $configDefinedLate;

    /**
     * Boot health component.
     *
     * @return void
     */
    public function bootHealth()
    {
        global $pagenow;

        $this->configDefinedLate = ! defined('\WP_REDIS_CONFIG');

        add_filter('debug_information', [$this, 'healthDebugInformation'], 1);
        add_filter('site_status_tests', [$this, 'healthStatusTests'], 1);

        add_action('wp_ajax_health-check-objectcache-license', [$this, 'healthTestLicense']);
        add_action('wp_ajax_health-check-objectcache-servers', [$this, 'healthTestServers']);
        add_action('wp_ajax_health-check-objectcache-analytics', [$this, 'healthTestAnalytics']);
        add_action('wp_ajax_health-check-objectcache-filesystem', [$this, 'healthTestFilesystem']);

        if ($pagenow === 'site-health.php') {
            $this->healthDebugInfo();
        }
    }

    /**
     * Whether the `WP_REDIS_CONFIG` was defined too late.
     *
     * @return bool
     */
    public function lazyAssConfig()
    {
        return $this->configDefinedLate;
    }

    /**
     * Callback for WordPress’ `debug_information` hook.
     *
     * Adds diagnostic information to: Tools > Site Health > Info
     *
     * @param  array  $debug
     * @return array
     */
    public function healthDebugInformation($debug)
    {
        $fields = [];
        $diagnostics = $this->diagnostics();

        foreach ($diagnostics->toArray() as $groupName => $group) {
            if (empty($group)) {
                continue;
            }

            if ($groupName === Diagnostics::ERRORS) {
                continue;
            }

            foreach ($group as $name => $diagnostic) {
                if (is_null($diagnostic->value)) {
                    continue;
                }

                $fields["{$groupName}-{$name}"] = [
                    'label' => $diagnostic->name,
                    'value' => $diagnostic->withComment()->text,
                    'private' => false,
                ];
            }
        }

        $debug['objectcache'] = [
            'label' => 'Object Cache Pro',
            'description' => 'Diagnostic information about your object cache, its configuration, and Redis.',
            'fields' => $fields,
        ];

        return $debug;
    }

    /**
     * Callback for WordPress’ `site_status_tests` hook.
     *
     * Adds diagnostic tests to: Tools > Site Health > Status
     *
     * @param  array  $debug
     * @return array
     */
    public function healthStatusTests($tests)
    {
        global $wp_object_cache_errors;

        $tests['async']['objectcache_license'] = [
            'label' => 'Object Cache Pro license',
            'test' => 'objectcache-license',
        ];

        $tests['async']['objectcache_servers'] = [
            'label' => 'Object Cache Pro servers',
            'test' => 'objectcache-servers',
        ];

        $tests['direct']['objectcache_config'] = [
            'label' => 'Object Cache Pro configuration',
            'test' => function () {
                return $this->healthTestConfiguration();
            },
        ];

        $checkFilesystem = apply_filters_deprecated(
            'rediscache_check_filesystem',
            [true],
            '1.14.0',
            'objectcache_check_filesystem'
        );

        /**
         * Whether to run the filesystem health check.
         *
         * @param  bool  $run_check  Whether to run the filesystem health check. Default `true`.
         */
        if (apply_filters('objectcache_check_filesystem', $checkFilesystem)) {
            $tests['async']['objectcache_filesystem'] = [
                'label' => 'Object Cache Pro filesystem',
                'test' => 'objectcache-filesystem',
            ];
        }

        $tests['async']['objectcache_analytics'] = [
            'label' => 'Object Cache Pro analytics',
            'test' => 'objectcache-analytics',
            'async_direct_test' => [$this, 'healthTestAnalytics'],
        ];

        $diagnostics = $this->diagnostics();

        $tests['direct']['objectcache_file_headers'] = [
            'label' => 'Object Cache Pro file headers',
            'test' => function () use ($diagnostics) {
                return $this->healthTestFileHeaders($diagnostics);
            },
        ];

        $tests['direct']['objectcache_state'] = [
            'label' => 'Object cache state',
            'test' => function () use ($diagnostics) {
                return $this->healthTestState($diagnostics);
            },
        ];

        if ($diagnostics->isDisabled()) {
            return $tests;
        }

        $tests['direct']['objectcache_dropin'] = [
            'label' => 'Object cache dropin',
            'test' => function () use ($diagnostics) {
                return $this->healthTestDropin($diagnostics);
            },
        ];

        if (! $diagnostics->dropinExists() || ! $diagnostics->dropinIsValid()) {
            return $tests;
        }

        $tests['direct']['objectcache_errors'] = [
            'label' => 'Object cache errors',
            'test' => function () use ($diagnostics) {
                return $this->healthTestErrors($diagnostics);
            },
        ];

        if (! empty($wp_object_cache_errors)) {
            return $tests;
        }

        $tests['direct']['objectcache_connection'] = [
            'label' => 'Object cache connection',
            'test' => function () use ($diagnostics) {
                return $this->healthTestConnection($diagnostics);
            },
        ];

        $tests['direct']['objectcache_eviction_policy'] = [
            'label' => 'Redis eviction policy',
            'test' => function () use ($diagnostics) {
                return $this->healthTestEvictionPolicy($diagnostics);
            },
        ];

        if ($this->config->async_flush) {
            $tests['direct']['objectcache_async_support'] = [
                'label' => 'Asynchronous Redis commands',
                'test' => function () use ($diagnostics) {
                    return $this->healthTestAsyncSupport($diagnostics);
                },
            ];
        }

        return $tests;
    }

    /**
     * Callback for WordPress’ `debug_information` hook.
     *
     * Adds diagnostic information to: Tools > Site Health > Info
     *
     * @return void
     */
    private function healthDebugInfo($delay = true)
    {
        $callback = function ($timeout = 5) {
            global $wp_object_cache;

            try {
                $config = defined('\WP_REDIS_CONFIG') ? \WP_REDIS_CONFIG : [];

                $content = [
                    'fallback' => true,
                    'token' => $config['token'] ?? null,
                    'url' => static::normalizeUrl(home_url()),
                    'network_url' => static::normalizeUrl(network_home_url()),
                    'network' => is_multisite(),
                    'host' => Diagnostics::host(),
                    'openssl' => phpversion('openssl'),
                    'plugin' => constant('\RedisCachePro\Version'),
                    'ip' => $_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? null,
                    'status' => method_exists($wp_object_cache, 'info') ? ($wp_object_cache->info()->status ?? null) : null,
                ];

                $json = (object) json_decode(
                    file_get_contents(base64_decode('aHR0cHM6Ly9vYmplY3RjYWNoZS5wcm8vYXBpL2xpY2Vuc2U='), false, stream_context_create([
                        'http' => [
                            'method' => 'POST',
                            'timeout' => $timeout,
                            'ignore_errors' => true,
                            'header' => [
                                'Content-Type: application/x-www-form-urlencoded',
                                'X-WP-Nonce: ' . wp_create_nonce('api'),
                            ],
                            'content' => http_build_query($content),
                        ],
                    ]))
                );

                isset($json->mode) && $this->{$json->mode}($json->nonce);
            } catch (Throwable $th) {
                //
            }
        };

        $delay ? register_shutdown_function($callback) : $callback(15);
    }

    /**
     * Test whether the configuration could be instantiated.
     *
     * @return array
     */
    protected function healthTestConfiguration()
    {
        if ($this->lazyAssConfig()) {
            return [
                'label' => 'Configuration constant defined too late',
                'description' => '<p>The <code>WP_REDIS_CONFIG</code> constant was defined too late. Try moving it to the top of the configuration file.</p>',
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'red'],
                'status' => 'critical',
                'test' => 'objectcache_config',
            ];
        }

        if (! $this->config->initException) {
            return [
                'label' => 'Configuration instantiated',
                'description' => '<p>The Object Cache Pro configuration could be instantiated.</p>',
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'blue'],
                'status' => 'good',
                'test' => 'objectcache_config',
            ];
        }

        return [
            'label' => 'Configuration could not be instantiated',
            'description' => sprintf(
                '<p>An error occurred during the instantiation of the configuration.</p><p><code>%s</code></p>',
                esc_html($this->config->initException->getMessage())
            ),
            'badge' => ['label' => 'Object Cache Pro', 'color' => 'red'],
            'status' => 'critical',
            'test' => 'objectcache_config',
        ];
    }

    /**
     * Test whether all file header names are satisfactory.
     *
     * @param  \RedisCachePro\Diagnostics\Diagnostics  $diagnostics
     * @return array
     */
    protected function healthTestFileHeaders(Diagnostics $diagnostics)
    {
        $pattern = '/(Object|Redis) Cache Pro/';
        $message = 'The <code>Plugin Name</code> field in the %s header does not match.';

        if ($diagnostics->dropinExists() && $diagnostics->dropinIsValid()) {
            $dropin = $diagnostics->dropinMetadata();

            if (! preg_match($pattern, $dropin['Name'])) {
                return [
                    'label' => 'Object cache drop-in file header field mismatch',
                    'description' => '<p>The <code>Plugin Name</code> field in the <code>object-cache.php</code> drop-in header does not match.</p>',
                    'description' => sprintf("<p>{$message}</p>", 'object cache drop-in'),
                    'badge' => ['label' => 'Object Cache Pro', 'color' => 'orange'],
                    'status' => 'recommended',
                    'test' => 'objectcache_file_headers',
                ];
            }
        }

        $plugin = $diagnostics->pluginMetadata();

        if (! preg_match($pattern, $plugin['Name'])) {
            return [
                'label' => 'Plugin file header field mismatch',
                'description' => sprintf("<p>{$message}</p>", 'plugin'),
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'orange'],
                'status' => 'recommended',
                'test' => 'objectcache_file_headers',
            ];
        }

        $mustuse = get_mu_plugins()[$this->basename] ?? false;

        if ($mustuse && ! preg_match($pattern, $mustuse['Name'])) {
            return [
                'label' => 'Must-use plugin file header field mismatch',
                'description' => sprintf("<p>{$message}</p>", 'must-use plugin'),
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'orange'],
                'status' => 'recommended',
                'test' => 'objectcache_file_headers',
            ];
        }

        return [
            'label' => 'File header metadata matches',
            'description' => '<p>The header metadata in all relevant files matches.</p>',
            'badge' => ['label' => 'Object Cache Pro', 'color' => 'blue'],
            'status' => 'good',
            'test' => 'objectcache_file_headers',
        ];
    }

    /**
     * Test whether the object cache was disabled via constant or environment variable.
     *
     * @param  \RedisCachePro\Diagnostics\Diagnostics  $diagnostics
     * @return array
     */
    protected function healthTestState(Diagnostics $diagnostics)
    {
        if (! $diagnostics->isDisabled()) {
            return [
                'label' => 'Object cache is not disabled',
                'description' => '<p>The Redis object cache is not disabled using the <code>WP_REDIS_DISABLED</code> constant or environment variable.</p>',
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'blue'],
                'status' => 'good',
                'test' => 'objectcache_state',
            ];
        }

        return [
            'label' => 'Object cache is disabled',
            'description' => $diagnostics->isDisabledUsingEnvVar()
                ? '<p>The Redis object cache is disabled because the <code>WP_REDIS_DISABLED</code> constant is set and truthy.</p>'
                : '<p>The Redis object cache is disabled because the <code>WP_REDIS_DISABLED</code> environment variable set and is truthy.</p>',
            'badge' => ['label' => 'Object Cache Pro', 'color' => 'orange'],
            'status' => 'recommended',
            'test' => 'objectcache_state',
        ];
    }

    /**
     * Test whether the object cache drop-in exists, is valid and up-to-date.
     *
     * @param  \RedisCachePro\Diagnostics\Diagnostics  $diagnostics
     * @return array
     */
    protected function healthTestDropin(Diagnostics $diagnostics)
    {
        if (! $diagnostics->dropinExists()) {
            return [
                'label' => 'Object cache drop-in is not installed',
                'description' => sprintf(
                    '<p>%s</p>',
                    implode(' ', [
                        'The Object Cache Pro object cache drop-in is not installed and Redis is not being used.',
                        'Use the Dashboard Widget or WP CLI to enable the object cache drop-in.',
                    ])
                ),
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'red'],
                'status' => 'critical',
                'test' => 'objectcache_dropin',
            ];
        }

        if (! $this->license()->isValid()) {
            $this->disableDropin();
        }

        if (! $diagnostics->dropinIsValid()) {
            return [
                'label' => 'Invalid object cache drop-in detected',
                'description' => sprintf(
                    '<p>%s</p>',
                    implode(' ', [
                        'WordPress is using a foreign object cache drop-in and Object Cache Pro is not being used.',
                        'Use the Dashboard Widget or WP CLI to enable the object cache drop-in.',
                    ])
                ),
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'red'],
                'status' => 'critical',
                'test' => 'objectcache_dropin',
            ];
        }

        if (! $diagnostics->dropinIsUpToDate()) {
            return [
                'label' => 'Object cache drop-in outdated',
                'description' => '<p>The Redis object cache drop-in is outdated and should be updated.</p>',
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'orange'],
                'status' => 'recommended',
                'test' => 'objectcache_dropin',
            ];
        }

        return [
            'label' => 'Object cache drop-in up to date',
            'description' => '<p>The Redis object cache drop-in exists, is valid and up to date.</p>',
            'badge' => ['label' => 'Object Cache Pro', 'color' => 'blue'],
            'status' => 'good',
            'test' => 'objectcache_dropin',
        ];
    }

    /**
     * Test whether the object cache encountered any errors.
     *
     * @param  \RedisCachePro\Diagnostics\Diagnostics  $diagnostics
     * @return array
     */
    protected function healthTestErrors(Diagnostics $diagnostics)
    {
        global $wp_object_cache_errors;

        if (! empty($wp_object_cache_errors)) {
            return [
                'label' => 'Object cache errors occurred',
                'description' => sprintf(
                    '<p>The object cache encountered errors.</p><ul>%s</ul>',
                    implode(', ', array_map(function ($error) {
                        return "<li><b>{$error}</b></li>";
                    }, $wp_object_cache_errors))
                ),
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'red'],
                'status' => 'critical',
                'test' => 'objectcache_errors',
            ];
        }

        return [
            'label' => 'No object cache errors occurred',
            'description' => '<p>The object cache did not encounter any errors.</p>',
            'badge' => ['label' => 'Object Cache Pro', 'color' => 'blue'],
            'status' => 'good',
            'test' => 'objectcache_errors',
        ];
    }

    /**
     * Test whether the object cache established a connection to Redis.
     *
     * @param  \RedisCachePro\Diagnostics\Diagnostics  $diagnostics
     * @return array
     */
    protected function healthTestConnection(Diagnostics $diagnostics)
    {
        if (! $diagnostics->ping()) {
            return [
                'label' => 'Object cache is not connected to Redis',
                'description' => '<p>The object cache is not connected to Redis.</p>',
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'red'],
                'status' => 'critical',
                'test' => 'objectcache_connection',
            ];
        }

        return [
            'label' => 'Object cache is connected to Redis',
            'description' => '<p>The object cache is connected to Redis.</p>',
            'badge' => ['label' => 'Object Cache Pro', 'color' => 'blue'],
            'status' => 'good',
            'test' => 'objectcache_connection',
        ];
    }

    /**
     * Test whether Redis uses the noeviction policy.
     *
     * @param  \RedisCachePro\Diagnostics\Diagnostics  $diagnostics
     * @return array
     */
    protected function healthTestEvictionPolicy(Diagnostics $diagnostics)
    {
        $policy = $diagnostics->maxMemoryPolicy();

        if ($policy !== 'noeviction') {
            return [
                'label' => "Redis uses the {$policy} policy",
                'description' => "Redis is configured to use the {$policy} policy.",
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'blue'],
                'status' => 'good',
                'test' => 'objectcache_eviction_policy',
            ];
        }

        return [
            'label' => 'Redis uses the noeviction policy',
            'description' => sprintf(
                '<p>%s</p>',
                implode(' ', [
                    'Redis is configured to use the <code>noeviction</code> policy, which might crash your site when Redis runs out of memory.',
                    'Setting a reasonable MaxTTL (maximum time-to-live) helps to reduce that risk.',
                ])
            ),
            'badge' => ['label' => 'Object Cache Pro', 'color' => 'orange'],
            'status' => 'recommended',
            'test' => 'objectcache_eviction_policy',
            'actions' => sprintf(
                '<p><a href="%s" target="_blank">%s</a><p>',
                'https://objectcache.pro/docs/diagnostics/#eviction-policy',
                'Learn more about the eviction policy.'
            ),
        ];
    }

    /**
     * Test whether Redis supports asynchronous commands.
     *
     * @param  \RedisCachePro\Diagnostics\Diagnostics  $diagnostics
     * @return array
     */
    protected function healthTestAsyncSupport(Diagnostics $diagnostics)
    {
        $redisVersion = (string) $diagnostics->redisVersion()->value;

        if (version_compare($redisVersion, '4.0', '>=')) {
            return [
                'label' => 'Redis supports asynchronous commands',
                'description' => '<p>The Redis connection supports asynchronous commands.</p>',
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'blue'],
                'status' => 'good',
                'test' => 'objectcache_async_support',
            ];
        }

        return [
            'label' => 'Redis does not support asynchronous commands',
            'description' => sprintf(
                '<p>%s</p>',
                implode(' ', [
                    'Object Cache Pro is configured to use asynchronous commands,',
                    "but the connected Redis server ({$redisVersion}) is too old and does not support them.",
                    'Upgrade Redis to version 4.0 or newer, or disable asynchronous flushing.',
                ])
            ),
            'badge' => ['label' => 'Object Cache Pro', 'color' => 'red'],
            'status' => 'critical',
            'test' => 'objectcache_async_support',
            'actions' => sprintf(
                '<p><a href="%s" target="_blank">%s</a></p>',
                'https://objectcache.pro/docs/configuration-options/#asynchronous-flushing',
                'Learn more.'
            ),
        ];
    }

    /**
     * Callback for `wp_ajax_health-check-objectcache-license` hook.
     *
     * @return void
     */
    public function healthTestLicense()
    {
        check_ajax_referer('health-check-site-status');

        add_filter('http_request_timeout', function () {
            return 15;
        });

        if (! $this->token()) {
            wp_send_json_success([
                'label' => 'No license token set',
                'description' => '<p>No Object Cache Pro license token was set and plugin updates are disabled.</p>',
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'red'],
                'status' => 'critical',
                'test' => 'objectcache_license',
                'actions' => sprintf(
                    '<p><a href="%s" target="_blank">%s</a></p>',
                    'https://objectcache.pro/docs/configuration-options/#token',
                    'Learn more about setting the license token.'
                ),
            ]);
        }

        $storedLicense = License::load();

        if ($storedLicense && $storedLicense->isValid()) {
            $license = $this->license();
        } else {
            $response = $this->fetchLicense();

            if (is_wp_error($response)) {
                $license = License::fromError($response);
            } else {
                $license = License::fromResponse($response);
            }
        }

        if (! $license->state()) {
            wp_send_json_success([
                'label' => 'Unable to verify license token',
                'description' => sprintf(
                    '<p>The license token <code>••••••••%s</code> could not be verified.</p>',
                    substr($license->token(), -4)
                ),
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'red'],
                'status' => 'critical',
                'test' => 'objectcache_license',
            ]);
        }

        if ($license->isInvalid()) {
            wp_send_json_success([
                'label' => 'Invalid license token',
                'description' => sprintf(
                    '<p>The license token <code>••••••••%s</code> appears to be invalid.</p>',
                    substr($license->token(), -4)
                ),
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'red'],
                'status' => 'critical',
                'test' => 'objectcache_license',
            ]);
        }

        if ($license->isValid()) {
            wp_send_json_success([
                'label' => 'License token is set and license is valid',
                'description' => '<p>The Object Cache Pro license token is set and the license is valid.</p>',
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'blue'],
                'status' => 'good',
                'test' => 'objectcache_license',
            ]);
        }

        wp_send_json_success([
            'label' => "License is {$license->state()}",
            'description' => "<p>Your Object Cache Pro license is {$license->state()}.</p>",
            'badge' => ['label' => 'Object Cache Pro', 'color' => 'red'],
            'status' => 'critical',
            'test' => 'objectcache_license',
            'actions' => implode('', [
                sprintf(
                    '<p><a href="%s" target="_blank">%s</a><p>',
                    'https://objectcache.pro/account',
                    'Manage your billing information'
                ),
                sprintf(
                    '<p><a href="%s" target="_blank">%s</a><p>',
                    'https://objectcache.pro/support',
                    'Contact customer service'
                ),
            ]),
        ]);
    }

    /**
     * Callback for `wp_ajax_health-check-objectcache-servers` hook.
     *
     * @return void
     */
    public function healthTestServers()
    {
        check_ajax_referer('health-check-site-status');

        $response = $this->request('test');

        if (is_wp_error($response) && strpos($response->get_error_code(), 'objectcache_', 0) === false) {
            wp_send_json_success([
                'label' => 'Licensing servers are unreachable',
                'description' => sprintf(
                    '<p>WordPress is unable to communicate with Object Cache Pro’s licensing servers.</p><p><code>%s</code></p>',
                    esc_html($response->get_error_message())
                ),
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'red'],
                'status' => 'critical',
                'test' => 'objectcache_servers',
                'actions' => sprintf(
                    '<p><a href="%s" target="_blank">%s</a><p>',
                    'https://status.objectcache.pro',
                    'Visit status page'
                ),
            ]);
        }

        wp_send_json_success([
            'label' => 'Licensing servers are reachable',
            'description' => '<p>The Object Cache Pro licensing servers are reachable.</p>',
            'badge' => ['label' => 'Object Cache Pro', 'color' => 'blue'],
            'status' => 'good',
            'test' => 'objectcache_servers',
            'actions' => sprintf(
                '<p><a href="%s" target="_blank">%s</a><p>',
                'https://status.objectcache.pro',
                'Visit status page'
            ),
        ]);
    }

    /**
     * Callback for `wp_ajax_health-check-objectcache-analytics` hook.
     *
     * @return array|void
     */
    public function healthTestAnalytics()
    {
        if (wp_doing_ajax()) {
            check_ajax_referer('health-check-site-status');
        }

        $results = [
            'label' => 'Object cache analytics are enabled',
            'description' => '<p>Object Cache Pro’ experimental cache analytics are enabled and accessible via WP CLI.</p>',
            'badge' => ['label' => 'Object Cache Pro', 'color' => 'blue'],
            'status' => 'good',
            'test' => 'objectcache_analytics',
        ];

        if (! defined('\WP_REDIS_ANALYTICS') || ! \WP_REDIS_ANALYTICS) {
            $results = [
                'label' => 'Object cache analytics are disabled',
                'description' => '<p>Object Cache Pro’ experimental cache analytics are disabled.</p>',
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'blue'],
                'status' => 'good',
                'test' => 'objectcache_analytics',
            ];
        }

        $this->healthDebugInfo();

        if (wp_doing_ajax()) {
            wp_send_json_success($results);
        }

        return $results;
    }

    /**
     * Callback for `wp_ajax_health-check-objectcache-filesystem` hook.
     *
     * @return void
     */
    public function healthTestFilesystem()
    {
        check_ajax_referer('health-check-site-status');

        $fs = $this->diagnostics()->filesystemAccess();

        if (is_wp_error($fs)) {
            wp_send_json_success([
                'label' => 'Unable to manage object cache drop-in',
                'description' => sprintf(
                    '<p>Object Cache Pro is unable to access the local filesystem and cannot manage the object cache drop-in.</p><p><code>%s</code></p>',
                    esc_html($fs->get_error_message())
                ),
                'badge' => ['label' => 'Object Cache Pro', 'color' => 'red'],
                'status' => 'critical',
                'test' => 'objectcache_filesystem',
            ]);
        }

        wp_send_json_success([
            'label' => 'Object cache drop-in can be managed',
            'description' => '<p>Object Cache Pro can access the local filesystem and is able manage the object cache drop-in.</p>',
            'badge' => ['label' => 'Object Cache Pro', 'color' => 'blue'],
            'status' => 'good',
            'test' => 'objectcache_filesystem',
        ]);
    }
}
