<?php
declare(strict_types=1);

/**
 * Общая точка входа для сайта, API, бота и CRM.
 * Загружает настройки и классы. База подключается только когда нужна,
 * поэтому страницы сайта открываются даже без базы.
 */

define('APP_PRIVATE', __DIR__);

spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/lib/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$configFile = getenv('TSZH_CONFIG') ?: __DIR__ . '/config.php';
$config = is_file($configFile) ? require $configFile : require __DIR__ . '/config.example.php';
$config['_configured'] = is_file($configFile);

App::init($config);
