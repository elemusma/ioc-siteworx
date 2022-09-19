<?php

declare(strict_types=1);

namespace RedisCachePro\Exceptions;

class RelayMissingException extends ObjectCacheException
{
    public function __construct($message = '', $code = 0, $previous = null)
    {
        if (empty($message)) {
            $sapi = PHP_SAPI;

            $message = implode(' ', [
                "The Relay extension for PHP is not loaded in this environment ({$sapi}).",
                'If it was installed, be sure to load the extension in your php.ini and to restart your PHP and web server processes.',
            ]);
        }

        parent::__construct($message, $code, $previous);
    }
}
