<?php
declare(strict_types=1);

/**
 * Каркас CRM: сессия, вход, роли, CSRF, макет страниц.
 * Код страниц — в private/crm/pages, в публичной папке только точки входа.
 */
final class CrmApp
{
    public const IDLE_TIMEOUT = 8 * 3600;       // выход после 8 часов без действий
    private static ?array $user = null;

    /** Точка входа страницы: CrmApp::run('board'). */
    public static function run(string $page, bool $public = false): void
    {
        self::startSession();
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        if (!$public) {
            self::requireLogin();
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            self::checkCsrf();
        }
        require APP_PRIVATE . '/crm/pages/' . $page . '.php';
    }

    // ------------------------------------------------------------ сессия

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = self::isHttps();
        session_name($https ? '__Host-tszh_crm' : 'tszh_crm');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',   // Lax, чтобы кнопка «Открыть в CRM» из Telegram открывала карточку без повторного входа
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        $dir = APP_PRIVATE . '/data/sessions';
        if (is_dir($dir) || @mkdir($dir, 0700, true)) {
            session_save_path($dir);
        }
        session_start();
        if (isset($_SESSION['uid'], $_SESSION['seen']) && time() - $_SESSION['seen'] > self::IDLE_TIMEOUT) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['seen'] = time();
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    /** Вход. Возвращает null при успехе или текст ошибки. */
    public static function login(string $login, string $password): ?string
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $login = mb_strtolower(trim($login));
        if (!RateLimit::hit('login', $ip . '|' . $login, 5, 900) || !RateLimit::hit('login_ip', $ip, 30, 900)) {
            Log::event('crm_login_limited', []);
            return 'Слишком много попыток. Подождите 15 минут.';
        }
        $u = App::db()->one('SELECT * FROM users WHERE login = ? AND active = 1', [$login]);
        // проверяем хеш даже для несуществующего логина — чтобы время ответа не выдавало, есть ли такой пользователь
        $hash = $u['password_hash'] ?? '$2y$10$0000000000000000000000000000000000000000000000000000.';
        if (!password_verify($password, $hash) || !$u) {
            Log::event('crm_login_failed', []);
            return 'Неверный логин или пароль.';
        }
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            App::db()->update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$u['id']]);
        }
        session_regenerate_id(true);
        $_SESSION = ['uid' => (int)$u['id'], 'seen' => time(), 'csrf' => bin2hex(random_bytes(32))];
        App::db()->update('users', ['last_login_at' => Db::ts()], 'id = ?', [$u['id']]);
        Audit::log((int)$u['id'], 'user', (int)$u['id'], 'login');
        return null;
    }

    public static function logout(): void
    {
        if ($u = self::user()) {
            Audit::log((int)$u['id'], 'user', (int)$u['id'], 'logout');
        }
        $_SESSION = [];
        session_regenerate_id(true);
        session_destroy();
    }

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $uid = (int)($_SESSION['uid'] ?? 0);
        if (!$uid) {
            return null;
        }
        $u = App::db()->one('SELECT id, login, name, role, telegram_id, is_on_duty, active FROM users WHERE id = ?', [$uid]);
        if (!$u || !(int)$u['active']) {
            $_SESSION = [];
            return null;
        }
        return self::$user = $u;
    }

    public static function uid(): int
    {
        return (int)(self::user()['id'] ?? 0);
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    public static function requireLogin(): void
    {
        if (!self::user()) {
            $back = $_SERVER['REQUEST_URI'] ?? '/crm/';
            self::redirect('/crm/login.php?back=' . rawurlencode(str_starts_with($back, '/crm/') ? $back : '/crm/'));
        }
    }

    public static function requireAdmin(): void
    {
        if (!self::isAdmin()) {
            self::deny('Раздел доступен только руководителю.');
        }
    }

    public static function deny(string $msg = 'Нет доступа к этой странице.'): never
    {
        http_response_code(403);
        echo self::layout('Нет доступа', '<div class="empty"><h1>Нет доступа</h1><p>' . self::e($msg) . '</p><p><a class="btn" href="/crm/">На канбан</a></p></div>');
        exit;
    }

    // ------------------------------------------------------------ права на сделку

    /** Руководитель видит всё; менеджер — свои сделки и общий входящий поток (этап «Новая»). */
    public static function canView(array $deal): bool
    {
        return self::isAdmin() || (int)$deal['manager_id'] === self::uid() || $deal['stage'] === 'new';
    }

    /** Менять сделку может руководитель или ответственный. Чужую новую заявку менеджер может только взять себе. */
    public static function canEdit(array $deal): bool
    {
        return self::isAdmin() || (int)$deal['manager_id'] === self::uid();
    }

    /** SQL-условие видимости сделок для текущего пользователя (алиас таблицы d). */
    public static function dealScope(): array
    {
        return self::isAdmin() ? ['1=1', []] : ["(d.manager_id = ? OR d.stage = 'new')", [self::uid()]];
    }

    // ------------------------------------------------------------ CSRF

    public static function csrf(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf" value="' . self::csrf() . '">';
    }

    public static function checkCsrf(): void
    {
        $given = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $given)) {
            http_response_code(403);
            Log::event('crm_csrf_failed', []);
            if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'Страница устарела. Обновите её и повторите действие.']);
            } else {
                echo 'Страница устарела. Вернитесь назад, обновите страницу и повторите действие.';
            }
            exit;
        }
    }

    // ------------------------------------------------------------ вывод

    public static function e(mixed $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function redirect(string $url): never
    {
        header('Location: ' . $url, true, 303);
        exit;
    }

    public static function flash(?string $msg = null, string $kind = 'ok'): ?array
    {
        if ($msg !== null) {
            $_SESSION['flash'] = ['msg' => $msg, 'kind' => $kind];
            return null;
        }
        $f = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $f;
    }

    public static function json(array $data, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function dt(mixed $ts, bool $withTime = true): string
    {
        if (!$ts) {
            return '—';
        }
        $d = new DateTimeImmutable($ts);
        return $d->format($withTime ? 'd.m.Y H:i' : 'd.m.Y');
    }

    public static function money(mixed $v): string
    {
        return $v === null || $v === '' ? '—' : number_format((float)$v, 2, ',', ' ') . ' ₽';
    }

    public static function stageLabel(string $s): string
    {
        return Deals::STAGES[$s] ?? $s;
    }

    public static function layout(string $title, string $content, string $active = ''): string
    {
        $u = self::user();
        $nav = '';
        if ($u) {
            $overdue = (int)App::db()->value('SELECT COUNT(*) FROM tasks WHERE done_at IS NULL AND due_at < ? AND user_id = ?', [Db::ts(), $u['id']]);
            $items = [
                'board'   => ['/crm/', 'Канбан'],
                'list'    => ['/crm/deals.php', 'Заявки'],
                'tasks'   => ['/crm/tasks.php', 'Задачи' . ($overdue ? ' <span class="badge" title="Просрочено">' . $overdue . '</span>' : '')],
                'kb'      => ['/crm/kb.php', 'Ответы бота'],
            ];
            if ($u['role'] === 'admin') {
                $items += [
                    'reports'  => ['/crm/reports.php', 'Отчёты'],
                    'users'    => ['/crm/users.php', 'Сотрудники'],
                    'settings' => ['/crm/settings.php', 'Настройки'],
                    'health'   => ['/crm/health.php', 'Состояние'],
                ];
            }
            foreach ($items as $k => [$href, $label]) {
                $nav .= sprintf('<a href="%s"%s>%s</a>', $href, $k === $active ? ' aria-current="page"' : '', $label);
            }
            $nav = '<nav class="nav" aria-label="Разделы CRM">' . $nav . '</nav>'
                . '<div class="who"><a href="/crm/profile.php"' . ($active === 'profile' ? ' aria-current="page"' : '') . '>' . self::e($u['name'])
                . ' · ' . ($u['role'] === 'admin' ? 'руководитель' : 'менеджер') . '</a>'
                . '<form method="post" action="/crm/logout.php">' . self::csrfField() . '<button class="link" type="submit">Выйти</button></form></div>';
        }
        $flash = '';
        if ($f = self::flash()) {
            $flash = '<p class="flash ' . self::e($f['kind']) . '" role="status">' . self::e($f['msg']) . '</p>';
        }
        $csrf = $u ? self::csrf() : '';
        return '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><meta name="csrf" content="' . $csrf . '">'
            . '<title>' . self::e($title) . ' — CRM ТСЖ «Пушкинская 15»</title>'
            . '<link rel="icon" href="/favicon.ico" sizes="any"><link rel="stylesheet" href="/assets/css/crm.css">'
            . '<script src="/assets/js/crm.js" defer></script></head><body>'
            . '<a class="skip" href="#main">Перейти к содержанию</a>'
            . '<header class="bar"><a class="brand" href="/crm/"><span class="plate"><span>П<b>15</b></span></span> CRM</a>' . $nav . '</header>'
            . '<main id="main">' . $flash . $content . '</main></body></html>';
    }
}
