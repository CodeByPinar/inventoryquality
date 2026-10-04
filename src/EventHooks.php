<?php

namespace GlpiPlugin\Inventoryquality;

use CommonDBTM;

/**
 * GLPI olayları. Kullanıcı kaydederken tarama YAPILMAZ: yalnız ilgili kayıt yeniden kontrol kuyruğuna alınır
 * (art arda olaylar tek işte birleşir). Olay işleyicisi asla hata fırlatmaz (kullanıcının kaydını bozmasın);
 * kaçırılan olayları gece tam taraması yakalar.
 */
final class EventHooks
{
    public static function onAsset(CommonDBTM $item): void
    {
        self::safe(static function () use ($item): void {
            $type = $item->getType();
            if (!AssetAdapter::isSupported($type) || (int) ($item->fields['is_template'] ?? 0) === 1) {
                return;
            }
            $id = (int) $item->getID();
            if ($id > 0) {
                JobQueue::enqueue('recheck', "recheck:$type:$id", ['itemtype' => $type, 'items_id' => $id]);
            }
        });
    }

    public static function onGroupItem(CommonDBTM $gi): void
    {
        self::safe(static function () use ($gi): void {
            $type = (string) ($gi->fields['itemtype'] ?? '');
            $id = (int) ($gi->fields['items_id'] ?? 0);
            if ($id > 0 && AssetAdapter::isSupported($type)) {
                JobQueue::enqueue('recheck', "recheck:$type:$id", ['itemtype' => $type, 'items_id' => $id]);
            }
        });
    }

    /** Kullanıcının aktifliği değişti → bağlı varlıklar (DQ-03, sorumlu) yeniden kontrol edilir. */
    public static function onUser(CommonDBTM $u): void
    {
        self::safe(static function () use ($u): void {
            if (array_intersect((array) ($u->updates ?? []), ['is_active', 'is_deleted', 'begin_date', 'end_date'])) {
                $uid = (int) $u->getID();
                JobQueue::enqueue('user_change', "user_change:$uid", ['users_id' => $uid]);
            }
        });
    }

    /** Bağlı destek kaydı çözüldü / kapandı / silindi → eşitleme (erken kapanma politikası). */
    public static function onTicket(CommonDBTM $t): void
    {
        self::safe(static function () use ($t): void {
            $tid = (int) $t->getID();
            if ($tid <= 0 || countElementsInTable(TicketBridge::LINKS, ['tickets_id' => $tid, 'is_open' => 1]) === 0) {
                return;
            }
            if (isset($t->updates) && is_array($t->updates) && $t->updates && !in_array('status', $t->updates, true) && !in_array('is_deleted', $t->updates, true)) {
                return;
            }
            JobQueue::enqueue('ticket_check', "ticket_check:$tid", ['tickets_id' => $tid]);
        });
    }

    public static function enqueueAssetsOfUser(int $uid): void
    {
        global $DB;
        foreach (AssetAdapter::supportedTypes() as $type) {
            $table = getTableForItemType($type);
            $or = [];
            foreach (['users_id', 'users_id_tech'] as $c) {
                if ($DB->fieldExists($table, $c)) {
                    $or[] = [$c => $uid];
                }
            }
            if (!$or) {
                continue;
            }
            foreach ($DB->request(['SELECT' => ['id'], 'FROM' => $table, 'WHERE' => ['is_template' => 0, 'OR' => $or], 'LIMIT' => 1000]) as $r) {
                JobQueue::enqueue('recheck', "recheck:$type:" . (int) $r['id'], ['itemtype' => $type, 'items_id' => (int) $r['id']]);
            }
        }
    }

    private static function safe(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            error_log('inventoryquality olay hatası: ' . $e->getMessage());
        }
    }
}
