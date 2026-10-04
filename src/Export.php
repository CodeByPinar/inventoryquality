<?php

namespace GlpiPlugin\Inventoryquality;

/**
 * Güvenli CSV dışa aktarma ("Rapor dışa aktar" yetkisi). Kurum birimi kısıtından geçer. Formül olarak
 * yorumlanabilecek hücreler (=, +, -, @, sekme, satır başı ile başlayan) başına tek tırnak eklenerek yazılır.
 * Parola, erişim anahtarı ya da gereksiz kişisel bilgi yoktur: kişiler yalnız adıyla, mevcut durum maskelenmiş özetle.
 */
final class Export
{
    public static function safeCell(mixed $v): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", (string) ($v ?? ''));
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\n"], true)) {
            $s = "'" . $s;
        }
        return $s;
    }

    /** @param list<string> $cells */
    public static function line(array $cells): string
    {
        $out = [];
        foreach ($cells as $c) {
            $c = self::safeCell($c);
            $out[] = '"' . str_replace('"', '""', $c) . '"';
        }
        return implode(';', $out) . "\r\n";
    }

    /**
     * Bulgular CSV'si (oturumun etkin birimleri; isteğe bağlı durum süzgeci).
     * @param list<string> $statuses boş = tümü
     */
    public static function findingsCsv(array $statuses = []): string
    {
        global $DB;
        $T = Finding::getTable();
        $where = getEntitiesRestrictCriteria($T, 'entities_id', '', false);
        if ($statuses) {
            $where[$T . '.status'] = $statuses;
        }
        $csv = "\xEF\xBB\xBF" . self::line([
            __('Bulgu', 'inventoryquality'), __('Kurum birimi', 'inventoryquality'), __('Varlık tipi', 'inventoryquality'), __('Varlık', 'inventoryquality'),
            __('Kural kodu', 'inventoryquality'), __('Kural', 'inventoryquality'), __('Durum', 'inventoryquality'), __('Önem', 'inventoryquality'),
            __('Sorumlu grup', 'inventoryquality'), __('Sorumlu kişi', 'inventoryquality'), __('Hedef tarih', 'inventoryquality'),
            __('İlk görülme', 'inventoryquality'), __('Son kontrol', 'inventoryquality'), __('Son sonuç', 'inventoryquality'),
            __('Dönem', 'inventoryquality'), __('Mevcut durum', 'inventoryquality'), __('Durum nedeni', 'inventoryquality'),
        ]);
        $rules = [];
        foreach ($DB->request(['FROM' => $T, 'WHERE' => $where, 'ORDER' => 'id']) as $f) {
            $rid = (int) $f['rules_id'];
            $rules[$rid] ??= Db::row(Rule::getTable(), ['id' => $rid]) ?? ['code' => '', 'name' => ''];
            $it = (string) $f['itemtype'];
            $obs = [];
            foreach ((array) (Db::decode($f['detail'])['observed'] ?? []) as $k => $s) {
                $obs[] = (Catalog::get($it, (string) $k)['label'] ?? $k) . ': ' . $s;
            }
            $csv .= self::line([
                (string) $f['id'], (string) \Dropdown::getDropdownName('glpi_entities', (int) $f['entities_id']),
                class_exists($it) ? $it::getTypeName(1) : $it, AssetAdapter::label($it, (int) $f['items_id']),
                (string) $rules[$rid]['code'], (string) $rules[$rid]['name'], Finding::statusLabel((string) $f['status']),
                Finding::severityLabel((int) $f['severity']),
                (int) $f['groups_id_assign'] > 0 ? (string) \Dropdown::getDropdownName('glpi_groups', (int) $f['groups_id_assign']) : '',
                (int) $f['users_id_assign'] > 0 ? (string) getUserName((int) $f['users_id_assign']) : '',
                (string) $f['due_date'], (string) $f['first_seen'], (string) $f['last_check'], (string) $f['last_result'],
                (string) $f['cycle'], implode('; ', $obs), (string) $f['status_reason'],
            ]);
        }
        return $csv;
    }
}
