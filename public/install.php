<?php
declare(strict_types=1);
// Мастер первой установки. После успешной установки блокируется и пытается удалить этот файл.
define('INSTALL_SCRIPT', __FILE__);
require __DIR__ . '/_boot.php';
CrmApp::run('install', true);
