<?php
declare(strict_types=1);

/**
 * POST /api/lead.php — заявка с сайта.
 * fetch (Accept: application/json) → JSON; обычная отправка формы → HTML-страница.
 */
final class LeadEndpoint
{
    private const MAX = ['name' => 100, 'phone' => 30, 'telegram' => 64, 'comment' => 2000];

    public static function handle(): void
    {
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        $json = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            self::out($json, ['ok' => false, 'error' => 'Отправьте форму на странице сайта.']);
            return;
        }

        $p = static fn(string $k) => trim((string)($_POST[$k] ?? ''));

        // Ловушки для ботов: скрытое поле и слишком быстрая отправка. Ботам отвечаем «успехом», ничего не сохраняя.
        $elapsed = $p('elapsed');
        if ($p('website') !== '' || ($elapsed !== '' && (int)$elapsed < 2000)) {
            Log::event('lead_trap', ['reason' => $p('website') !== '' ? 'honeypot' : 'too_fast']);
            self::out($json, ['ok' => true, 'number' => null]);
            return;
        }

        try {
            $limit = (array)App::cfg('lead_rate_limit', ['max' => 5, 'window_sec' => 600]);
            if (!RateLimit::hit('lead', self::ip(), (int)$limit['max'], (int)$limit['window_sec'])) {
                http_response_code(429);
                self::out($json, ['ok' => false, 'error' => 'Слишком много заявок подряд. Подождите 10 минут или позвоните нам.']);
                return;
            }
        } catch (Throwable $e) {
            Log::error('lead_ratelimit_failed', ['err' => $e->getMessage()]);
        }

        $errors = [];
        $name = mb_substr($p('name'), 0, self::MAX['name']);
        if (mb_strlen($name) < 2) {
            $errors['name'] = 'Напишите, как к вам обращаться.';
        }
        $phoneRaw = mb_substr($p('phone'), 0, self::MAX['phone']);
        $tgRaw = mb_substr($p('telegram'), 0, self::MAX['telegram']);
        $phone = $phoneRaw !== '' ? Phone::normalize($phoneRaw) : null;
        $tg = $tgRaw !== '' ? Phone::telegramUsername($tgRaw) : null;
        if ($phoneRaw !== '' && !$phone) {
            $errors['phone'] = 'Проверьте номер: нужен российский мобильный или городской, 10 цифр после +7.';
        }
        if ($tgRaw !== '' && !$tg) {
            $errors['telegram'] = 'Ник в Telegram — латиница, цифры и _, от 5 символов. Например: @ivan_petrov.';
        }
        if ($phoneRaw === '' && $tgRaw === '') {
            $errors['phone'] = 'Укажите телефон или ник в Telegram — иначе мы не сможем ответить.';
        }
        $comment = mb_substr($p('comment'), 0, self::MAX['comment']);
        if ($p('consent') !== 'yes') {
            $errors['consent'] = 'Без согласия на обработку данных мы не можем сохранить заявку.';
        }
        if ($errors) {
            http_response_code(422);
            self::out($json, ['ok' => false, 'errors' => $errors]);
            return;
        }

        try {
            $r = Leads::capture([
                'channel'           => 'site',
                'name'              => $name,
                'phone'             => $phone,
                'telegram_username' => $tg,
                'text'              => $comment,
                'consent'           => ['ip' => self::ip()],
                'source'            => [
                    'channel'      => 'site_form',
                    'utm_source'   => $p('utm_source'),
                    'utm_medium'   => $p('utm_medium'),
                    'utm_campaign' => $p('utm_campaign'),
                    'utm_content'  => $p('utm_content'),
                    'utm_term'     => $p('utm_term'),
                    'referrer'     => self::cleanUrl($p('referrer')),
                    'landing_page' => self::cleanUrl($p('landing_page'), true),
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('lead_save_failed', ['err' => $e->getMessage()]);
            http_response_code(500);
            self::out($json, ['ok' => false, 'error' => 'Не получилось сохранить заявку. Попробуйте ещё раз через минуту или позвоните нам.']);
            return;
        }

        Log::event('lead_saved', ['deal' => $r['number'], 'created' => $r['created'], 'duplicate' => $r['duplicate']]);
        self::out($json, [
            'ok'       => true,
            'number'   => $r['number'],
            'existing' => !$r['created'],
            // ссылка, привязывающая Telegram к заявке, — только новому человеку. Если телефон уже был в базе,
            // форму мог заполнить кто угодно, знающий номер: даём обычную ссылку на бота без привязки.
            'telegram' => !empty($r['contact_created'])
                ? Deeplink::botUrl(Deeplink::forDeal($r['number']))
                : Deeplink::botUrl(Deeplink::campaignTag($p('utm_campaign') ?: 'form')),
        ]);

        // Ответ уже у пользователя — теперь пробуем отправить уведомление менеджеру.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        if (class_exists('Telegram') && method_exists('Telegram', 'flushOutbox')) {
            try {
                Telegram::flushOutbox(5);
            } catch (Throwable $e) {
                Log::error('outbox_flush_failed', ['err' => $e->getMessage()]);
            }
        }
    }

    private static function ip(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /** Оставляем только хост и путь: в query-строках чужих сайтов бывают персональные данные. */
    private static function cleanUrl(string $u, bool $keepUtm = false): ?string
    {
        if ($u === '') {
            return null;
        }
        $parts = parse_url($u);
        if (!$parts) {
            return null;
        }
        $out = isset($parts['host']) ? ($parts['host'] . ($parts['path'] ?? '')) : ($parts['path'] ?? '');
        if ($keepUtm && !empty($parts['query'])) {
            parse_str($parts['query'], $q);
            $utm = array_intersect_key($q, array_flip(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term']));
            if ($utm) {
                $out .= '?' . http_build_query($utm);
            }
        }
        return mb_substr($out, 0, 255);
    }

    private static function out(bool $json, array $data): void
    {
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($data, JSON_UNESCAPED_UNICODE);
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        $common = Page::common();
        if (!empty($data['ok']) && !empty($data['number'])) {
            $title = 'Заявка ' . $data['number'] . ' принята';
            $body = '<p>Номер заявки — <b class="num">' . htmlspecialchars($data['number']) . '</b>. Менеджер свяжется с вами в рабочее время: '
                . htmlspecialchars($common['work_hours']) . '.</p>'
                . '<p><a class="btn" href="' . htmlspecialchars($data['telegram']) . '">Продолжить в Telegram</a></p>';
        } elseif (!empty($data['ok'])) {
            $title = 'Спасибо!';
            $body = '<p>Мы получили ваше сообщение.</p>';
        } else {
            $title = 'Заявка не отправлена';
            $items = $data['errors'] ?? ['error' => $data['error'] ?? 'Что-то пошло не так.'];
            $body = '<ul class="errors">' . implode('', array_map(static fn($e) => '<li>' . htmlspecialchars($e) . '</li>', $items)) . '</ul>'
                . '<p><a class="btn" href="/#zayavka">Вернуться к форме</a></p>';
        }
        echo Page::render('result', $common + ['title' => $title, 'body' => $body]);
    }
}
