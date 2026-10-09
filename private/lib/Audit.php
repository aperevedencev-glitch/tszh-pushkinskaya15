<?php
declare(strict_types=1);

/** Журнал изменений: кто, когда, что. Без персональных данных в details. */
final class Audit
{
    public static function log(?int $userId, string $entity, ?int $entityId, string $action, array $details = []): void
    {
        App::db()->insert('audit_log', [
            'user_id'    => $userId,
            'entity'     => $entity,
            'entity_id'  => $entityId,
            'action'     => $action,
            'details'    => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            'created_at' => Db::ts(),
        ]);
    }
}
