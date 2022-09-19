<?php

declare(strict_types=1);

namespace RedisCachePro\Exceptions;

class PhpRedisOutdatedException extends ObjectCacheException
{
    public function __construct($message = '', $code = 0, $previous = null)
    {
        if (empty($message)) {
            $sapi = PHP_SAPI;
            $version = phpversion('redis');

            $message = implode(' ', [
                'Object Cache Pro requires PhpRedis 3.1.1 or newer.',
                "This environment ({$sapi}) was loaded with PhpRedis {$version}.",
            ]);
        }

        parent::__construct($message, $code, $previous);
    }
}
