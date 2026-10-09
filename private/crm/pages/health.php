<?php
declare(strict_types=1);
/** Состояние системы: /crm/health — только руководитель. */

CrmApp::requireAdmin();
$e = 'CrmApp::e';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'webhook_set') {
            // Необратимое для бота действие: Telegram начнёт слать сообщения на этот домен. Требуем кодовое слово.
            if (mb_strtoupper(trim((string)($_POST['confirm'] ?? ''))) !== 'ПОДКЛЮЧИТЬ') {
                throw new InvalidArgumentException('Чтобы подключить бота, впишите слово ПОДКЛЮЧИТЬ.');
            }
            Webhook::set(CrmApp::uid());
            CrmApp::flash('Бот подключён: Telegram будет присылать сообщения на ' . Webhook::url());
        } elseif ($action === 'clear_error') {
            Settings::set('last_error', null);
            Audit::log(CrmApp::uid(), 'system', null, 'clear_error');
            CrmApp::flash('Ошибка отмечена как просмотренная.');
        } elseif ($action === 'backup_now') {
            $f = Backup::create();
            Settings::set('backup_last', ['at' => Db::ts(), 'file' => basename($f), 'size' => filesize($f)]);
            Backup::prune();
            Audit::log(CrmApp::uid(), 'system', null, 'backup', ['file' => basename($f)]);
            CrmApp::flash('Бэкап создан: ' . basename($f));
        }
    } catch (InvalidArgumentException $ex) {
        CrmApp::flash($ex->getMessage(), 'err');
    } catch (Throwable $ex) {
        Log::error('health_' . $action . '_failed', ['err' => $ex->getMessage()]);
        CrmApp::flash('Не получилось: ' . Health::scrub($ex->getMessage()), 'err');
    }
    CrmApp::redirect('/crm/health.php');
}

$checks = Health::run();
$overall = Health::overall($checks);
$label = ['ok' => 'в порядке', 'warn' => 'обратите внимание', 'fail' => 'нужно исправить'];
$groups = [];
foreach ($checks as $c) {
    $groups[$c['group']][] = $c;
}
$html = '';
foreach ($groups as $g => $items) {
    $html .= '<section class="card"><h2>' . $e($g) . '</h2><ul class="checks">';
    foreach ($items as $c) {
        $html .= '<li class="check ' . $e($c['status']) . '" data-check="' . $e($c['title']) . '"><span class="dot" aria-hidden="true"></span><div><b>' . $e($c['title'])
            . '</b> <span class="state">' . $e($label[$c['status']]) . '</span><p class="small">' . $e($c['detail']) . '</p></div></li>';
    }
    $html .= '</ul></section>';
}

$hookProblems = Webhook::problems();
$lastError = Settings::get('last_error');
$tools = '<section class="card"><h2>Действия</h2>'
    . '<form method="post" class="form">' . CrmApp::csrfField() . '<input type="hidden" name="action" value="webhook_set">'
    . '<p class="small">Подключить бота: Telegram начнёт присылать сообщения жильцов на <code>' . $e(Webhook::url()) . '</code> с секретным заголовком.'
    . ' Делайте это один раз — после переезда на боевой домен и установки SSL.</p>'
    . ($hookProblems ? '<p class="small err-text">' . $e(implode(' ', $hookProblems)) . '</p>' : '')
    . '<label for="h-confirm">Для подтверждения впишите ПОДКЛЮЧИТЬ</label>'
    . '<input class="inp narrow-wide" id="h-confirm" name="confirm" autocomplete="off"' . ($hookProblems ? ' disabled' : '') . '>'
    . '<button class="btn" type="submit"' . ($hookProblems ? ' disabled' : '') . '>Подключить вебхук</button></form>'
    . '<form method="post" class="row">' . CrmApp::csrfField() . '<input type="hidden" name="action" value="backup_now">'
    . '<button class="btn ghost" type="submit">Сделать бэкап сейчас</button><span class="small muted">Файл появится в private/backups, хранится ' . Backup::keepDays() . ' дней.</span></form>'
    . ($lastError ? '<form method="post" class="row">' . CrmApp::csrfField() . '<input type="hidden" name="action" value="clear_error">'
        . '<button class="btn ghost" type="submit">Отметить ошибку как просмотренную</button></form>' : '')
    . '</section>';

echo CrmApp::layout('Состояние системы', '
<div class="head"><h1>Состояние системы</h1><p class="overall ' . $overall . '" role="status"><span class="dot" aria-hidden="true"></span>Итог: ' . $label[$overall] . '</p></div>
<p class="muted small">Проверено ' . App::now()->format('d.m.Y H:i:s') . '. Секреты на этой странице не показываются.</p>
<div class="health">' . $html . $tools . '</div>', 'health');
