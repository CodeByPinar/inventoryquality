<?php

namespace GlpiPlugin\Inventoryquality;

use Profile_User;

/**
 * Düzeltme işinin sorumlusu (öncelik sırasıyla):
 *   1. Kuralda tanımlı aktif ve yetkili grup / kişi
 *   2. Varlığın teknik grubu / aktif teknik sorumlusu
 *   3. Birimin "Envanter Veri Kalitesi" destek grubu (EntityConfig)
 *   4. Hiçbiri yoksa "Atama bekliyor" kuyruğu (yönetici uyarısı Genel Bakış'ta)
 *
 * Kullanıcı: aktif, silinmemiş, geçerlilik tarihleri içinde ve varlığın biriminde profili olan. Grup: var,
 * atanabilir (is_assign) ve varlığın biriminden görünür. Pasif kullanıcı hiçbir zaman atanmaz (DQ-03 bulgusu
 * dahil — pasif kişiye bağlı varlığın işi o kişiye gitmez).
 */
final class AssignmentResolver
{
    /**
     * @param array<string,mixed> $policy kural politikası (groups_id, users_id)
     * @param array<string,mixed> $asset  AssetAdapter verisi (core:users_id_tech, rel:groups_tech varsa)
     * @return array{users_id:int,groups_id:int,state:string,reason:string}
     */
    public static function resolve(int $entitiesId, array $policy, array $asset): array
    {
        $g = (int) ($policy['groups_id'] ?? 0);
        if ($g > 0 && self::validGroup($g, $entitiesId)) {
            return ['users_id' => 0, 'groups_id' => $g, 'state' => 'assigned', 'reason' => 'rule_group'];
        }
        $u = (int) ($policy['users_id'] ?? 0);
        if ($u > 0 && self::validUser($u, $entitiesId)) {
            return ['users_id' => $u, 'groups_id' => 0, 'state' => 'assigned', 'reason' => 'rule_user'];
        }
        foreach ((array) ($asset['rel:groups_tech']['value'] ?? []) as $gid) {
            if (self::validGroup((int) $gid, $entitiesId)) {
                return ['users_id' => 0, 'groups_id' => (int) $gid, 'state' => 'assigned', 'reason' => 'asset_tech_group'];
            }
        }
        $tech = (int) ($asset['core:users_id_tech']['value'] ?? 0);
        if ($tech > 0 && self::validUser($tech, $entitiesId)) {
            return ['users_id' => $tech, 'groups_id' => 0, 'state' => 'assigned', 'reason' => 'asset_tech_user'];
        }
        $eg = (int) EntityConfig::effective($entitiesId)['groups_id_quality'];
        if ($eg > 0 && self::validGroup($eg, $entitiesId)) {
            return ['users_id' => 0, 'groups_id' => $eg, 'state' => 'assigned', 'reason' => 'entity_group'];
        }
        return ['users_id' => 0, 'groups_id' => 0, 'state' => 'waiting', 'reason' => 'none'];
    }

    public static function validUser(int $usersId, int $entitiesId): bool
    {
        if ($usersId <= 0) {
            return false;
        }
        $info = AssetAdapter::refInfo('User', [$usersId])[$usersId] ?? null;
        if (!$info || !$info['exists'] || $info['active'] !== true) {
            return false;
        }
        return in_array($entitiesId, array_map('intval', Profile_User::getUserEntities($usersId, true)), true);
    }

    public static function validGroup(int $groupsId, int $entitiesId): bool
    {
        if ($groupsId <= 0) {
            return false;
        }
        $g = Db::row('glpi_groups', ['id' => $groupsId]);
        if (!$g || (int) $g['is_assign'] !== 1) {
            return false;
        }
        $ge = (int) $g['entities_id'];
        if ($ge === $entitiesId) {
            return true;
        }
        return (int) $g['is_recursive'] === 1 && in_array($ge, array_map('intval', getAncestorsOf('glpi_entities', $entitiesId)), true);
    }

    public static function reasonLabel(string $r): string
    {
        return match ($r) {
            'rule_group'       => __('Kuralda tanımlı grup', 'inventoryquality'),
            'rule_user'        => __('Kuralda tanımlı kişi', 'inventoryquality'),
            'asset_tech_group' => __('Varlığın teknik grubu', 'inventoryquality'),
            'asset_tech_user'  => __('Varlığın teknik sorumlusu', 'inventoryquality'),
            'entity_group'     => __('Birimin veri kalitesi grubu', 'inventoryquality'),
            'manual'           => __('Elle atandı', 'inventoryquality'),
            'taken'            => __('Üstlenildi', 'inventoryquality'),
            default            => __('Atama bekliyor', 'inventoryquality'),
        };
    }
}
