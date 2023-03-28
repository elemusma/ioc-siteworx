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

namespace RedisCachePro\Diagnostics;

use ArrayAccess;
use RuntimeException;

use WP_Error;

use RedisCachePro\License;
use RedisCachePro\Connections\RelayConnection;
use RedisCachePro\Configuration\Configuration;
use RedisCachePro\ObjectCaches\ObjectCacheInterface;

use const RedisCachePro\Basename;

class Diagnostics implements ArrayAccess
{
    /**
     * Diagnose group: Configuration values.
     *
     * @var string
     */
    const CONFIG = 'config';

    /**
     * Diagnose group: Constants.
     *
     * @var string
     */
    const CONSTANTS = 'constants';

    /**
     * Diagnose group: Errors.
     *
     * @var string
     */
    const ERRORS = 'errors';

    /**
     * Diagnose group: General information.
     *
     * @var string
     */
    const GENERAL = 'general';

    /**
     * Diagnose group: Version numbers.
     *
     * @var string
     */
    const VERSIONS = 'versions';

    /**
     * Diagnose group: Statistics.
     *
     * @var string
     */
    const STATISTICS = 'statistics';

    /**
     * The object cache instance.
     *
     * @var \RedisCachePro\ObjectCaches\ObjectCacheInterface
     */
    protected $cache;

    /**
     * The configuration instance.
     *
     * @var \RedisCachePro\Configuration\Configuration
     */
    protected $config;

    /**
     * The connection instance.
     *
     * @var \RedisCachePro\Connections\ConnectionInterface
     */
    protected $connection;

    /**
     * Holds the diagnostics groups and their data.
     *
     * @var array
     */
    protected $data = [];

    /**
     * Create a new diagnostics instance.
     *
     * @param  \RedisCachePro\ObjectCaches\ObjectCacheInterface  $cache
     */
    public function __construct($cache)
    {
        if (is_object($cache) && in_array(ObjectCacheInterface::class, (array) class_implements($cache))) {
            $this->cache = $cache;
            $this->config = $this->cache->config();
            $this->connection = $this->cache->connection();
        }

        if (! $this->config && defined('\WP_REDIS_CONFIG')) {
            $this->config = Configuration::safelyFrom(\WP_REDIS_CONFIG);
        }

        $this->gatherGeneral();
        $this->gatherVersions();
        $this->gatherStatistics();
        $this->gatherConfiguration();
        $this->gatherConstants();
        $this->gatherErrors();
    }

    /**
     * Gathers general information, such as the used object cache class,
     * the connection status and eviction policy.
     *
     * @return void
     */
    protected function gatherGeneral()
    {
        $this->data[self::GENERAL]['status'] = $this->status();

        $this->data[self::GENERAL]['dropin'] = $this->dropin();

        $this->data[self::GENERAL]['license'] = $this->license();

        if ($evictionPolicy = $this->evictionPolicy()) {
            $this->data[self::GENERAL]['eviction-policy'] = $evictionPolicy;
        }

        $this->data[self::GENERAL]['env'] = Diagnostic::name('Environment')->value(
            self::environment()
        );

        if ($this->cache) {
            $this->data[self::GENERAL]['multisite'] = Diagnostic::name('Multisite')->value(
                $this->cache->isMultisite() ? 'Yes' : 'No'
            );

            $this->data[self::GENERAL]['global-groups'] = Diagnostic::name('Global Groups')->values(
                $this->cache->globalGroups()
            );

            $this->data[self::GENERAL]['non-persistent-groups'] = Diagnostic::name('Non-persistent Groups')->values(
                $this->cache->nonPersistentGroups()
            );

            $this->data[self::GENERAL]['non-prefetchable-groups'] = Diagnostic::name('Non-prefetchable Groups')->values(
                $this->cache->nonPrefetchableGroups()
            );
        }

        $this->data[self::GENERAL]['compressions'] = $this->compressions();

        if ($this->isDisabledUsingEnvVar()) {
            $this->data[self::GENERAL]['disabled'] = Diagnostic::name('Disabled')
                ->error('Using WP_REDIS_DISABLED environment variable');
        }

        if ($this->isDisabledUsingConstant()) {
            $this->data[self::GENERAL]['disabled'] = Diagnostic::name('Disabled')
                ->error('Using WP_REDIS_DISABLED constant');
        }

        $this->data[self::GENERAL]['mu'] = Diagnostic::name('Must-use')->value(
            self::isMustUse() ? 'Yes' : 'No'
        );

        $this->data[self::GENERAL]['vcs'] = Diagnostic::name('VCS')->value(
            self::usingVCS() ? 'Yes' : 'No'
        );

        $this->data[self::GENERAL]['host'] = Diagnostic::name('Hosting Provider')->value(
            self::host()
        );
    }

    /**
     * Gathers version numbers for PHP, extensions, Redis and the drop-in.
     *
     * @return void
     */
    protected function gatherVersions()
    {
        $this->data[self::VERSIONS]['php'] = $this->phpVersion();
        $this->data[self::VERSIONS]['igbinary'] = $this->igbinary();
        $this->data[self::VERSIONS]['phpredis'] = $this->phpredis();
        $this->data[self::VERSIONS]['relay'] = $this->relay();

        if ($redisVersion = $this->redisVersion()) {
            $this->data[self::VERSIONS]['redis'] = $redisVersion;
        }

        if ($pluginVersion = $this->pluginVersion()) {
            $this->data[self::VERSIONS]['plugin'] = $pluginVersion;
        }

        if ($dropinVersion = $this->dropinVersion()) {
            $this->data[self::VERSIONS]['dropin'] = $dropinVersion;
        }
    }

    /**
     * Gathers memory and usage statistics.
     *
     * @return void
     */
    protected function gatherStatistics()
    {
        $this->gatherRedisStatistics();

        if ($this->usingRelay()) {
            $this->gatherRelayStatistics();
        }
    }

    /**
     * Gathers Redis related statistics.
     *
     * @return void
     */
    protected function gatherRedisStatistics()
    {
        $memory = Diagnostic::name('Redis Memory');
        $usedMemory = $this->usedMemory();

        if ($usedMemory) {
            $maxMemory = $this->maxMemory();

            $memory->value(
                $maxMemory
                    ? sprintf('%s of %s', size_format($usedMemory), size_format($maxMemory))
                    : size_format($usedMemory, 2)
            );
        }

        $this->data[self::STATISTICS]['redis-memory'] = $memory;

        $keys = Diagnostic::name('Redis Keys');

        if ($this->connection && ! $this->config->cluster) {
            $keys->value($this->connection->memoize('dbsize'));
        }

        $this->data[self::STATISTICS]['redis-keys'] = $keys;
    }

    /**
     * Gathers Relay related statistics.
     *
     * @return void
     */
    protected function gatherRelayStatistics()
    {
        $stats = $this->connection->memoize('stats');

        $instance = array_search(
            $this->connection->socketKey(),
            array_column($stats['instances'], 'key')
        );

        $this->data[self::STATISTICS]['relay-memory'] = Diagnostic::name('Relay Memory')
            ->value(sprintf(
                '%s of %s',
                size_format($stats['memory']['active']),
                size_format($stats['memory']['total'])
            ));

        $keys = $stats['instances'][$instance]['keys'] ?? null;

        $this->data[self::STATISTICS]['relay-keys'] = Diagnostic::name('Relay Keys')
            ->value($keys);
    }

    /**
     * Gathers configuration values from the config instance.
     *
     * @return void
     */
    protected function gatherConfiguration()
    {
        if ($this->config) {
            foreach ($this->config->diagnostics() as $option => $value) {
                $name = ucwords(str_replace('_', ' ', $option));
                $this->data[self::CONFIG][$option] = Diagnostic::name($name)->value($value);
            }
        }
    }

    /**
     * Gathers relevant constants.
     *
     * @return void
     */
    protected function gatherConstants()
    {
        $constants = [
            'WP_DEBUG',
            'SAVEQUERIES',
            'WP_REDIS_DIR',
            'WP_REDIS_DISABLED',
            'WP_REDIS_CONFIG',
        ];

        foreach ($constants as $constant) {
            $diagnostic = Diagnostic::name($constant);

            if (defined($constant)) {
                $value = constant($constant);

                if (is_string($value)) {
                    $diagnostic->value($value);
                } elseif (is_array($value)) {
                    $diagnostic->prettyJson($value);
                } else {
                    $diagnostic->json($value);
                }
            } else {
                $diagnostic->value('undefined');
            }

            $this->data[self::CONSTANTS][$constant] = $diagnostic;
        }
    }

    /**
     * Gathers all occurred errors.
     *
     * @return void
     */
    protected function gatherErrors()
    {
        global $wp_object_cache_errors;

        if ($this->config->initException ?? false) {
            $this->data[self::ERRORS][] = sprintf(
                'The configuration could not be instantiated: %s',
                $this->config->initException->getMessage()
            );
        }

        if (empty($wp_object_cache_errors)) {
            return;
        }

        foreach ($wp_object_cache_errors as $error) {
            $this->data[self::ERRORS][] = $error;
        }
    }

    /**
     * Append filesystem access diagnostic to general group.
     *
     * @return self
     */
    public function withFilesystemAccess()
    {
        $fs = $this->filesystemAccess();

        $diagnostic = Diagnostic::name('Filesystem');

        if (is_wp_error($fs)) {
            $diagnostic->error($fs->get_error_message());
        } else {
            $diagnostic->value('Accessible');
        }

        $this->data[self::GENERAL]['filesystem'] = $diagnostic;

        return $this;
    }

    /**
     * Return the license status.
     *
     * @return \RedisCachePro\Diagnostic
     */
    protected function license()
    {
        $diagnostic = Diagnostic::name('License');

        if (! $this->config || ! $this->config->token) {
            return $diagnostic->error('Missing token');
        }

        $license = License::load();

        if (! $license) {
            return $diagnostic;
        }

        if ($license->isValid()) {
            return $diagnostic->value(ucwords((string) $license->state()));
        }

        return $diagnostic->error(ucwords((string) $license->state()));
    }

    /**
     * Return the object cache drop-in status.
     *
     * @return \RedisCachePro\Diagnostic
     */
    protected function dropin()
    {
        $diagnostic = Diagnostic::name('Drop-in');

        if (! $this->dropinExists()) {
            return $diagnostic->error('Not enabled');
        }

        if (! $this->dropinIsValid()) {
            return $diagnostic->error('Invalid');
        }

        if (! $this->dropinIsUpToDate()) {
            return $diagnostic->warning('Outdated');
        }

        return $diagnostic->value('Valid');
    }

    /**
     * Returns the drop-in version, if present.
     *
     * @return \RedisCachePro\Diagnostic
     */
    protected function dropinVersion()
    {
        $diagnostic = Diagnostic::name('Drop-in');

        if (! $this->dropinExists()) {
            return $diagnostic;
        }

        $dropin = $this->fileMetadata(WP_CONTENT_DIR . '/object-cache.php');
        $stub = $this->fileMetadata(__DIR__ . '/../../stubs/object-cache.php');

        if ($dropin['Version'] !== $stub['Version']) {
            return $diagnostic->error($dropin['Version'])->comment('Outdated');
        }

        return $diagnostic->value($dropin['Version']);
    }

    /**
     * Returns Redis' eviction policy, if a connection is established.
     *
     * @return \RedisCachePro\Diagnostic
     */
    protected function evictionPolicy()
    {
        $policy = $this->maxMemoryPolicy();
        $diagnostic = Diagnostic::name('Eviction Policy');

        if ($policy === 'noeviction' && ! $this->config->maxttl) {
            return $diagnostic->error($policy);
        }

        return $diagnostic->value($policy);
    }

    /**
     * Returns the used memory, if a connection is established.
     *
     * @return string|null
     */
    public function usedMemory()
    {
        return $this->redisInfo('used_memory');
    }

    /**
     * Returns the max memory, if a connection is established.
     *
     * @return string|null
     */
    public function maxMemory()
    {
        return $this->redisInfo('maxmemory');
    }

    /**
     * Return the `maxmemory_policy` from Redis.
     *
     * @return string|null
     */
    public function maxMemoryPolicy()
    {
        return $this->redisInfo('maxmemory_policy');
    }

    /**
     * Returns details about the igbinary extension.
     *
     * @return \RedisCachePro\Diagnostic
     */
    protected function igbinary()
    {
        $diagnostic = Diagnostic::name('igbinary');

        if (! extension_loaded('igbinary')) {
            return $diagnostic->error('Not installed');
        }

        $version = phpversion('igbinary');

        if (! defined('\Redis::SERIALIZER_IGBINARY')) {
            return $diagnostic->value($version)->comment('No PhpRedis support');
        }

        return $diagnostic->value($version);
    }

    /**
     * Returns details about the PhpRedis extension.
     *
     * @return \RedisCachePro\Diagnostic
     */
    protected function phpredis()
    {
        $diagnostic = Diagnostic::name('PhpRedis');

        if (! extension_loaded('redis')) {
            return $diagnostic->error('Not installed');
        }

        $version = phpversion('redis');

        if (version_compare($version, '3.1.1', '<')) {
            return $diagnostic->error($version)->comment('Unsupported');
        }

        return $diagnostic->value($version);
    }

    /**
     * Returns details about the Relay extension.
     *
     * @return \RedisCachePro\Diagnostic
     */
    protected function relay()
    {
        $diagnostic = Diagnostic::name('Relay');

        if (! extension_loaded('relay')) {
            return $diagnostic->error('Not installed');
        }

        $version = phpversion('relay');

        return $diagnostic->value($version);
    }

    /**
     * Returns details about the PHP version.
     *
     * Outdated comment is based on:
     * https://www.php.net/supported-versions.php
     *
     * @return \RedisCachePro\Diagnostic
     */
    protected function phpVersion()
    {
        $diagnostic = Diagnostic::name('PHP');

        $version = phpversion();

        if (version_compare($version, '7.4', '<')) {
            return $diagnostic->warning($version)->comment('Outdated');
        }

        if (version_compare($version, '8.0', '<') && date('Y') > 2021) {
            return $diagnostic->warning($version)->comment('Outdated');
        }

        if (version_compare($version, '8.1', '<') && date('Y') > 2022) {
            return $diagnostic->warning($version)->comment('Outdated');
        }

        return $diagnostic->value($version);
    }

    /**
     * Returns whether Redis responds to a PING command.
     *
     * @return bool
     */
    public function ping()
    {
        if ($this->connection) {
            return (bool) $this->connection->memoize('ping');
        }

        return false;
    }

    /**
     * Returns the status of the Redis connection.
     *
     * @return \RedisCachePro\Diagnostic
     */
    protected function status()
    {
        $diagnostic = Diagnostic::name('Status');

        if ($this->isDisabled()) {
            return $diagnostic->warning('Disabled');
        }

        return $this->ping()
            ? $diagnostic->success('Connected')
            : $diagnostic->error('Not connected');
    }

    /**
     * Returns a list of supported compression algorithms.
     *
     * @return \RedisCachePro\Diagnostic
     */
    protected function compressions()
    {
        $algorithms = array_filter([
            defined('\Redis::COMPRESSION_LZF') ? 'LZF' : null,
            defined('\Redis::COMPRESSION_LZ4') ? 'LZ4' : null,
            defined('\Redis::COMPRESSION_ZSTD') ? 'ZSTD' : null,
        ]);

        $diagnostic = Diagnostic::name('Supported compressions');

        return empty($algorithms)
            ? $diagnostic->value('None')
            : $diagnostic->values($algorithms);
    }

    /**
     * Returns the plugin version, if installed.
     *
     * @return \RedisCachePro\Diagnostic
     */
    protected function pluginVersion()
    {
        $plugin = $this->pluginMetadata();

        return Diagnostic::name('Plugin')->value(
            $plugin['Version'] ?? null
        );
    }

    /**
     * Returns the Redis server version, if a connection is established.
     *
     * @return \RedisCachePro\Diagnostic
     */
    public function redisVersion()
    {
        return Diagnostic::name('Redis')->value(
            $this->redisInfo('redis_version')
        );
    }

    /**
     * Returns information and statistics about the Redis server.
     *
     * @return array|null
     */
    protected function redisInfo($name = null)
    {
        $info = null;

        if ($this->connection) {
            $info = $this->connection->memoize('info');
        }

        if ($name && $info) {
            return $info[$name] ?? null;
        }

        return $info;
    }

    /**
     * Whether the object cache is using Relay.
     *
     * @return bool
     */
    public function usingRelay()
    {
        if ($this->connection) {
            return $this->connection instanceof RelayConnection;
        }

        return false;
    }

    /**
     * Whether the object cache is disabled.
     *
     * @return bool
     */
    public function isDisabled()
    {
        return $this->isDisabledUsingEnvVar()
            || $this->isDisabledUsingConstant();
    }

    /**
     * Whether the object cache is disabled using the `WP_REDIS_DISABLED` constant.
     *
     * @return bool
     */
    public function isDisabledUsingConstant()
    {
        return defined('\WP_REDIS_DISABLED') && \WP_REDIS_DISABLED;
    }

    /**
     * Whether the object cache is disabled using the `WP_REDIS_DISABLED` environment variable.
     *
     * @return bool
     */
    public function isDisabledUsingEnvVar()
    {
        return ! empty(getenv('WP_REDIS_DISABLED'));
    }

    /**
     * Whether the plugin is installed as a must-use plugin.
     *
     * @return bool
     */
    public static function isMustUse()
    {
        static $mustuse;

        if (! $mustuse) {
            $mustuse = array_key_exists(Basename, get_mu_plugins());
        }

        return $mustuse;
    }

    /**
     * Whether the plugin is using version control.
     *
     * @return bool
     */
    public static function usingVCS()
    {
        static $vcs;

        if (! $vcs) {
            $vcs = @is_dir(realpath(__DIR__ . '/../..') . '/.git');
        }

        return $vcs;
    }

    /**
     * Whether the object cache drop-in file exists.
     *
     * @return bool
     */
    public function dropinExists()
    {
        global $wp_filesystem;

        if (! \WP_Filesystem()) {
            return file_exists(WP_CONTENT_DIR . '/object-cache.php');
        }

        return $wp_filesystem->exists(WP_CONTENT_DIR . '/object-cache.php');
    }

    /**
     * Whether the object cache drop-in is valid.
     *
     * @return bool
     */
    public function dropinIsValid()
    {
        $plugin = $this->pluginMetadata();
        $dropin = $this->dropinMetadata();

        $isValid = $dropin['PluginURI'] === $plugin['PluginURI'];

        $isValid = \apply_filters_deprecated(
            'rediscache_validate_dropin',
            [$isValid, $dropin, $plugin],
            '1.14.0',
            'objectcache_validate_dropin'
        );

        /**
         * Filter the drop-in validation result.
         *
         * @param  bool  $is_valid  Whether the drop-in is valid.
         * @param  array  $dropin  The drop-in metadata.
         * @param  array  $plugin  The plugin metadata.
         */
        return \apply_filters(
            'objectcache_validate_dropin',
            $isValid,
            $dropin,
            $plugin
        );
    }

    /**
     * Whether the object cache drop-in is up-to-date.
     *
     * @return bool
     */
    public function dropinIsUpToDate()
    {
        $plugin = $this->pluginMetadata();
        $dropin = $this->dropinMetadata();

        $upToDate = version_compare($dropin['Version'], $plugin['Version'], '>=');

        $upToDate = \apply_filters_deprecated(
            'rediscache_validate_dropin_version',
            [$upToDate, $dropin, $plugin],
            '1.14.0',
            'objectcache_validate_dropin_version'
        );

        /**
         * Filter the drop-in version check result.
         *
         * @param  bool  $is_uptodate  Whether the drop-in is up-to-date.
         * @param  array  $dropin  The drop-in metadata.
         * @param  array  $plugin  The plugin metadata.
         */
        return \apply_filters(
            'objectcache_validate_dropin_version',
            $upToDate,
            $dropin,
            $plugin
        );
    }

    /**
     * Test whether the filesystem access can be obtained and is working.
     *
     * @return WP_Error|true
     */
    public function filesystemAccess()
    {
        global $wp_filesystem;

        if (! WP_Filesystem()) {
            return new WP_Error('fs', 'Failed to obtain filesystem access.');
        }

        $stub = realpath(__DIR__ . '/../../stubs/object-cache.php');
        $temp = WP_CONTENT_DIR . '/.object-cache-test.tmp';

        if (! $wp_filesystem->exists($stub)) {
            return new WP_Error('fs', 'Stub file does not exist');
        }

        if (! $wp_filesystem->is_writable(WP_CONTENT_DIR)) {
            return new WP_Error('fs', 'Unable to write to content directory');
        }

        if ($wp_filesystem->exists($temp)) {
            if (! $wp_filesystem->delete($temp)) {
                return new WP_Error('fs', 'Unable to delete existing test file');
            }
        }

        if (! $wp_filesystem->copy($stub, $temp, true, FS_CHMOD_FILE)) {
            return new WP_Error('fs', 'Failed to copy test file');
        }

        if (! $wp_filesystem->exists($temp)) {
            return new WP_Error('fs', 'Unable to verify existence of copied test file');
        }

        if (! $wp_filesystem->is_readable($temp)) {
            return new WP_Error('fs', 'Unable to read copied test file');
        }

        if ($wp_filesystem->size($stub) !== $wp_filesystem->size($temp)) {
            return new WP_Error('fs', 'Size of copied test file does not match');
        }

        if (! $wp_filesystem->delete($temp)) {
            return new WP_Error('fs', 'Unable to delete copied test file');
        }

        return true;
    }

    /**
     * May return the environment type.
     *
     * @return string|null
     */
    public static function environment()
    {
        if (function_exists('wp_get_environment_type')) {
            return wp_get_environment_type();
        }

        if (defined('WP_ENV')) {
            return \WP_ENV;
        }
    }

    /**
     * May return the name of the hosting provider.
     *
     * @return string|null
     */
    public static function host()
    {
        if (isset($_ENV['PANTHEON_ENVIRONMENT']) || defined('PANTHEON_ENVIRONMENT')) {
            return 'pantheon';
        }

        if (isset($_SERVER['cw_allowed_ip'])) {
            return 'cloudways';
        }

        if (defined('KINSTAMU_VERSION')) {
            return 'kinsta';
        }

        if (defined('PAGELYBIN') && constant('PAGELYBIN')) {
            return 'pagely';
        }

        if (defined('IS_PRESSABLE') && constant('IS_PRESSABLE')) {
            return 'pressable';
        }

        if (defined('CONVESIO_VER')) {
            return 'convesio';
        }

        if (defined('FLYWHEEL_PLUGIN_DIR')) {
            return 'flywheel';
        }

        if (getenv('SPINUPWP_CACHE_PATH')) {
            return 'spinupwp';
        }

        if (class_exists('WpeCommon') || getenv('IS_WPE')) {
            return 'wpengine';
        }

        if (defined('WPCOMSH_VERSION') && constant('WPCOMSH_VERSION')) {
            return 'wpcom';
        }

        if (class_exists('\WPaas\Plugin')) {
            return 'godaddy';
        }

        if (isset($_SERVER['DH_USER'])) {
            return 'dreampress';
        }
    }

    /**
     * Parses the given file header once to retrieve plugin metadata.
     *
     * @param  string  $file
     * @return array
     */
    protected function fileMetadata($file)
    {
        static $cache = [];

        $file = realpath($file);

        if (! isset($cache[$file])) {
            $cache[$file] = \get_plugin_data($file, false, false);
        }

        return $cache[$file];
    }

    /**
     * Parses the plugin file's metadata once.
     *
     * @return array
     */
    public function pluginMetadata()
    {
        $file = realpath(__DIR__ . '/../../object-cache-pro.php')
            ?: realpath(__DIR__ . '/../../redis-cache-pro.php');

        return $this->fileMetadata($file);
    }

    /**
     * Parses the drop-in file's metadata once.
     *
     * @return array
     */
    public function dropinMetadata()
    {
        return $this->fileMetadata(WP_CONTENT_DIR . '/object-cache.php');
    }

    /**
     * Returns the diagnostic information.
     *
     * @return array
     */
    public function toArray()
    {
        return [
            self::GENERAL => $this->data[self::GENERAL] ?? null,
            self::ERRORS => $this->data[self::ERRORS] ?? null,
            self::VERSIONS => $this->data[self::VERSIONS] ?? null,
            self::STATISTICS => $this->data[self::STATISTICS] ?? null,
            self::CONFIG => $this->data[self::CONFIG] ?? null,
            self::CONSTANTS => $this->data[self::CONSTANTS] ?? null,
        ];
    }

    /**
     * Interface method to provide accessing diagnostics as array.
     *
     * @param  mixed  $group
     * @return bool
     */
    public function offsetExists($group): bool
    {
        return isset($this->data[$group]);
    }

    /**
     * Interface method to provide accessing diagnostics as array.
     *
     * @param  mixed  $group
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($group)
    {
        return $this->data[$group] ?: null;
    }

    /**
     * Interface method to provide accessing diagnostics as array.
     *
     * @param  mixed  $offset
     * @param  mixed  $value
     *
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        throw new RuntimeException('Diagnostics cannot be set.');
    }

    /**
     * Interface method to provide accessing diagnostics as array.
     *
     * @param  mixed  $offset
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        throw new RuntimeException('Diagnostics cannot be unset.');
    }
}
