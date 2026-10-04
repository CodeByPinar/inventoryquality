<?php

namespace GlpiPlugin\Inventoryquality;

use Session;

/**
 * Denetim izi. İşlemi yapan kullanıcı (users_id) ile servis kimliği (actor) ayrı yazılır; sistem işleri
 * "cron:…" kimliğiyle kaydedilir — başka bir kullanıcı yapmış gibi gösterilmez. Kayıtlar arayüzden düzenlenmez.
 */
final class AuditLog
{
    public const TABLE = Install::P . 'auditlogs';

    /** Şu anki servis kimliği (cron içinde "cron:<görev>", aksi halde "user"). */
    public static string $actor = 'user';

    public static function write(string $action, string $itemtype, int $itemsId, int $entitiesId, mixed $before = null, mixed $after = null, string $reason = ''): void
    {
        global $DB;
        // users_id: işlemi yapan / başlatan kullanıcı (cron'da 0); actor: "user" ya da servis kimliği.
        $uid = (int) Session::getLoginUserID(false);
        $DB->insert(self::TABLE, [
            'users_id'    => max(0, $uid),
            'actor'       => self::$actor,
            'itemtype'    => $itemtype,
            'items_id'    => $itemsId,
            'entities_id' => $entitiesId,
            'action'      => $action,
            'before'      => $before === null ? null : Db::json($before),
            'after'       => $after === null ? null : Db::json($after),
            'reason'      => mb_substr($reason, 0, 2000),
            'date'        => Db::now(),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function forObject(string $itemtype, int $itemsId, int $limit = 50): array
    {
        global $DB;
        return iterator_to_array($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $itemsId],
            'ORDER' => 'id DESC',
            'LIMIT' => $limit,
        ]), false);
    }

    /** "Yapan" sütunu: kullanıcı işlemiyse kullanıcı adı, servis işlemiyse servis kimliği (+ başlatan kullanıcı). */
    public static function actorLabel(array $row): string
    {
        $u = (int) $row['users_id'] > 0 ? (string) getUserName((int) $row['users_id']) : '';
        if ($row['actor'] === 'user') {
            return $u;
        }
        return (string) $row['actor'] . ($u !== '' ? ' · ' . sprintf(__('başlatan: %s', 'inventoryquality'), $u) : '');
    }

    /** Kod içinde servis kimliğiyle çalıştır (cron, kuyruk, elle başlatılan tarama / yeniden kontrol). */
    public static function as(string $actor, callable $fn): mixed
    {
        $prev = self::$actor;
        self::$actor = $actor;
        try {
            return $fn();
        } finally {
            self::$actor = $prev;
        }
    }
}
