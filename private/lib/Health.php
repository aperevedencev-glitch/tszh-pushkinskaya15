<?php
declare(strict_types=1);

/**
 * Проверки для страницы /crm/health. Каждая проверка независима:
 * упала база — остальные всё равно показываются. Секреты не выводятся никогда.
 */
final class Health
{
    /** @return array<int, array{group:string,title:string,status:string,detail:string}> */
    public static function run(bool $callTelegram = true): array
    {
        $out = [];
        $add = static function (string $group, string $title, callable $fn) use (&$out): void {
            try {
                [$status, $detail] = $fn();
            } catch (Throwable $e) {
                $status = 'fail';
                $detail = 'Ошибка проверки: ' . self::scrub($e->getMessage());
            }
            $out[] = compact('group', 'title', 'status', 'detail');
        };

        // ---------- сервер
        $add('Сервер', 'Версия PHP', static fn() => PHP_VERSION_ID >= 80100
            ? ['ok', PHP_VERSION]
            : ['fail', PHP_VERSION . ' — нужна 8.1 или новее. Переключите версию в панели хостинга.']);
        $add('Сервер', 'Расширения PHP', static function () {
            $need = ['pdo', 'curl', 'mbstring', 'json', 'zlib', App::cfg('db.driver', 'mysql') === 'sqlite' ? 'pdo_sqlite' : 'pdo_mysql'];
            $miss = array_values(array_filter($need, static fn($e) => !extension_loaded($e)));
            return $miss ? ['fail', 'Не хватает: ' . implode(', ', $miss)] : ['ok', implode(', ', $need)];
        });
        $add('Сервер', 'HTTPS', static fn() => CrmApp::isHttps()
            ? ['ok', 'Страница открыта по защищённому соединению.']
            : ['warn', 'Страница открыта без HTTPS. Установите SSL-сертификат и откройте CRM по https://.']);
        $add('Сервер', 'Закрытая папка вне сайта', static function () {
            $root = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '';
            $priv = realpath(APP_PRIVATE) ?: APP_PRIVATE;
            if ($root === '') {
                return ['warn', 'Не удалось определить публичную папку.'];
            }
            return str_starts_with($priv . '/', rtrim($root, '/') . '/')
                ? ['fail', 'Папка private лежит внутри публичной папки сайта. Перенесите её на уровень выше.']
                : ['ok', 'Настройки, база и бэкапы недоступны из интернета.'];
        });
        $add('Сервер', 'Папки для записи', static function () {
            $bad = [];
            foreach (['data', 'logs', 'backups'] as $d) {
                if (!is_dir(APP_PRIVATE . "/$d") || !is_writable(APP_PRIVATE . "/$d")) {
                    $bad[] = "private/$d";
                }
            }
            $free = @disk_free_space(APP_PRIVATE);
            $freeTxt = $free !== false ? ' Свободно на диске: ' . self::bytes((float)$free) . '.' : '';
            return $bad ? ['fail', 'Нет прав на запись: ' . implode(', ', $bad) . '.' . $freeTxt] : ['ok', 'data, logs, backups — запись разрешена.' . $freeTxt];
        });

        // ---------- настройки
        $add('Настройки', 'Файл config.php', static fn() => App::configured()
            ? ['ok', 'Найден в закрытой папке.']
            : ['fail', 'Нет private/config.php — работают настройки-образец.']);
        $add('Настройки', 'Адрес сайта', static function () {
            $u = App::siteUrl();
            if ($u === '' || str_contains($u, 'ВАШ-ДОМЕН')) {
                return ['fail', 'В site_url не вписан домен.'];
            }
            return str_starts_with($u, 'https://') ? ['ok', $u] : ['warn', $u . ' — после установки SSL поменяйте на https://'];
        });
        $add('Настройки', 'Секреты', static function () {
            $p = [];
            if (strlen((string)App::cfg('app_secret', '')) < 32) {
                $p[] = 'app_secret короче 32 символов';
            }
            if (!Webhook::secretValid((string)App::cfg('telegram.webhook_secret', ''))) {
                $p[] = 'webhook_secret пустой или неверного формата';
            }
            if (!Telegram::configured()) {
                $p[] = 'не вписан токен бота';
            }
            return $p ? ['fail', ucfirst(implode('; ', $p)) . '.'] : ['ok', 'Заданы (значения не показываются).'];
        });
        $add('Настройки', 'Мастер установки', static function () {
            $file = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) . '/install.php';
            if (!Installer::locked()) {
                return ['fail', 'Установка не завершена.'];
            }
            return is_file($file) ? ['warn', 'Установка заблокирована, но файл install.php ещё лежит на сайте — удалите его.'] : ['ok', 'Установка завершена, install.php удалён.'];
        });

        // ---------- база
        $add('База данных', 'Подключение', static function () {
            $db = App::db();
            $ver = (string)$db->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            return ['ok', ($db->driver === 'mysql' ? 'MySQL/MariaDB ' : 'SQLite ') . $ver];
        });
        $add('База данных', 'Таблицы', static function () {
            $db = App::db();
            $have = $db->driver === 'mysql'
                ? $db->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)
                : $db->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
            $miss = array_diff(Backup::tables(), $have);
            if ($miss) {
                return ['fail', 'Нет таблиц: ' . implode(', ', $miss) . '. Запустите установку.'];
            }
            return ['ok', sprintf('Все %d на месте. Заявок: %d, контактов: %d, сотрудников: %d.', count(Backup::tables()),
                $db->value('SELECT COUNT(*) FROM deals'), $db->value('SELECT COUNT(*) FROM contacts WHERE anonymized_at IS NULL'),
                $db->value('SELECT COUNT(*) FROM users WHERE active = 1'))];
        });

        // ---------- Telegram
        $add('Telegram', 'Вебхук бота', static function () use ($callTelegram) {
            if ($p = Webhook::problems()) {
                return ['fail', implode(' ', $p)];
            }
            if (!$callTelegram) {
                return ['warn', 'Проверка не выполнялась.'];
            }
            return Webhook::assess(Webhook::info());
        });
        $add('Telegram', 'Очередь уведомлений', static function () {
            $db = App::db();
            $pending = (int)$db->value('SELECT COUNT(*) FROM outbox WHERE sent_at IS NULL AND failed_at IS NULL');
            $oldest = $db->value('SELECT MIN(created_at) FROM outbox WHERE sent_at IS NULL AND failed_at IS NULL');
            $failed = (int)$db->value('SELECT COUNT(*) FROM outbox WHERE failed_at >= ?', [Db::ts(App::now()->modify('-1 day'))]);
            $txt = "Ждут отправки: $pending. Не доставлено за сутки: $failed.";
            if ($oldest && strtotime((string)$oldest) < App::now()->getTimestamp() - 900) {
                return ['fail', $txt . ' Старейшее ждёт с ' . CrmApp::dt($oldest) . ' — проверьте связь с Telegram и cron.'];
            }
            return [$failed ? 'warn' : 'ok', $txt . ($failed ? ' Обычно это люди, которые заблокировали бота.' : '')];
        });
        $add('Telegram', 'Сотрудники получают уведомления', static function () {
            $n = App::db()->all('SELECT name FROM users WHERE active = 1 AND telegram_id IS NULL');
            return $n ? ['warn', 'Не привязали Telegram: ' . implode(', ', array_column($n, 'name')) . '. Код привязки — в профиле CRM.'] : ['ok', 'Все активные сотрудники привязали Telegram.'];
        });

        // ---------- фоновые задачи
        $add('Фоновые задачи', 'Cron', static function () {
            $last = Settings::get('cron_last_run');
            if (!$last) {
                return ['fail', 'Cron ещё ни разу не запускался. Добавьте задание в панели хостинга (см. инструкцию).'];
            }
            $age = App::now()->getTimestamp() - strtotime((string)$last);
            $txt = 'Последний запуск: ' . CrmApp::dt($last) . ' (' . self::ago($age) . ').';
            return [$age <= 180 ? 'ok' : ($age <= 900 ? 'warn' : 'fail'), $txt . ($age > 180 ? ' Должен запускаться каждую минуту.' : '')];
        });
        $add('Фоновые задачи', 'Бэкап базы', static function () {
            $list = Backup::list();
            if (!$list) {
                return ['fail', 'Бэкапов нет. Первый появится после 03:00, если работает cron.'];
            }
            $b = $list[0];
            $age = App::now()->getTimestamp() - strtotime($b['at']);
            $txt = sprintf('Последний: %s, %s. Всего %d за %d дн., хранятся %d дней.', CrmApp::dt($b['at']), self::bytes($b['size']),
                count($list), count(array_unique(array_column($list, 'date'))), Backup::keepDays());
            return [$age <= 26 * 3600 ? 'ok' : 'fail', $txt . ($age > 26 * 3600 ? ' Бэкап старше суток!' : '')];
        });
        $add('Фоновые задачи', 'Календарь праздников', static function () {
            $y = App::now()->format('Y');
            $h = (array)Settings::get('holidays', []);
            $thisYear = count(array_filter($h, static fn($d) => str_starts_with((string)$d, $y)));
            $next = count(array_filter($h, static fn($d) => str_starts_with((string)$d, (string)($y + 1))));
            if (!$thisYear) {
                return ['warn', "Праздники на $y год не заданы — в праздники заявки будут считаться рабочими. Настройки → Календарь."];
            }
            if (!$next && (int)App::now()->format('n') >= 11) {
                return ['warn', 'Задайте праздники на ' . ($y + 1) . ' год.'];
            }
            return ['ok', "На $y год: $thisYear дн." . ($next ? ' На ' . ($y + 1) . " год: $next дн." : '')];
        });

        // ---------- ошибки
        $add('Ошибки', 'Последняя ошибка', static function () {
            $e = Settings::get('last_error');
            if (!$e) {
                return ['ok', 'Ошибок не было.'];
            }
            $age = App::now()->getTimestamp() - strtotime((string)$e['at']);
            $ctx = array_diff_key((array)($e['ctx'] ?? []), ['trace' => 1]);
            $detail = CrmApp::dt($e['at']) . ' — ' . $e['event'] . ($ctx ? ': ' . self::scrub(json_encode($ctx, JSON_UNESCAPED_UNICODE)) : '');
            return [$age < 86400 ? 'warn' : 'ok', $detail];
        });
        return $out;
    }

    /** Итог по всем проверкам. */
    public static function overall(array $checks): string
    {
        $s = array_column($checks, 'status');
        return in_array('fail', $s, true) ? 'fail' : (in_array('warn', $s, true) ? 'warn' : 'ok');
    }

    /** Убирает из текста ошибок всё, похожее на секреты: токен бота, длинные ключи, пароли из DSN. */
    public static function scrub(string $s): string
    {
        return mb_substr(Log::scrubSecrets($s), 0, 400);
    }

    public static function bytes(float $b): string
    {
        foreach (['Б', 'КБ', 'МБ', 'ГБ'] as $u) {
            if ($b < 1024 || $u === 'ГБ') {
                return ($u === 'Б' ? (string)(int)$b : number_format($b, 1, ',', ' ')) . ' ' . $u;
            }
            $b /= 1024;
        }
        return '';
    }

    private static function ago(int $sec): string
    {
        return match (true) {
            $sec < 120 => 'только что',
            $sec < 7200 => intdiv($sec, 60) . ' мин назад',
            $sec < 172800 => intdiv($sec, 3600) . ' ч назад',
            default => intdiv($sec, 86400) . ' дн назад',
        };
    }
}
