<?php
declare(strict_types=1);
/** Выход: только POST с CSRF-токеном (проверен в CrmApp::run). */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    CrmApp::redirect('/crm/');
}
CrmApp::logout();
CrmApp::redirect('/crm/login.php');
