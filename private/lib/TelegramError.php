<?php
declare(strict_types=1);

final class TelegramError extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpCode = 0, public readonly int $retryAfter = 0)
    {
        parent::__construct($message, $httpCode);
    }

    /** Ошибки, при которых повтор бессмыслен: бот заблокирован, чат не найден, неверный запрос. */
    public function isPermanent(): bool
    {
        return in_array($this->httpCode, [400, 401, 403, 404], true);
    }
}
