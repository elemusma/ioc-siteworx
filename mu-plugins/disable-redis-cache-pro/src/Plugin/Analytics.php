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

trait Analytics
{
    /**
     * Boot analytics component.
     *
     * @return void
     */
    public function bootAnalytics()
    {
        global $wp_object_cache;

        if (! defined('\WP_REDIS_ANALYTICS') || ! \WP_REDIS_ANALYTICS) {
            return;
        }

        if (! method_exists($wp_object_cache, 'measurements')) {
            return;
        }

        add_action('wp_footer', [$this, 'shouldPrintMetricsComment']);
        add_action('wp_body_open', [$this, 'shouldPrintMetricsComment']);
        add_action('login_head', [$this, 'shouldPrintMetricsComment']);
        add_action('in_admin_header', [$this, 'shouldPrintMetricsComment']);
        add_action('rss_tag_pre', [$this, 'shouldPrintMetricsComment']);

        add_action('shutdown', [$this, 'maybePrintMetricsComment'], PHP_INT_MAX);

        add_action('objectcache_prune_analytics', [$this, 'pruneAnalytics']);

        if (wp_doing_cron() && ! wp_next_scheduled('objectcache_prune_analytics')) {
            wp_schedule_event(time(), 'hourly', 'objectcache_prune_analytics');
        }
    }

    /**
     * Callback for the scheduled `objectcache_prune_analytics` hook.
     *
     * @return void
     */
    public function pruneAnalytics()
    {
        global $wp_object_cache;

        $wp_object_cache->pruneMeasurements();
    }

    /**
     * Print the request's metrics as HTML comment.
     *
     * @return bool|null
     */
    public function shouldPrintMetricsComment()
    {
        static $shouldPrint;

        if (doing_action('shutdown')) {
            return $shouldPrint;
        }

        $shouldPrint = true;
    }

    /**
     * Print the request's metrics as HTML comment.
     *
     * @return void
     */
    public function maybePrintMetricsComment()
    {
        global $wp_object_cache;

        $option = defined('WP_REDIS_ANALYTICS_HTML')
            ? \WP_REDIS_ANALYTICS_HTML
            : true;

        if (! $option && ! \WP_DEBUG && ! $this->config->debug) {
            return;
        }

        if (! $this->shouldPrintMetricsComment()) {
            return;
        }

        if (is_robots() || is_trackback()) {
            return;
        }

        if (
            (defined('\WP_CLI') && \WP_CLI) ||
            (defined('\REST_REQUEST') && \REST_REQUEST) ||
            (defined('\XMLRPC_REQUEST') && \XMLRPC_REQUEST) ||
            (defined('\DOING_AJAX') && \DOING_AJAX) ||
            (defined('\DOING_CRON') && \DOING_CRON) ||
            (defined('\DOING_AUTOSAVE') && \DOING_AUTOSAVE) ||
            (function_exists('wp_is_json_request') && wp_is_json_request()) ||
            (function_exists('wp_is_jsonp_request') && wp_is_jsonp_request())
        ) {
            return;
        }

        printf(
            "<!-- plugin=%s client=%s %s -->\n",
            'object-cache-pro',
            strtolower($wp_object_cache->clientName()),
            $wp_object_cache->measureWordPress()
        );
    }
}
