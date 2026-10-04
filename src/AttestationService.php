<?php

namespace GlpiPlugin\Inventoryquality;

use InvalidArgumentException;
use Session;

/**
 * İş sahibi teyidi (DQ-08). Otomatik envanter gelişinden AYRI tutulur: kimin, hangi alanları, hangi değerlerle ve
 * hangi tarihte teyit ettiği kaydedilir. Teyit verisi değiştirmez; yalnız ilgili kuralın yeniden kontrolünü sağlar.
 *
 * Teyit verebilen: varlığın kullanıcısı, teknik sorumlusu, teknik grubunun üyesi ya da "Düzeltme uygula" yetkisi
 * olan kullanıcı (varlığın kurum birimine erişimi olmak şartıyla).
 */
final class AttestationService
{
    public const TABLE = Install::P . 'attestations';

    public static function canAttest(string $itemtype, int $itemsId, int $uid): bool
    {
        if ($uid <= 0 || !AssetAdapter::isSupported($itemtype)) {
            return false;
        }
        $a = AssetAdapter::readOne($itemtype, $itemsId, ['core:users_id', 'core:users_id_tech', 'rel:groups_tech']);
        if ($a === null || !Session::haveAccessToEntity((int) $a['_meta']['entities_id'])) {
            return false;
        }
        if (Rights::has(Rights::APPLY)) {
            return true;
        }
        if ((int) ($a['core:users_id']['value'] ?? 0) === $uid || (int) ($a['core:users_id_tech']['value'] ?? 0) === $uid) {
            return true;
        }
        $groups = (array) ($a['rel:groups_tech']['value'] ?? []);
        return $groups && countElementsInTable('glpi_groups_users', ['groups_id' => $groups, 'users_id' => $uid]) > 0;
    }

    /** @param list<string> $fields */
    public static function attest(string $itemtype, int $itemsId, array $fields, string $comment = ''): int
    {
        global $DB;
        $uid = (int) Session::getLoginUserID();
        if (!self::canAttest($itemtype, $itemsId, $uid)) {
            throw new InvalidArgumentException(__('Bu varlık için teyit yetkiniz yok.', 'inventoryquality'));
        }
        $allowed = RuleTemplates::attestableFields($itemtype);
        $fields = array_values(array_unique(array_intersect(array_map('strval', $fields), array_keys($allowed))));
        sort($fields);
        if (!$fields) {
            throw new InvalidArgumentException(__('Teyit edilecek en az bir alan seçin.', 'inventoryquality'));
        }
        $a = AssetAdapter::readOne($itemtype, $itemsId, $fields);
        $values = [];
        foreach ($fields as $f) {
            $values[$f] = RuleEvaluator::summarize($a[$f] ?? null);
        }
        $DB->insert(self::TABLE, [
            'itemtype' => $itemtype, 'items_id' => $itemsId, 'entities_id' => (int) $a['_meta']['entities_id'],
            'fields' => Db::json($fields), 'field_values' => Db::json($values), 'comment' => mb_substr($comment, 0, 2000),
            'users_id' => $uid, 'date' => Db::now(),
        ]);
        $id = (int) $DB->insertId();
        AuditLog::write('attest', $itemtype, $itemsId, (int) $a['_meta']['entities_id'], null, ['fields' => $fields, 'values' => $values], $comment);
        return $id;
    }

    /** @return list<array<string,mixed>> */
    public static function recent(string $itemtype, int $itemsId, int $limit = 10): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $itemsId], 'ORDER' => 'date DESC', 'LIMIT' => $limit]) as $r) {
            $r['fields_list'] = Db::decode($r['fields']);
            $r['values_list'] = Db::decode($r['field_values']);
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Varlığa uygulanan teyit kuralları (DQ-08) ve gereken alanlar.
     * @return list<array{rule:array<string,mixed>,fields:list<string>,days:int}>
     */
    public static function rulesFor(string $itemtype, array $asset): array
    {
        $out = [];
        foreach (Rule::activeRules($itemtype) as $r) {
            if (($r['template'] ?? '') !== 'attestation' || !ScanRunner::inScope($r, $asset)) {
                continue;
            }
            $out[] = ['rule' => $r, 'fields' => Catalog::attestList((string) $r['v']['def']['target']), 'days' => (int) ($r['v']['def']['assert']['days'] ?? 0)];
        }
        return $out;
    }
}
