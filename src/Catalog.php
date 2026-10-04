<?php

namespace GlpiPlugin\Inventoryquality;

/**
 * Alan kataloğu — kurallarda kullanılabilecek alanlar, KARARLI anahtarlarla:
 *   core:<kolon>      varlık tablosundaki çekirdek kolon (metin, açılır liste kimliği, tarih, evet/hayır)
 *   rel:groups        varlığın grupları (glpi_groups_items, tür 1)
 *   rel:groups_tech   varlığın teknik grupları (glpi_groups_items, tür 2)
 *   virt:responsible  sorumlu = aktif teknik sorumlu kişi ∪ geçerli teknik grup (salt okunur, türetilmiş)
 *   agent:last_contact ajanın son bağlantısı (glpi_agents; GLPI kaydının değiştirilme tarihinden ayrı)
 *
 * Katalog hedef GLPI sürümünde KEŞFEDİLİR: kolonu olmayan alan listelenmez. Sabit tablo adı yerine GLPI'nin
 * itemtype → tablo eşlemesi kullanılır. Etiket değişse de anahtar korunur; alan kaybolursa ya da veri tipi
 * değişirse bu alanı kullanan kural askıya alınır (Rule::validateAll).
 */
final class Catalog
{
    public const TABLE = Install::P . 'fieldmaps';

    public const OPS = [
        'string' => ['empty', 'not_empty', 'eq', 'neq', 'in', 'not_in'],
        'text'   => ['empty', 'not_empty'],
        'fk'     => ['empty', 'not_empty', 'eq', 'neq', 'in', 'not_in', 'ref_exists'],
        'user'   => ['empty', 'not_empty', 'eq', 'neq', 'in', 'not_in', 'ref_exists', 'ref_active'],
        'date'   => ['empty', 'not_empty', 'older_than_days', 'within_days'],
        'bool'   => ['eq', 'neq'],
        'multi'  => ['empty', 'not_empty'],
    ];

    /** @var array<string,array<string,array<string,mixed>>> */
    private static array $cache = [];

    public static function reset(): void
    {
        self::$cache = [];
    }

    /**
     * @return array<string,array{key:string,label:string,datatype:string,column:string,ref_itemtype:string,ref_table:string,writable:bool,ops:list<string>}>
     */
    public static function fields(string $itemtype): array
    {
        if (isset(self::$cache[$itemtype])) {
            return self::$cache[$itemtype];
        }
        global $DB;
        $out = [];
        if (!class_exists($itemtype) || !is_subclass_of($itemtype, \CommonDBTM::class)) {
            return self::$cache[$itemtype] = [];
        }
        $table = getTableForItemType($itemtype);
        if (!$DB->tableExists($table)) {
            return self::$cache[$itemtype] = [];
        }
        $cols = $DB->listFields($table);
        $lower = strtolower($itemtype);

        $add = static function (string $key, string $label, string $dt, string $col = '', string $refIt = '', bool $w = false) use (&$out): void {
            $refTable = $refIt !== '' && class_exists($refIt) ? getTableForItemType($refIt) : '';
            $out[$key] = [
                'key' => $key, 'label' => $label, 'datatype' => $dt, 'column' => $col,
                'ref_itemtype' => $refIt, 'ref_table' => $refTable, 'writable' => $w, 'ops' => self::OPS[$dt],
            ];
        };

        $strings = [
            'name'        => __('Ad', 'inventoryquality'),
            'serial'      => __('Seri numarası', 'inventoryquality'),
            'otherserial' => __('Envanter numarası', 'inventoryquality'),
            'contact'     => __('Kişi', 'inventoryquality'),
            'contact_num' => __('Kişi numarası', 'inventoryquality'),
            'uuid'        => __('UUID', 'inventoryquality'),
        ];
        foreach ($strings as $c => $l) {
            if (isset($cols[$c])) {
                $add("core:$c", $l, 'string', $c, '', $c !== 'uuid');
            }
        }
        if (isset($cols['comment'])) {
            $add('core:comment', __('Açıklamalar', 'inventoryquality'), 'text', 'comment', '', true);
        }
        $fks = [
            'locations_id'         => [__('Konum', 'inventoryquality'), 'Location'],
            'states_id'            => [__('Durum', 'inventoryquality'), 'State'],
            'manufacturers_id'     => [__('Üretici', 'inventoryquality'), 'Manufacturer'],
            "{$lower}types_id"     => [__('Tür', 'inventoryquality'), $itemtype . 'Type'],
            "{$lower}models_id"    => [__('Model', 'inventoryquality'), $itemtype . 'Model'],
            'autoupdatesystems_id' => [__('Güncelleme kaynağı', 'inventoryquality'), 'AutoUpdateSystem'],
            'networks_id'          => [__('Ağ', 'inventoryquality'), 'Network'],
        ];
        foreach ($fks as $c => [$l, $refIt]) {
            if (isset($cols[$c]) && class_exists($refIt)) {
                $add("core:$c", $l, 'fk', $c, $refIt, true);
            }
        }
        foreach (['users_id' => __('Kullanıcı', 'inventoryquality'), 'users_id_tech' => __('Teknik sorumlu', 'inventoryquality')] as $c => $l) {
            if (isset($cols[$c])) {
                $add("core:$c", $l, 'user', $c, 'User', true);
            }
        }
        foreach (['last_inventory_update' => __('Son envanter tarihi', 'inventoryquality'), 'last_boot' => __('Son açılış', 'inventoryquality')] as $c => $l) {
            if (isset($cols[$c])) {
                $add("core:$c", $l, 'date', $c);
            }
        }
        if (isset($cols['is_dynamic'])) {
            $add('core:is_dynamic', __('Otomatik envanterden', 'inventoryquality'), 'bool', 'is_dynamic');
        }
        if ($DB->tableExists('glpi_groups_items') && in_array(\Glpi\Features\AssignableItem::class, class_uses($itemtype) ?: [], true)) {
            $add('rel:groups', __('Grup', 'inventoryquality'), 'multi');
            $add('rel:groups_tech', __('Teknik grup', 'inventoryquality'), 'multi');
            if (isset($cols['users_id_tech'])) {
                $add('virt:responsible', __('Sorumlu (aktif teknik sorumlu ya da teknik grup)', 'inventoryquality'), 'multi');
            }
        }
        if ($DB->tableExists('glpi_agents')) {
            $add('agent:last_contact', __('Ajanın son bağlantısı', 'inventoryquality'), 'date');
        }
        return self::$cache[$itemtype] = $out;
    }

    /** @return array<string,mixed>|null */
    public static function get(string $itemtype, string $key): ?array
    {
        if (str_starts_with($key, 'attest:')) {
            return self::attestField($itemtype, $key);
        }
        return self::fields($itemtype)[$key] ?? null;
    }

    /**
     * İş sahibi teyidi alanı: "attest:core:locations_id,core:users_id" = bu alanların hepsini kapsayan en son teyidin
     * tarihi (eklentinin attestations tablosu; otomatik envanter gelişinden ayrı). Türetilmiş, salt okunur.
     * @return array<string,mixed>|null
     */
    public static function attestField(string $itemtype, string $key): ?array
    {
        $list = self::attestList($key);
        $cat = self::fields($itemtype);
        if (!$list) {
            return null;
        }
        $labels = [];
        foreach ($list as $f) {
            if (!isset($cat[$f]) || $cat[$f]['column'] === '') {
                return null;
            }
            $labels[] = $cat[$f]['label'];
        }
        return [
            'key' => $key, 'label' => sprintf(__('İş sahibi teyidi (%s)', 'inventoryquality'), implode(', ', $labels)),
            'datatype' => 'date', 'column' => '', 'ref_itemtype' => '', 'ref_table' => '', 'writable' => false, 'ops' => self::OPS['date'],
        ];
    }

    /** @return list<string> */
    public static function attestList(string $key): array
    {
        if (!str_starts_with($key, 'attest:')) {
            return [];
        }
        $l = array_values(array_unique(array_filter(explode(',', substr($key, 7)))));
        sort($l);
        return $l;
    }

    /** @param list<string> $fields */
    public static function attestKey(array $fields): string
    {
        $fields = array_values(array_unique(array_filter($fields)));
        sort($fields);
        return 'attest:' . implode(',', $fields);
    }

    /** Katalogu fieldmaps tablosuna yazar; artık bulunmayan alanları geçersiz işaretler, kuralları doğrular. */
    public static function sync(): void
    {
        global $DB;
        self::reset();
        $now = Db::now();
        foreach (AssetAdapter::supportedTypes() as $it) {
            $seen = [];
            foreach (self::fields($it) as $f) {
                $seen[] = $f['key'];
                Db::upsert(self::TABLE, [
                    'itemtype'     => $it,
                    'adapter'      => 'core',
                    'field_key'    => $f['key'],
                    'datatype'     => $f['datatype'],
                    'ref_table'    => $f['ref_table'],
                    'label'        => $f['label'],
                    'capabilities' => $f['writable'] ? 'rw' : 'r',
                    'operations'   => Db::json($f['ops']),
                    'glpi_version' => GLPI_VERSION,
                    'is_valid'     => 1,
                    'date_mod'     => $now,
                ], ['datatype', 'ref_table', 'label', 'capabilities', 'operations', 'glpi_version', 'is_valid', 'date_mod']);
            }
            $where = ['itemtype' => $it, 'is_valid' => 1];
            if ($seen) {
                $where['NOT'] = ['field_key' => $seen];
            }
            $DB->update(self::TABLE, ['is_valid' => 0, 'date_mod' => $now], $where);
        }
        Rule::validateAll();
    }

    /** Değer etiketini (açılır liste adı) döndürür; kayıt yoksa boş. */
    public static function refLabel(string $refItemtype, int $id): string
    {
        if ($id <= 0 || !class_exists($refItemtype)) {
            return '';
        }
        if ($refItemtype === 'User') {
            return (string) getUserName($id);
        }
        $name = \Dropdown::getDropdownName(getTableForItemType($refItemtype), $id);
        return $name === '&nbsp;' ? '' : (string) $name;
    }
}
