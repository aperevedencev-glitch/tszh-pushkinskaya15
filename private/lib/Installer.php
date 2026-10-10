<?php
declare(strict_types=1);

/**
 * Первая установка: проверки окружения, таблицы, стартовые ответы бота, первый руководитель.
 * После успешной установки создаётся private/data/install.lock — повторно мастер не откроется.
 */
final class Installer
{
    public static function lockFile(): string
    {
        return (string)App::cfg('install_lock', APP_PRIVATE . '/data/install.lock');   // путь меняется только в тестах
    }

    /** Установка уже выполнена: есть файл-замок или в базе уже есть сотрудники. */
    public static function locked(): bool
    {
        if (is_file(self::lockFile())) {
            return true;
        }
        try {
            return (int)App::db()->value('SELECT COUNT(*) FROM users') > 0;
        } catch (Throwable) {
            return false;   // таблиц ещё нет
        }
    }

    /** Проверки перед установкой: [title, ok, detail, blocking]. */
    public static function checks(): array
    {
        $c = [];
        $c[] = ['PHP 8.1 или новее', PHP_VERSION_ID >= 80100, 'Сейчас ' . PHP_VERSION . '. Версия переключается в панели хостинга → «Сайты» → настройки PHP.', true];
        $driver = (string)App::cfg('db.driver', 'mysql');
        foreach (['pdo', $driver === 'sqlite' ? 'pdo_sqlite' : 'pdo_mysql', 'curl', 'mbstring', 'zlib'] as $ext) {
            $c[] = ["Расширение PHP $ext", extension_loaded($ext), 'Включите расширение в настройках PHP хостинга.', true];
        }
        $c[] = ['Файл private/config.php', App::configured(), 'Скопируйте private/config.example.php в private/config.php и впишите доступ к базе и токен бота.', true];
        $c[] = ['Ключ установки (install_key в config.php)', strlen((string)App::cfg('install_key', '')) >= 8,
            'Впишите в config.php строку install_key — любую фразу от 8 символов. Её нужно будет ввести ниже: так мастер не сможет запустить посторонний.', true];
        foreach (['data', 'logs', 'backups'] as $d) {
            $c[] = ["Запись в private/$d", is_dir(APP_PRIVATE . "/$d") && is_writable(APP_PRIVATE . "/$d"), 'Выставьте права 700 на папку в файловом менеджере.', true];
        }
        $dbOk = false;
        $dbMsg = '';
        try {
            App::db()->value('SELECT 1');
            $dbOk = true;
            $dbMsg = 'Подключение есть.';
        } catch (Throwable $e) {
            $dbMsg = 'Нет подключения к базе. Проверьте db.name, db.user и db.pass в config.php (имя базы и пользователя — как в панели Reg.ru, с префиксом u1234567_).';
        }
        $c[] = ['База данных', $dbOk, $dbMsg, true];
        $c[] = ['Секрет подписи ссылок (app_secret)', strlen((string)App::cfg('app_secret', '')) >= 32 || self::canFill('app_secret'),
            'Впишите в config.php app_secret — 64 случайных символа, или оставьте пустые кавычки: мастер заполнит сам.', true];
        $c[] = ['Секрет вебхука (telegram.webhook_secret)', Webhook::secretValid((string)App::cfg('telegram.webhook_secret', '')) || self::canFill('webhook_secret'),
            'Оставьте пустые кавычки — мастер заполнит сам.', true];
        $c[] = ['Токен бота', Telegram::configured(), 'Можно вписать позже: без токена сайт и CRM работают, бот — нет.', false];
        $c[] = ['Адрес сайта с https://', str_starts_with(App::siteUrl(), 'https://') && !str_contains(App::siteUrl(), 'ВАШ-ДОМЕН'),
            'Впишите site_url после установки SSL-сертификата. Нужен для бота и ссылок.', false];
        return $c;
    }

    public static function ready(): bool
    {
        foreach (self::checks() as [, $ok, , $blocking]) {
            if ($blocking && !$ok) {
                return false;
            }
        }
        return true;
    }

    /** Лимит попыток мастера: 5 за 15 минут с одного адреса. Файл — потому что таблиц до установки нет. */
    public static function attempt(string $ip, int $max = 5, int $window = 900): bool
    {
        $file = dirname(self::lockFile()) . '/install_attempts.json';
        $fh = @fopen($file, 'c+');
        if (!$fh) {
            return true;   // нет прав на запись — проверка «Запись в private/data» уже покажет проблему
        }
        flock($fh, LOCK_EX);
        $data = json_decode((string)stream_get_contents($fh), true) ?: [];
        $key = hash('sha256', $ip);
        $now = time();
        $hits = array_values(array_filter((array)($data[$key] ?? []), static fn($t) => $t > $now - $window));
        $ok = count($hits) < $max;
        if ($ok) {
            $hits[] = $now;
        }
        $data = array_filter($data, static fn($v) => max((array)$v ?: [0]) > $now - $window);
        $data[$key] = $hits;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data));
        flock($fh, LOCK_UN);
        fclose($fh);
        @chmod($file, 0600);
        return $ok;
    }

    public static function keyMatches(string $given): bool
    {
        $key = (string)App::cfg('install_key', '');
        return strlen($key) >= 8 && hash_equals($key, $given);
    }

    /** Установка. Возвращает id руководителя. */
    public static function install(string $login, string $name, string $password): int
    {
        if (self::locked()) {
            throw new RuntimeException('Установка уже выполнена.');
        }
        $login = mb_strtolower(trim($login));
        $name = trim($name);
        if (!preg_match('/^[a-z0-9._-]{3,32}$/', $login)) {
            throw new InvalidArgumentException('Логин: 3–32 символа, латинские буквы, цифры, точка, дефис, подчёркивание.');
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('Укажите имя руководителя.');
        }
        if ($err = Passwords::weak($password, $login)) {
            throw new InvalidArgumentException($err);
        }
        self::fillSecrets();
        $db = App::db();
        Schema::create($db);
        $id = $db->tx(static function () use ($db, $login, $name, $password) {
            if ((int)$db->value('SELECT COUNT(*) FROM users') > 0) {
                throw new RuntimeException('Установка уже выполнена.');
            }
            $id = $db->insert('users', ['login' => $login, 'name' => $name, 'role' => 'admin', 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'created_at' => Db::ts()]);
            Kb::seed($id);
            if (Settings::get('holidays') === null) {
                Settings::set('holidays', []);
                Settings::set('workdays', []);
            }
            Settings::set('installed_at', Db::ts());
            Audit::log($id, 'system', null, 'install', ['version' => App::cfg('policy_version')]);
            return $id;
        });
        file_put_contents(self::lockFile(), 'installed ' . date('c') . "\n", LOCK_EX);
        @chmod(self::lockFile(), 0600);
        Log::event('installed', []);
        return $id;
    }

    // ------------------------------------------------------------ секреты

    /** В config.php ключ записан пустыми кавычками — мастер может его заполнить. */
    public static function canFill(string $key, ?string $file = null): bool
    {
        $file ??= APP_PRIVATE . '/config.php';
        return is_file($file) && is_writable($file) && preg_match(self::pattern($key), (string)file_get_contents($file)) === 1;
    }

    /**
     * Вписывает случайные app_secret и webhook_secret в config.php, если там пустые кавычки.
     * Значения нигде не показываются. Возвращает список заполненных ключей.
     */
    public static function fillSecrets(?string $file = null): array
    {
        $file ??= APP_PRIVATE . '/config.php';
        if (!is_file($file)) {
            return [];
        }
        $src = (string)file_get_contents($file);
        $done = [];
        foreach (['app_secret' => 32, 'webhook_secret' => 24] as $key => $bytes) {
            if (preg_match(self::pattern($key), $src)) {
                $value = $key === 'app_secret' ? bin2hex(random_bytes($bytes)) : rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
                $src = preg_replace(self::pattern($key), '${1}' . $value . '${2}', $src, 1);
                $done[] = $key;
            }
        }
        if ($done) {
            if (file_put_contents($file, $src, LOCK_EX) === false) {
                throw new RuntimeException('Не удалось записать секреты в config.php — заполните app_secret и webhook_secret вручную.');
            }
            @chmod($file, 0600);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }
            $config = require $file;
            $config['_configured'] = true;
            App::init($config);
            Log::event('secrets_generated', ['keys' => implode(',', $done)]);
        }
        return $done;
    }

    private static function pattern(string $key): string
    {
        return "/^(\\s*'" . preg_quote($key, '/') . "'\\s*=>\\s*')('\\s*,)/m";
    }
}
