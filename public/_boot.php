<?php
declare(strict_types=1);

// Ищем закрытую папку private/: рядом с публичной папкой или на уровень выше.
$candidates = array_filter([
    getenv('TSZH_PRIVATE') ?: null,
    dirname(__DIR__) . '/private',
    dirname(__DIR__, 2) . '/private',
]);
foreach ($candidates as $dir) {
    if (is_file($dir . '/bootstrap.php')) {
        require $dir . '/bootstrap.php';
        return;
    }
}
http_response_code(500);
exit('Не найдена папка private/. Проверьте, что она лежит рядом с публичной папкой сайта.');
