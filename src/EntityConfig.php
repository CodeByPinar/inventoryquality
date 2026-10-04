<?php

namespace GlpiPlugin\Inventoryquality;

/**
 * Kurum birimi (entity) ayarları. Satırı olmayan birim en yakın üst birimin ayarını devralır.
 *
 *   groups_id_quality     "Envanter Veri Kalitesi" destek grubu (atama önceliğinde 3. sıra)
 *   ticket_mode           off = yalnız bulgu (varsayılan; ilk pilotta bulgu hacmi görülür) | grouped = destek kaydı aç
 *   users_id_requester    destek kaydının talep sahibi (servis kimliği; 0 = yok)
 *   due_days              kuralda süre yoksa hedef süre (gün)
 *   closed_ticket_policy  new_ticket = erken kapatılan kayıt yerine yeni takip kaydı
 */
final class EntityConfig
{
    public const TABLE = Install::P . 'entityconfigs';

    /** @var array<string,mixed> */
    public const DEFAULTS = [
        'groups_id_quality'    => 0,
        'ticket_mode'          => 'off',
        'users_id_requester'   => 0,
        'due_days'             => 14,
        'closed_ticket_policy' => 'new_ticket',
    ];

    public const TICKET_MODES = ['off', 'grouped'];

    /** @var array<int,array<string,mixed>> */
    private static array $cache = [];

    public static function reset(): void
    {
        self::$cache = [];
    }

    /** @return array<string,mixed> etkin ayar + 'source_entities_id' (ayarın geldiği birim; -1 = varsayılan) */
    public static function effective(int $entitiesId): array
    {
        if (isset(self::$cache[$entitiesId])) {
            return self::$cache[$entitiesId];
        }
        global $DB;
        $seen = [];
        $eid = $entitiesId;
        $res = self::DEFAULTS + ['source_entities_id' => -1];
        while ($eid >= 0 && !isset($seen[$eid])) {
            $seen[$eid] = true;
            $row = Db::row(self::TABLE, ['entities_id' => $eid]);
            if ($row) {
                $res = array_intersect_key($row, self::DEFAULTS) + self::DEFAULTS;
                $res['source_entities_id'] = $eid;
                break;
            }
            if ($eid === 0) {
                break;
            }
            $parent = $DB->request(['SELECT' => ['entities_id'], 'FROM' => 'glpi_entities', 'WHERE' => ['id' => $eid]])->current();
            $eid = $parent ? (int) $parent['entities_id'] : -1;
        }
        foreach (['groups_id_quality', 'users_id_requester', 'due_days'] as $k) {
            $res[$k] = (int) $res[$k];
        }
        return self::$cache[$entitiesId] = $res;
    }

    /** @return array<string,mixed>|null birimin KENDİ satırı (devralma yok) */
    public static function own(int $entitiesId): ?array
    {
        return Db::row(self::TABLE, ['entities_id' => $entitiesId]);
    }

    /** @param array<string,mixed> $input */
    public static function save(int $entitiesId, array $input): void
    {
        $vals = [
            'groups_id_quality'    => max(0, (int) ($input['groups_id_quality'] ?? 0)),
            'ticket_mode'          => in_array($input['ticket_mode'] ?? '', self::TICKET_MODES, true) ? $input['ticket_mode'] : 'off',
            'users_id_requester'   => max(0, (int) ($input['users_id_requester'] ?? 0)),
            'due_days'             => min(365, max(1, (int) ($input['due_days'] ?? 14))),
            'closed_ticket_policy' => 'new_ticket',
        ];
        $before = self::own($entitiesId);
        Db::upsert(self::TABLE, ['entities_id' => $entitiesId] + $vals + ['date_mod' => Db::now()], array_merge(array_keys($vals), ['date_mod']));
        AuditLog::write('config_save', 'Entity', $entitiesId, $entitiesId, $before, $vals);
        self::reset();
    }

    public static function inherit(int $entitiesId): void
    {
        global $DB;
        $before = self::own($entitiesId);
        if ($before) {
            $DB->delete(self::TABLE, ['entities_id' => $entitiesId]);
            AuditLog::write('config_inherit', 'Entity', $entitiesId, $entitiesId, $before, null);
        }
        self::reset();
    }
}
