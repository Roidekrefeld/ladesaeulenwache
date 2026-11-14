<?php
// Autoloader für den Betrieb ohne Composer. Mit Composer: vendor/autoload.php.
spl_autoload_register(static function (string $class): void {
    foreach (['Ladewache\\Tests\\' => __DIR__ . '/tests/', 'Ladewache\\' => __DIR__ . '/src/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
