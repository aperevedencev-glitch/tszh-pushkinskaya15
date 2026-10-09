<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';
header('Content-Type: text/html; charset=utf-8');
echo Page::render('landing', Page::common());
