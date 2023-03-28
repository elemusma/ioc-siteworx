<?php

declare(strict_types=1);

namespace RedisCachePro\Exceptions;

class RelayOutdatedException extends ObjectCacheException
{
    public function __construct($message = '', $code = 0, $previous = null)
    {
        if (empty($message)) {
            $sapi = PHP_SAPI;
            $version = phpversion('relay');

            $message = implode(' ', [
                'Object Cache Pro requires Relay 0.3.0 or newer.',
                "This environment ({$sapi}) was loaded with Relay {$version}.",
            ]);
        }

        parent::__construct($message, $code, $previous);
    }
}
