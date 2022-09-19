<?php

declare(strict_types=1);

namespace RedisCachePro\Exceptions;

use Exception;
use Throwable;

class ObjectCacheException extends Exception
{
    public static function from(Throwable $exception)
    {
        if ($exception instanceof self) {
            return $exception;
        }

        return new static(
            $exception->getMessage(),
            $exception->getCode(),
            $exception
        );
    }
}
