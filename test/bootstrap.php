<?php

require dirname(__DIR__) . '/vendor/autoload.php';

// Host tests use explicit contracts for the Omeka/Laminas runtime, which is
// supplied by Omeka in production rather than by this module's dependencies.
spl_autoload_register(static function (string $class): void {
    if ($class === 'OERManager\\Module') {
        require dirname(__DIR__) . '/Module.php';
        return;
    }
    $file = __DIR__ . '/stubs/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
