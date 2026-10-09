<?php
declare(strict_types=1);

/** Распределение новых обращений: дежурный, иначе по очереди между активными менеджерами. */
final class Assign
{
    public static function next(): ?int
    {
        $db = App::db();
        $duty = $db->value("SELECT id FROM users WHERE active = 1 AND is_on_duty = 1 AND role = 'manager' ORDER BY id LIMIT 1");
        if ($duty) {
            return (int)$duty;
        }
        $ids = array_map('intval', array_column($db->all("SELECT id FROM users WHERE active = 1 AND role = 'manager' ORDER BY id"), 'id'));
        if (!$ids) {
            $admin = $db->value("SELECT id FROM users WHERE active = 1 AND role = 'admin' ORDER BY id LIMIT 1");
            return $admin ? (int)$admin : null;
        }
        $last = (int)Settings::get('rr_last_user_id', 0);
        $next = $ids[0];
        foreach ($ids as $id) {
            if ($id > $last) {
                $next = $id;
                break;
            }
        }
        Settings::set('rr_last_user_id', $next);
        return $next;
    }
}
