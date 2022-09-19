<?php

defined('ABSPATH') || exit;

spl_autoload_register(function ($fqcn) {
    if (strpos($fqcn, 'RedisCachePro\\') === 0) {
        require_once str_replace(['\\', 'RedisCachePro/'], ['/', __DIR__ . '/src/'], $fqcn) . '.php';
    }
});
