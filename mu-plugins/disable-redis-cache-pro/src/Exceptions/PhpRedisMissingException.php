<?php

declare(strict_types=1);

namespace RedisCachePro\Exceptions;

class PhpRedisMissingException extends ObjectCacheException
{
    public function __construct($message = '', $code = 0, $previous = null)
    {
        if (empty($message)) {
            $sapi = PHP_SAPI;

            $message = implode(' ', [
                'Object Cache Pro requires the PHP extension PhpRedis 3.1.1 or newer.',
                "The PhpRedis extension is not loaded in this environment ({$sapi}).",
                'If it was installed, be sure to load the extension in your php.ini and to restart your PHP and web server processes.',
            ]);
        }

        parent::__construct($message, $code, $previous);
    }
}
