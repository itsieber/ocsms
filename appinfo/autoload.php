<?php

declare(strict_types=1);

// Register autoloader for OCA\OcSms namespace
spl_autoload_register(function ($className) {
    $prefix = 'OCA\\Ocsms\\';
    $baseDir = __DIR__ . '/../lib/';

    $len = strlen($prefix);
    if (strncmp($prefix, $className, $len) !== 0) {
        return;
    }

    $relativeClass = substr($className, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
