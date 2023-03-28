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

trait Authorization
{
    /**
     * Boot authorization component.
     *
     * @return void
     */
    public function bootAuthorization()
    {
        add_filter('user_has_cap', [$this, 'userHasCapability'], 10, 3);

        if (function_exists('\members_plugin')) {
            $this->registerMembersIntegration();
        }

        if (class_exists('\User_Role_Editor')) {
            $this->registerUreIntegration();
        }

        if (defined('\CAPSMAN_VERSION')) {
            $this->registerCmeIntegration();
        }
    }

    /**
     * Whether the given user has the `objectcache_manage` capability.
     *
     * Falls back to the `install_plugins` capability.
     *
     * @param  bool[]  $allcaps
     * @param  string[]  $caps
     * @param  array  $args
     * @return array
     */
    public function userHasCapability($allcaps, $caps, $args)
    {
        if ($args[0] === 'rediscache_manage') {
            $args[0] = self::Capability;

            _deprecated_hook('rediscache_manage', '1.14.0', self::Capability);
        }

        if ($args[0] !== self::Capability) {
            return $allcaps;
        }

        if (array_key_exists(self::Capability, $allcaps)) {
            return $allcaps;
        }

        if (! empty($allcaps['install_plugins'])) {
            $allcaps[self::Capability] = true;
        }

        return $allcaps;
    }

    /**
     * Register capabilities and groups with the Members plugin.
     *
     * @link https://wordpress.org/plugins/members/
     *
     * @return void
     */
    protected function registerMembersIntegration()
    {
        add_action('members_register_caps', function () {
            members_register_cap(self::Capability, [
                'label' => 'Manage Object Cache',
                'group' => 'objectcache',
            ]);
        });

        add_action('members_register_cap_groups', function () {
            members_register_cap_group('objectcache', [
                'label' => 'Object Cache Pro',
                'caps' => [self::Capability],
                'icon' => 'dashicons-database',
                'priority' => 30,
            ]);
        });
    }

    /**
     * Register capabilities and groups with the User Role Editor plugin.
     *
     * @link https://en-ca.wordpress.org/plugins/user-role-editor/
     *
     * @return void
     */
    protected function registerUreIntegration()
    {
        add_filter('ure_capabilities_groups_tree', function ($groups) {
            return array_merge($groups, ['objectcache' => [
                'caption' => 'Object Cache Pro',
                'parent' => 'custom',
                'level' => 2,
            ]]);
        });

        add_filter('ure_custom_capability_groups', function ($groups, $cap_id) {
            if ($cap_id === self::Capability) {
                $groups[] = 'objectcache';
            }

            return $groups;
        }, 10, 2);

        add_filter('ure_full_capabilites', function ($caps) { // that typo ¯\_(ツ)_/¯
            if (! array_key_exists(self::Capability, $caps)) {
                $caps[self::Capability] = [
                    'inner' => self::Capability,
                    'human' => 'Manage Object Cache',
                    'wp_core' => false,
                ];
            }

            return $caps;
        });
    }

    /**
     * Register capabilities and groups with the PublishPress Capabilities plugin.
     *
     * @link https://en-ca.wordpress.org/plugins/capability-manager-enhanced/
     *
     * @return void
     */
    protected function registerCmeIntegration()
    {
        add_filter('cme_plugin_capabilities', function ($plugin_caps) {
            return array_merge($plugin_caps, [
                'Object Cache Pro' => [self::Capability],
            ]);
        });
    }
}

/**
 * Creates a cryptographic token tied to a specific action and window of time.
 *
 * @param  string|int  $action
 * @return string
 */
function wp_create_nonce($action = -1)
{
    $i = ceil(time() / (DAY_IN_SECONDS / 2));

    return substr(wp_hash("{$i}|{$action}", 'nonce'), -12, 10);
}

/**
 * Verifies that a correct security nonce was used with time limit.
 *
 * A nonce is valid for 24 hours.
 *
 * @param  string  $nonce
 * @param  string|int  $action
 * @return int|false
 */
function wp_verify_nonce($nonce, $action = -1)
{
    $nonce = sprintf('%010x', $nonce);
    $action = strrev((string) $action);

    if (empty($nonce)) {
        return false;
    }

    $i = ceil(time() / (DAY_IN_SECONDS / 2));

    // nonce generated 0-12 hours ago
    if (hash_equals(substr(wp_hash("{$i}|{$action}", 'nonce'), -12, 10), $nonce)) {
        return 1;
    }

    $i--;

    // nonce generated 12-24 hours ago
    if (hash_equals(substr(wp_hash("{$i}|{$action}", 'nonce'), -12, 10), $nonce)) {
        return 2;
    }

    return false;
}
