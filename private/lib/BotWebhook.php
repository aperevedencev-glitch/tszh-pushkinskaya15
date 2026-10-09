<?php
declare(strict_types=1);

/**
 * POST /bot/webhook.php — входящие обновления Telegram.
 * Принимаем только запросы с верным X-Telegram-Bot-Api-Secret-Token.
 * Повторная доставка того же update_id ничего не дублирует.
 */
final class BotWebhook
{
    public static function handle(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo 'POST only';
            return;
        }
        $secret = (string)App::cfg('telegram.webhook_secret', '');
        $given = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
        if ($secret === '' || !hash_equals($secret, $given)) {
            http_response_code(403);
            Log::event('webhook_bad_secret', ['has_header' => $given !== '']);
            echo 'forbidden';
            return;
        }

        $raw = file_get_contents('php://input', false, null, 0, 1048576);
        $update = json_decode((string)$raw, true);
        if (!is_array($update) || !isset($update['update_id'])) {
            http_response_code(400);
            echo 'bad update';
            return;
        }

        try {
            App::db()->insert('tg_updates', ['update_id' => (int)$update['update_id'], 'received_at' => Db::ts()]);
        } catch (PDOException) {
            echo 'duplicate';   // Telegram прислал то же обновление повторно — уже обработано
            return;
        }

        try {
            Bot::handle($update);
        } catch (Throwable $e) {
            // Отвечаем 200, чтобы Telegram не повторял обновление бесконечно; ошибка — в журнале и на /crm/health
            Log::error('bot_update_failed', ['update_id' => (int)$update['update_id'], 'err' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
        }
        echo 'ok';

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        try {
            Telegram::flushOutbox(5);
        } catch (Throwable $e) {
            Log::error('outbox_flush_failed', ['err' => $e->getMessage()]);
        }
    }
}
