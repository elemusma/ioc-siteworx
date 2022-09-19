<?php
/*
 * Plugin Name: Object Cache Pro
 * Plugin URI: https://objectcache.pro
 * Description: A business class Redis object cache backend for WordPress.
 * Version: 1.14.5
 * Author: Rhubarb Group
 * Author URI: https://rhubarb.group
 * License: Proprietary
 * Update URI: false
 * Network: true
 * Requires PHP: 7.0
 */

defined('ABSPATH') || exit;

/*
 * The plugin version number.
 */
define('RedisCachePro\Version', '1.14.5');

/**
 * Bootstrap the plugin and instantiate it.
 */
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/src/License.php';
require_once __DIR__ . '/src/Plugin/Analytics.php';
require_once __DIR__ . '/src/Plugin/Authorization.php';
require_once __DIR__ . '/src/Plugin/Dropin.php';
require_once __DIR__ . '/src/Plugin/Health.php';
require_once __DIR__ . '/src/Plugin/Licensing.php';
require_once __DIR__ . '/src/Plugin/Lifecycle.php';
require_once __DIR__ . '/src/Plugin/Meta.php';
require_once __DIR__ . '/src/Plugin/Network.php';
require_once __DIR__ . '/src/Plugin/Updates.php';
require_once __DIR__ . '/src/Plugin/Widget.php';
require_once __DIR__ . '/src/Plugin/Extensions/Debugbar.php';
require_once __DIR__ . '/src/Plugin/Extensions/QueryMonitor.php';
require_once __DIR__ . '/src/Plugin.php';

/*
 * Register WP CLI commands when running in console.
 */
if (defined('WP_CLI') && WP_CLI) {
    require_once dirname(__FILE__) . '/src/Console/Watchers/LogWatcher.php';
    require_once dirname(__FILE__) . '/src/Console/Watchers/DigestWatcher.php';
    require_once dirname(__FILE__) . '/src/Console/Watchers/AggregateWatcher.php';
    require_once dirname(__FILE__) . '/src/Console/Commands.php';

    WP_CLI::add_command('redis', \RedisCachePro\Console\Commands::class);
}

add_action('plugins_loaded', function () {
    if (! defined('RedisCachePro\Basename')) {
        define('RedisCachePro\Basename', plugin_basename(__FILE__));
    }

    $GLOBALS['ObjectCachePro'] = \RedisCachePro\Plugin::boot();

    // `$GLOBALS['RedisCachePro']` is deprecated since version 1.14.0!
    // Use `$GLOBALS['ObjectCachePro']` instead.
    $GLOBALS['RedisCachePro'] = $GLOBALS['ObjectCachePro'];
});

add_action('activated_plugin', function ($plugin) {
    if ($plugin === plugin_basename(__FILE__)) {
        deactivate_plugins('redis-cache/redis-cache.php', true, is_multisite());
    }
});
