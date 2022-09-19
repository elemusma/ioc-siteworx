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

namespace RedisCachePro;

use WP_Error;

class License
{
    /**
     * The license is valid.
     *
     * @var string
     */
    const Valid = 'valid';

    /**
     * The license was canceled.
     *
     * @var string
     */
    const Canceled = 'canceled';

    /**
     * The license is unpaid.
     *
     * @var string
     */
    const Unpaid = 'unpaid';

    /**
     * The license is invalid.
     *
     * @var string
     */
    const Invalid = 'invalid';

    /**
     * The license was deauthorized.
     *
     * @var string
     */
    const Deauthorized = 'deauthorized';

    /**
     * The license plan.
     *
     * @var string
     */
    protected $plan;

    /**
     * The license plan.
     *
     * @var string
     */
    protected $state;

    /**
     * The license plan.
     *
     * @var string
     */
    protected $token;

    /**
     * The license plan.
     *
     * @var array
     */
    protected $organization;

    /**
     * The last time the license was checked.
     *
     * @var int
     */
    protected $last_check;

    /**
     * The last time the license was verified.
     *
     * @var int
     */
    protected $valid_as_of;

    /**
     * The last error associated with the license.
     *
     * @var \WP_Error
     */
    protected $_error;

    /**
     * The license token.
     *
     * @return bool
     */
    public function token()
    {
        return $this->token;
    }

    /**
     * The license state.
     *
     * @return string
     */
    public function state()
    {
        return $this->state;
    }

    /**
     * Whether the license is valid.
     *
     * @return bool
     */
    public function isValid()
    {
        return $this->state === self::Valid;
    }

    /**
     * Whether the license was canceled.
     *
     * @return bool
     */
    public function isCanceled()
    {
        return $this->state === self::Canceled;
    }

    /**
     * Whether the license is unpaid.
     *
     * @return bool
     */
    public function isUnpaid()
    {
        return $this->state === self::Unpaid;
    }

    /**
     * Whether the license is invalid.
     *
     * @return bool
     */
    public function isInvalid()
    {
        return $this->state === self::Invalid;
    }

    /**
     * Whether the license was deauthorized.
     *
     * @return bool
     */
    public function isDeauthorized()
    {
        return $this->state === self::Deauthorized;
    }

    /**
     * Load the plugin's license from the database.
     *
     * @return self|null
     */
    public static function load()
    {
        $license = get_site_option('rediscache_license');

        if (
            is_object($license) &&
            property_exists($license, 'token') &&
            property_exists($license, 'state') &&
            property_exists($license, 'last_check')
        ) {
            return static::fromObject($license);
        }
    }

    /**
     * Transform the license into a generic object.
     *
     * @return object
     */
    protected function toObject()
    {
        return (object) [
            'plan' => $this->plan,
            'state' => $this->state,
            'token' => $this->token,
            'organization' => $this->organization,
            'last_check' => $this->last_check,
            'valid_as_of' => $this->valid_as_of,
        ];
    }

    /**
     * Instanciate a new license from the given generic object.
     *
     * @param  object  $object
     * @return self
     */
    public static function fromObject($object)
    {
        $license = new self;

        foreach (get_object_vars($object) as $key => $value) {
            property_exists($license, $key) && $license->{$key} = $value;
        }

        return $license;
    }

    /**
     * Instanciate a new license from the given response object.
     *
     * @param  object  $response
     * @return self
     */
    public static function fromResponse($response)
    {
        $license = static::fromObject($response);
        $license->last_check = current_time('timestamp');

        if ($license->isValid()) {
            $license->valid_as_of = current_time('timestamp');
        }

        if (is_null($license->state)) {
            $license->state = self::Invalid;
        }

        return $license->save();
    }

    /**
     * Instanciate a new license from the given response object.
     *
     * @param  object  $response
     * @return self
     */
    public static function fromError(WP_Error $error)
    {
        $license = new self;

        foreach ($error->get_error_data() as $key => $value) {
            property_exists($license, $key) && $license->{$key} = $value;
        }

        $license->_error = $error;
        $license->last_check = current_time('timestamp');

        error_log('objectcache.warning: ' . $error->get_error_message());

        return $license->save();
    }

    /**
     * Persist the license as a network option.
     *
     * @return self
     */
    public function save()
    {
        update_site_option('rediscache_license', $this->toObject());

        return $this;
    }

    /**
     * Deauthorize the license.
     *
     * @return self
     */
    public function deauthorize()
    {
        $this->valid_as_of = null;
        $this->state = self::Deauthorized;

        return $this->save();
    }

    /**
     * Bump the `last_check` timestamp on the license.
     *
     * @return self
     */
    public function checkFailed(WP_Error $error)
    {
        $this->_error = $error;
        $this->last_check = current_time('timestamp');

        error_log('objectcache.notice: ' . $error->get_error_message());

        return $this->save();
    }

    /**
     * Whether it's been given minutes since the last check.
     *
     * @return bool
     */
    public function minutesSinceLastCheck(int $minutes)
    {
        if (! $this->last_check) {
            delete_site_option('rediscache_license_last_check');

            return true;
        }

        $validUntil = $this->last_check + ($minutes * MINUTE_IN_SECONDS);

        return $validUntil < current_time('timestamp');
    }

    /**
     * Whether it's been given hours since the license was successfully verified.
     *
     * @return bool
     */
    public function hoursSinceVerification(int $hours)
    {
        if (! $this->valid_as_of) {
            return true;
        }

        $validUntil = $this->valid_as_of + ($hours * HOUR_IN_SECONDS);

        return $validUntil < current_time('timestamp');
    }
}
