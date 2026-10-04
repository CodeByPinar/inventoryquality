<?php

namespace GlpiPlugin\Inventoryquality;

/**
 * Varlık adaptörü — tip/alan kataloğu üzerinden GÜNCEL veriyi okur ve kural değerlendiricisine normalize eder.
 * Varlıkta hiçbir değişiklik yapmaz.
 *
 * Normalize değer:
 *   ['ok' => true,  'type' => <datatype>, 'value' => …, 'ref' => ['exists'=>bool,'active'=>?bool,'label'=>string]]
 *   ['ok' => false, 'error' => <kod>]       okunamadı → kural sonucu UNKNOWN (boş alanla karıştırılmaz)
 *
 * Desteklenen tipler: yalnız testleri geçmiş (doğrulanmış) tipler yönetim ekranında listelenir. 0.1.0: Computer.
 */
final class AssetAdapter
{
    /** Doğrulanmış tipler (aynı testleri geçtikçe eklenir: Monitor, Printer, Phone, NetworkEquipment). */
    public const VALIDATED = ['Computer'];

    /** Tanınan ama henüz doğrulanmamış tipler (yönetim ekranında "doğrulanmadı" olarak görünür). */
    public const CANDIDATES = ['Monitor', 'Printer', 'Phone', 'NetworkEquipment'];

    /** @return list<string> */
    public static function supportedTypes(): array
    {
        return array_values(array_filter(self::VALIDATED, static fn($t) => class_exists($t)));
    }

    public static function isSupported(string $itemtype): bool
    {
        return in_array($itemtype, self::supportedTypes(), true);
    }

    /**
     * Kapsamdaki varlık kimlikleri (çöp kutusundakiler ve şablonlar hariç), kimlik tabanlı sayfalama.
     * @param list<int>|null $entities null = tüm birimler
     * @return list<int>
     */
    public static function listIds(string $itemtype, ?array $entities, int $afterId, int $limit): array
    {
        global $DB;
        $where = ['is_deleted' => 0, 'is_template' => 0, 'id' => ['>', $afterId]];
        if ($entities !== null) {
            if (!$entities) {
                return [];
            }
            $where['entities_id'] = $entities;
        }
        $ids = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => getTableForItemType($itemtype), 'WHERE' => $where, 'ORDER' => 'id ASC', 'LIMIT' => $limit]) as $r) {
            $ids[] = (int) $r['id'];
        }
        return $ids;
    }

    /**
     * @param list<int>    $ids
     * @param list<string> $keys gerekli alan anahtarları
     * @return array<int,array<string,mixed>> items_id → ['_meta' => …, <anahtar> => normalize değer]; bulunmayan varlık yer almaz
     */
    public static function readMany(string $itemtype, array $ids, array $keys): array
    {
        global $DB;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        if (!$ids) {
            return [];
        }
        $cat = Catalog::fields($itemtype);
        $table = getTableForItemType($itemtype);
        $keys = array_values(array_unique($keys));
        if (in_array('virt:responsible', $keys, true)) {
            $keys[] = 'core:users_id_tech';
            $keys[] = 'rel:groups_tech';
        }
        $keys = array_values(array_unique($keys));

        $cols = ['id', 'entities_id', 'is_deleted', 'is_template'];
        foreach (['name', 'states_id'] as $c) {
            if ($DB->fieldExists($table, $c)) {
                $cols[] = $c;
            }
        }
        foreach ($keys as $k) {
            if (isset($cat[$k]) && $cat[$k]['column'] !== '') {
                $cols[] = $cat[$k]['column'];
            }
            if (str_starts_with($k, 'attest:') && Catalog::attestField($itemtype, $k) !== null) {
                $cat[$k] = Catalog::attestField($itemtype, $k);
            }
        }
        $cols = array_values(array_unique($cols));

        $out = [];
        foreach ($DB->request(['SELECT' => $cols, 'FROM' => $table, 'WHERE' => ['id' => $ids]]) as $row) {
            $id = (int) $row['id'];
            $out[$id] = ['_meta' => [
                'entities_id' => (int) $row['entities_id'],
                'name'        => (string) ($row['name'] ?? ''),
                'states_id'   => (int) ($row['states_id'] ?? 0),
                'is_deleted'  => (int) $row['is_deleted'],
                'is_template' => (int) $row['is_template'],
            ]];
            foreach ($keys as $k) {
                $def = $cat[$k] ?? null;
                if ($def === null) {
                    $out[$id][$k] = ['ok' => false, 'error' => 'field_missing'];
                    continue;
                }
                if ($def['column'] === '') {
                    continue; // ilişki / türetilmiş / ajan alanları aşağıda
                }
                $raw = $row[$def['column']] ?? null;
                $out[$id][$k] = match ($def['datatype']) {
                    'fk', 'user' => ['ok' => true, 'type' => $def['datatype'], 'value' => (int) ($raw ?? 0)],
                    'bool'       => ['ok' => true, 'type' => 'bool', 'value' => $raw === null ? null : (bool) (int) $raw],
                    default      => ['ok' => true, 'type' => $def['datatype'], 'value' => $raw === null ? null : (string) $raw],
                };
            }
        }
        if (!$out) {
            return [];
        }
        $found = array_keys($out);

        // Açılır liste / kullanıcı referansları: var mı, aktif mi, etiketi ne?
        $byTable = [];
        foreach ($keys as $k) {
            $def = $cat[$k] ?? null;
            if (!$def || !in_array($def['datatype'], ['fk', 'user'], true)) {
                continue;
            }
            foreach ($found as $id) {
                $v = (int) ($out[$id][$k]['value'] ?? 0);
                if ($v > 0) {
                    $byTable[$def['ref_itemtype']][$v] = true;
                }
            }
        }
        $refs = [];
        foreach ($byTable as $refIt => $set) {
            try {
                $refs[$refIt] = self::refInfo($refIt, array_keys($set));
            } catch (\Throwable $e) {
                $refs[$refIt] = null; // okunamadı
            }
        }
        foreach ($keys as $k) {
            $def = $cat[$k] ?? null;
            if (!$def || !in_array($def['datatype'], ['fk', 'user'], true)) {
                continue;
            }
            foreach ($found as $id) {
                $v = (int) ($out[$id][$k]['value'] ?? 0);
                if ($v <= 0) {
                    $out[$id][$k]['ref'] = ['exists' => false, 'active' => null, 'label' => ''];
                    continue;
                }
                $info = $refs[$def['ref_itemtype']] ?? null;
                if ($info === null) {
                    $out[$id][$k] = ['ok' => false, 'error' => 'ref_unreadable'];
                    continue;
                }
                $out[$id][$k]['ref'] = $info[$v] ?? ['exists' => false, 'active' => null, 'label' => ''];
            }
        }

        // Gruplar (tür 1 normal, tür 2 teknik).
        if (array_intersect($keys, ['rel:groups', 'rel:groups_tech'])) {
            $g = [];
            try {
                $gids = [];
                foreach ($DB->request(['SELECT' => ['items_id', 'groups_id', 'type'], 'FROM' => 'glpi_groups_items', 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $found]]) as $r) {
                    $g[(int) $r['items_id']][(int) $r['type']][] = (int) $r['groups_id'];
                    $gids[(int) $r['groups_id']] = true;
                }
                $exist = [];
                if ($gids) {
                    foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_groups', 'WHERE' => ['id' => array_keys($gids)]]) as $r) {
                        $exist[(int) $r['id']] = true;
                    }
                }
                foreach ($found as $id) {
                    foreach (['rel:groups' => 1, 'rel:groups_tech' => 2] as $k => $type) {
                        if (in_array($k, $keys, true)) {
                            $list = $g[$id][$type] ?? [];
                            $out[$id][$k] = ['ok' => true, 'type' => 'multi', 'value' => array_values(array_filter($list, static fn($x) => isset($exist[$x]))),
                                'missing' => array_values(array_filter($list, static fn($x) => !isset($exist[$x])))];
                        }
                    }
                }
            } catch (\Throwable $e) {
                foreach ($found as $id) {
                    foreach (['rel:groups', 'rel:groups_tech'] as $k) {
                        if (in_array($k, $keys, true)) {
                            $out[$id][$k] = ['ok' => false, 'error' => 'relation_unreadable'];
                        }
                    }
                }
            }
        }

        // Sorumlu: aktif teknik sorumlu kişi ∪ geçerli teknik gruplar.
        if (in_array('virt:responsible', $keys, true)) {
            foreach ($found as $id) {
                $u = $out[$id]['core:users_id_tech'] ?? ['ok' => false, 'error' => 'field_missing'];
                $gt = $out[$id]['rel:groups_tech'] ?? ['ok' => false, 'error' => 'field_missing'];
                if (!$u['ok'] || !$gt['ok']) {
                    $out[$id]['virt:responsible'] = ['ok' => false, 'error' => !$u['ok'] ? $u['error'] : $gt['error']];
                    continue;
                }
                $list = [];
                if ((int) $u['value'] > 0 && ($u['ref']['active'] ?? false)) {
                    $list[] = 'User:' . (int) $u['value'];
                }
                foreach ($gt['value'] as $gid) {
                    $list[] = 'Group:' . $gid;
                }
                $out[$id]['virt:responsible'] = ['ok' => true, 'type' => 'multi', 'value' => $list];
            }
        }

        // İş sahibi teyidi: istenen alanların hepsini kapsayan en son teyit (otomatik envanter gelişinden ayrı).
        $attestKeys = array_values(array_filter($keys, static fn($k) => str_starts_with($k, 'attest:') && isset($cat[$k])));
        if ($attestKeys) {
            try {
                $att = [];
                foreach ($DB->request(['SELECT' => ['items_id', 'fields', 'date'], 'FROM' => AttestationService::TABLE, 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $found], 'ORDER' => 'date DESC']) as $r) {
                    $att[(int) $r['items_id']][] = ['fields' => Db::decode($r['fields']), 'date' => (string) $r['date']];
                }
                foreach ($attestKeys as $k) {
                    $need = Catalog::attestList($k);
                    foreach ($found as $id) {
                        $date = null;
                        foreach ($att[$id] ?? [] as $a) {
                            if (!array_diff($need, $a['fields'])) {
                                $date = $a['date'];
                                break;
                            }
                        }
                        $out[$id][$k] = ['ok' => true, 'type' => 'date', 'value' => $date];
                    }
                }
            } catch (\Throwable $e) {
                foreach ($attestKeys as $k) {
                    foreach ($found as $id) {
                        $out[$id][$k] = ['ok' => false, 'error' => 'attestation_unreadable'];
                    }
                }
            }
        }

        // Ajanın son bağlantısı (kaydın değiştirilme tarihinden ayrı).
        if (in_array('agent:last_contact', $keys, true)) {
            try {
                $lc = [];
                foreach ($DB->request(['SELECT' => ['items_id', 'last_contact'], 'FROM' => 'glpi_agents', 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $found]]) as $r) {
                    $cur = $lc[(int) $r['items_id']] ?? null;
                    if ($r['last_contact'] !== null && ($cur === null || strcmp((string) $r['last_contact'], $cur) > 0)) {
                        $lc[(int) $r['items_id']] = (string) $r['last_contact'];
                    }
                }
                foreach ($found as $id) {
                    $out[$id]['agent:last_contact'] = ['ok' => true, 'type' => 'date', 'value' => $lc[$id] ?? null];
                }
            } catch (\Throwable $e) {
                foreach ($found as $id) {
                    $out[$id]['agent:last_contact'] = ['ok' => false, 'error' => 'agent_unreadable'];
                }
            }
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function readOne(string $itemtype, int $id, array $keys): ?array
    {
        return self::readMany($itemtype, [$id], $keys)[$id] ?? null;
    }

    /**
     * @param list<int> $ids
     * @return array<int,array{exists:bool,active:?bool,label:string}>
     */
    public static function refInfo(string $refItemtype, array $ids): array
    {
        global $DB;
        $table = getTableForItemType($refItemtype);
        $res = [];
        if ($refItemtype === 'User') {
            $now = Db::now();
            foreach ($DB->request(['SELECT' => ['id', 'name', 'realname', 'firstname', 'is_active', 'is_deleted', 'begin_date', 'end_date'], 'FROM' => $table, 'WHERE' => ['id' => $ids]]) as $r) {
                $active = (int) $r['is_active'] === 1 && (int) $r['is_deleted'] === 0
                    && ($r['end_date'] === null || strcmp((string) $r['end_date'], $now) > 0)
                    && ($r['begin_date'] === null || strcmp((string) $r['begin_date'], $now) <= 0);
                $label = trim(((string) $r['firstname']) . ' ' . ((string) $r['realname']));
                $res[(int) $r['id']] = ['exists' => true, 'active' => $active, 'label' => $label !== '' ? $label . ' (' . $r['name'] . ')' : (string) $r['name']];
            }
            return $res;
        }
        $cols = ['id'];
        foreach (['completename', 'name', 'is_deleted'] as $c) {
            if ($DB->fieldExists($table, $c)) {
                $cols[] = $c;
            }
        }
        foreach ($DB->request(['SELECT' => $cols, 'FROM' => $table, 'WHERE' => ['id' => $ids]]) as $r) {
            $deleted = isset($r['is_deleted']) && (int) $r['is_deleted'] === 1;
            $res[(int) $r['id']] = ['exists' => !$deleted, 'active' => null, 'label' => (string) ($r['completename'] ?? $r['name'] ?? ('#' . $r['id']))];
        }
        return $res;
    }

    public static function label(string $itemtype, int $id): string
    {
        global $DB;
        if (!class_exists($itemtype)) {
            return $itemtype . ' #' . $id;
        }
        $r = $DB->request(['SELECT' => ['name'], 'FROM' => getTableForItemType($itemtype), 'WHERE' => ['id' => $id]])->current();
        $n = trim((string) ($r['name'] ?? ''));
        return $n !== '' ? $n : ($itemtype::getTypeName(1) . ' #' . $id);
    }
}
