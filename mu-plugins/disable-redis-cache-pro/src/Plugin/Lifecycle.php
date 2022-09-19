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

trait Lifecycle
{
    /**
     * Boot lifecycle component and register hooks.
     *
     * @return void
     */
    public function bootLifecycle()
    {
        add_action('init', [$this, 'run']);

        // add_action("activate_{$this->basename}", [$this, 'activate']);
        add_action("deactivate_{$this->basename}", [$this, 'deactivate']);
        add_action("uninstall_{$this->basename}", [$this, 'uninstall']);
    }

    /**
     * Called when initializing WordPress.
     *
     * @return void
     */
    public function run()
    {
        if (is_admin()) {
            $this->license();
        }
    }

    /**
     * Called by `activate_{$plugin}` hook.
     *
     * @return void
     */
    public function activate()
    {
        //
    }

    /**
     * Called by `deactivate_{$plugin}` hook.
     *
     * @return void
     */
    public function deactivate()
    {
        delete_site_option('rediscache_license');
        delete_site_option('rediscache_license_last_check');

        $this->disableDropin();
        $this->flush();
    }

    /**
     * Called by `uninstall_{$plugin}` hook.
     *
     * @return void
     */
    public function uninstall()
    {
        wp_unschedule_event(
            wp_next_scheduled('objectcache_prune_analytics'),
            'objectcache_prune_analytics'
        );
    }
}
