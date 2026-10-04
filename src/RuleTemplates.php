<?php

namespace GlpiPlugin\Inventoryquality;

use InvalidArgumentException;

/**
 * İlk sürüm kural şablonları (DQ-01…DQ-06) ve form girdisinden normalize tanım üretimi.
 * Şablonlar başlangıç önerisidir; eşik ve değerler kuruma göre ayarlanır. Serbest SQL / PHP ifadesi /
 * düzenli ifade YOKTUR — yalnız katalogdaki alanlar ve desteklenen operatörler.
 *
 * Normalize tanım:
 *   template, target (bulgu hedef alanı), precondition (null | koşul), assert (koşul),
 *   scope ['states' => [kimlik…]] (boş = tüm durumlar),
 *   policy ['due_days' => 0 (=birim varsayılanı), 'groups_id', 'users_id', 'ticket' => inherit|off],
 *   field_types [anahtar => veri tipi] (yayın anındaki tip; değişirse kural askıya alınır),
 *   labels ['values' => [kimlik => etiket]] (ekranda gösterim için anlık görüntü)
 */
final class RuleTemplates
{
    public const SEVERITIES = [1 => 'Düşük', 2 => 'Orta', 3 => 'Yüksek', 4 => 'Kritik'];

    /** @return array<string,array{code:string,label:string,help:string,severity:int,weight:int}> */
    public static function all(): array
    {
        return [
            'required' => [
                'code' => 'DQ-01', 'label' => __('Zorunlu alan', 'inventoryquality'), 'severity' => 2, 'weight' => 2,
                'help' => __('Kapsamdaki varlıkta kurumun zorunlu saydığı alan boşsa uygunsuz. Düzeltme: yetkili kişi geçerli değer girer.', 'inventoryquality'),
            ],
            'responsible' => [
                'code' => 'DQ-02', 'label' => __('Sorumlu', 'inventoryquality'), 'severity' => 3, 'weight' => 3,
                'help' => __('Aktif varlıkta sorumlu kişi (aktif teknik sorumlu) ya da teknik grup yoksa uygunsuz. Kapsamı "aktif" durumlarla sınırlayın.', 'inventoryquality'),
            ],
            'inactive_user' => [
                'code' => 'DQ-03', 'label' => __('Pasif kullanıcı', 'inventoryquality'), 'severity' => 3, 'weight' => 2,
                'help' => __('Varlığa bağlı kullanıcı pasif, silinmiş ya da geçerlilik süresi dolmuşsa uygunsuz. Kullanıcı boşsa uygulanmaz.', 'inventoryquality'),
            ],
            'valueset' => [
                'code' => 'DQ-04', 'label' => __('Değer kümesi', 'inventoryquality'), 'severity' => 2, 'weight' => 1,
                'help' => __('Alan, izin verilen seçeneklerin dışındaysa uygunsuz. Alan boşsa uygulanmaz (zorunluluk için DQ-01).', 'inventoryquality'),
            ],
            'conditional' => [
                'code' => 'DQ-05', 'label' => __('Koşullu zorunluluk', 'inventoryquality'), 'severity' => 2, 'weight' => 2,
                'help' => __('Önkoşul sağlandığında (ör. durum "Kullanımda") hedef alan boşsa uygunsuz. Önkoşul sağlanmıyorsa uygulanmaz.', 'inventoryquality'),
            ],
            'reference' => [
                'code' => 'DQ-06', 'label' => __('Referans bütünlüğü', 'inventoryquality'), 'severity' => 3, 'weight' => 2,
                'help' => __('Alan artık bulunmayan (silinmiş) bir kayda bağlıysa uygunsuz. Doğru referans seçilir; otomatik tahmin yapılmaz.', 'inventoryquality'),
            ],
            'freshness' => [
                'code' => 'DQ-07', 'label' => __('Güncellik', 'inventoryquality'), 'severity' => 2, 'weight' => 1,
                'help' => __('Seçilen veri kaynağının son görülme tarihi belirlenen günü aşmışsa (ya da hiç yoksa) uygunsuz. Ajan / veri kaynağı incelenir; tarih elle ileri alınmaz — bu kural için düzeltme önerisi yoktur.', 'inventoryquality'),
            ],
            'attestation' => [
                'code' => 'DQ-08', 'label' => __('İş sahibi teyidi', 'inventoryquality'), 'severity' => 2, 'weight' => 1,
                'help' => __('Belirlenen periyotta seçili alanlar (ör. kullanıcı, konum) iş sahibi tarafından teyit edilmemişse uygunsuz. Teyit, varlığın "Veri Kalitesi" sekmesinden ya da bulgu ekranından verilir.', 'inventoryquality'),
            ],
        ];
    }

    /** Kuralda düzeltme önerisi verilebilir mi (DQ-07: tarih elle ileri alınmaz; DQ-08: teyit ile kapanır). */
    public static function allowsCorrection(string $template): bool
    {
        return !in_array($template, ['freshness', 'attestation'], true);
    }

    /** @return array<string,string> teyit edilebilir alanlar (çekirdek kolonlar, metin hariç) */
    public static function attestableFields(string $itemtype): array
    {
        $out = [];
        foreach (Catalog::fields($itemtype) as $k => $d) {
            if ($d['column'] !== '' && $d['writable'] && $d['datatype'] !== 'text') {
                $out[$k] = $d['label'];
            }
        }
        return $out;
    }

    public static function label(string $template): string
    {
        return self::all()[$template]['label'] ?? $template;
    }

    /**
     * Şablon için seçilebilir hedef alanlar.
     * @return array<string,array<string,mixed>>
     */
    public static function targetFields(string $template, string $itemtype): array
    {
        $f = Catalog::fields($itemtype);
        return array_filter($f, static function (array $d) use ($template): bool {
            return match ($template) {
                'required', 'conditional' => in_array('not_empty', $d['ops'], true) && $d['key'] !== 'virt:responsible',
                'responsible'   => $d['key'] === 'virt:responsible',
                'inactive_user' => $d['datatype'] === 'user',
                'valueset'      => in_array($d['datatype'], ['fk', 'user', 'string'], true),
                'reference'     => in_array($d['datatype'], ['fk', 'user'], true),
                'freshness'     => $d['datatype'] === 'date' && in_array($d['key'], ['agent:last_contact', 'core:last_inventory_update'], true),
                default         => false,
            };
        });
    }

    /**
     * Form girdisinden normalize tanım. Geçersiz girdide InvalidArgumentException (kullanıcıya gösterilir).
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function build(string $template, string $itemtype, array $in): array
    {
        if (!isset(self::all()[$template])) {
            throw new InvalidArgumentException(__('Bilinmeyen kural şablonu.', 'inventoryquality'));
        }
        $cat = Catalog::fields($itemtype);
        $labels = ['values' => []];
        $pre = null;
        if ($template === 'attestation') {
            $af = array_values(array_intersect(array_map('strval', (array) ($in['attest_fields'] ?? [])), array_keys(self::attestableFields($itemtype))));
            if (!$af) {
                throw new InvalidArgumentException(__('Teyit edilecek en az bir alan seçin.', 'inventoryquality'));
            }
            $field = Catalog::attestKey($af);
            $cat[$field] = Catalog::attestField($itemtype, $field);
            $targets = [$field => $cat[$field]];
        } else {
            $targets = self::targetFields($template, $itemtype);
            $field = $template === 'responsible' ? 'virt:responsible' : (string) ($in['field'] ?? '');
        }
        if (!isset($targets[$field])) {
            throw new InvalidArgumentException(__('Bu şablon için geçerli bir hedef alan seçin.', 'inventoryquality'));
        }
        switch ($template) {
            case 'freshness':
            case 'attestation':
                $days = (int) ($in['days'] ?? 0);
                if ($days < 1 || $days > 3650) {
                    throw new InvalidArgumentException(__('Gün sayısı 1 ile 3650 arasında olmalı.', 'inventoryquality'));
                }
                $assert = ['field' => $field, 'op' => 'within_days', 'days' => $days];
                if ($template === 'freshness' && !empty($in['only_dynamic']) && isset($cat['core:is_dynamic'])) {
                    $pre = ['field' => 'core:is_dynamic', 'op' => 'eq', 'values' => [1]];
                }
                break;
            case 'required':
            case 'responsible':
                $assert = ['field' => $field, 'op' => 'not_empty'];
                break;
            case 'inactive_user':
                $pre = ['field' => $field, 'op' => 'not_empty'];
                $assert = ['field' => $field, 'op' => 'ref_active'];
                break;
            case 'valueset':
                $vals = self::values($cat[$field], (array) ($in['values'] ?? []), $labels);
                if (!$vals) {
                    throw new InvalidArgumentException(__('İzin verilen en az bir değer seçin.', 'inventoryquality'));
                }
                $pre = ['field' => $field, 'op' => 'not_empty'];
                $assert = ['field' => $field, 'op' => 'in', 'values' => $vals];
                break;
            case 'reference':
                $pre = ['field' => $field, 'op' => 'not_empty'];
                $assert = ['field' => $field, 'op' => 'ref_exists'];
                break;
            case 'conditional':
                $pf = (string) ($in['pre_field'] ?? '');
                $pop = (string) ($in['pre_op'] ?? '');
                if (!isset($cat[$pf]) || !in_array($pop, ['eq', 'neq', 'in', 'not_in', 'empty', 'not_empty'], true) || !in_array($pop, $cat[$pf]['ops'], true)) {
                    throw new InvalidArgumentException(__('Önkoşul için geçerli bir alan ve operatör seçin.', 'inventoryquality'));
                }
                $pre = ['field' => $pf, 'op' => $pop];
                if (in_array($pop, ['eq', 'neq', 'in', 'not_in'], true)) {
                    $pv = self::values($cat[$pf], (array) ($in['pre_values'] ?? []), $labels);
                    if (!$pv) {
                        throw new InvalidArgumentException(__('Önkoşul için en az bir değer seçin.', 'inventoryquality'));
                    }
                    $pre['values'] = in_array($pop, ['eq', 'neq'], true) ? [$pv[0]] : $pv;
                }
                if ($pf === $field) {
                    throw new InvalidArgumentException(__('Önkoşul alanı ile hedef alan aynı olamaz.', 'inventoryquality'));
                }
                $assert = ['field' => $field, 'op' => 'not_empty'];
                break;
            default:
                throw new InvalidArgumentException('?');
        }

        $states = [];
        if (isset($cat['core:states_id'])) {
            $states = self::values($cat['core:states_id'], (array) ($in['scope_states'] ?? []), $labels);
        }
        $def = [
            'template'     => $template,
            'target'       => $field,
            'precondition' => $pre,
            'assert'       => $assert,
            'scope'        => ['states' => $states],
            'policy'       => [
                'due_days'  => min(365, max(0, (int) ($in['due_days'] ?? 0))),
                'groups_id' => max(0, (int) ($in['groups_id'] ?? 0)),
                'users_id'  => max(0, (int) ($in['users_id'] ?? 0)),
                'ticket'    => ($in['ticket'] ?? 'inherit') === 'off' ? 'off' : 'inherit',
                'approval'  => self::allowsCorrection($template) ? self::approvalPolicy($in) : ['level' => 'none', 'steps' => [], 'allow_self' => false],
            ],
            'labels'       => $labels,
        ];
        $def['field_types'] = [];
        foreach (array_merge(RuleEvaluator::keys($def), $states ? ['core:states_id'] : []) as $k) {
            $def['field_types'][$k] = $cat[$k]['datatype'];
        }
        return $def;
    }

    /**
     * Düzeltme onay politikası (kuraldan gelir; düzeltmeyi yapan kişi gevşetemez):
     *   level none = yetkili kişi doğrudan uygular · one = 1. onay · two = 1. onay, ardından 2. onay (sıralı)
     *   her adım: grup ve/veya kişi + karar türü (any = gruptan bir yetkili yeterli, all = belirlenmiş herkes onaylamalı)
     *   allow_self: kendi talebini onaylama (varsayılan kapalı)
     * @param array<string,mixed> $in
     * @return array{level:string,steps:array<int,array{groups_id:int,users_id:int,mode:string}>,allow_self:bool}
     */
    public static function approvalPolicy(array $in): array
    {
        $level = (string) ($in['approval'] ?? 'none');
        if (!in_array($level, ['none', 'one', 'two'], true)) {
            $level = 'none';
        }
        $steps = [];
        $n = ['none' => 0, 'one' => 1, 'two' => 2][$level];
        for ($i = 1; $i <= $n; $i++) {
            $g = max(0, (int) ($in["appr{$i}_group"] ?? 0));
            $u = $i === 1 ? max(0, (int) ($in['appr1_user'] ?? 0)) : 0;
            if ($g === 0 && $u === 0) {
                throw new InvalidArgumentException(sprintf(__('%d. onay adımı için onaylayan grup ya da kişi seçin.', 'inventoryquality'), $i));
            }
            if ($g > 0 && !Db::row('glpi_groups', ['id' => $g])) {
                throw new InvalidArgumentException(sprintf(__('%d. onay adımındaki grup bulunamadı.', 'inventoryquality'), $i));
            }
            if ($u > 0 && !(AssetAdapter::refInfo('User', [$u])[$u]['active'] ?? false)) {
                throw new InvalidArgumentException(__('1. onay adımındaki kişi aktif değil.', 'inventoryquality'));
            }
            $steps[$i] = ['groups_id' => $g, 'users_id' => $u, 'mode' => ($in["appr{$i}_mode"] ?? 'any') === 'all' ? 'all' : 'any'];
        }
        return ['level' => $level, 'steps' => $steps, 'allow_self' => !empty($in['allow_self'])];
    }

    public static function approvalLabel(array $policy): string
    {
        return match ($policy['level'] ?? 'none') {
            'one'   => __('1 onay adımı', 'inventoryquality'),
            'two'   => __('2 sıralı onay adımı', 'inventoryquality'),
            default => __('onaysız (yetkili kişi doğrudan uygular)', 'inventoryquality'),
        };
    }

    /**
     * Değer listesini doğrular: açılır liste / kullanıcı için GERÇEK kayıt kimlikleri (görünen ad değil).
     * @param array<string,mixed> $fieldDef
     * @param array<mixed> $raw
     * @param array<string,mixed> $labels
     * @return list<int|string>
     */
    private static function values(array $fieldDef, array $raw, array &$labels): array
    {
        if (in_array($fieldDef['datatype'], ['fk', 'user'], true)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $raw), static fn($i) => $i > 0)));
            if (!$ids) {
                return [];
            }
            $info = AssetAdapter::refInfo($fieldDef['ref_itemtype'], $ids);
            foreach ($ids as $id) {
                if (empty($info[$id]['exists'])) {
                    throw new InvalidArgumentException(sprintf(__('Seçilen değer bulunamadı: #%d', 'inventoryquality'), $id));
                }
                $labels['values'][$fieldDef['key'] . ':' . $id] = $info[$id]['label'];
            }
            return $ids;
        }
        if ($fieldDef['datatype'] === 'bool') {
            return [((int) ($raw[0] ?? 0)) ? 1 : 0];
        }
        $vals = [];
        foreach ($raw as $s) {
            foreach (preg_split('/\R/', (string) $s) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && mb_strlen($line) <= 255) {
                    $vals[] = $line;
                }
            }
        }
        $vals = array_values(array_unique($vals));
        if (count($vals) > 50) {
            throw new InvalidArgumentException(__('En fazla 50 değer girilebilir.', 'inventoryquality'));
        }
        return $vals;
    }

    /** Kullanıcıya görünen açıklama ("Konum dolu olmalı · önkoşul: Durum ∈ {Kullanımda}"). */
    public static function describe(array $def, string $itemtype): string
    {
        $s = self::cond($def['assert'] ?? [], $def, $itemtype);
        if (!empty($def['precondition']) && ($def['template'] ?? '') === 'conditional') {
            $s .= ' · ' . sprintf(__('önkoşul: %s', 'inventoryquality'), self::cond($def['precondition'], $def, $itemtype));
        }
        if (!empty($def['precondition']) && ($def['template'] ?? '') === 'freshness') {
            $s .= ' · ' . __('yalnız otomatik envanterden gelen kayıtlar', 'inventoryquality');
        }
        if (!empty($def['scope']['states'])) {
            $s .= ' · ' . sprintf(__('kapsam: Durum ∈ {%s}', 'inventoryquality'), self::valueList('core:states_id', $def['scope']['states'], $def));
        }
        return $s;
    }

    private static function cond(array $c, array $def, string $itemtype): string
    {
        $f = (string) ($c['field'] ?? '');
        $label = Catalog::get($itemtype, $f)['label'] ?? $f;
        $vals = self::valueList($f, (array) ($c['values'] ?? []), $def);
        return match ($c['op'] ?? '') {
            'empty'           => sprintf(__('%s boş', 'inventoryquality'), $label),
            'not_empty'       => sprintf(__('%s dolu olmalı', 'inventoryquality'), $label),
            'eq'              => sprintf(__('%s = %s', 'inventoryquality'), $label, $vals),
            'neq'             => sprintf(__('%s ≠ %s', 'inventoryquality'), $label, $vals),
            'in'              => sprintf(__('%s şunlardan biri olmalı: %s', 'inventoryquality'), $label, $vals),
            'not_in'          => sprintf(__('%s şunlardan biri olmamalı: %s', 'inventoryquality'), $label, $vals),
            'ref_exists'      => sprintf(__('%s var olan bir kayda bağlı olmalı', 'inventoryquality'), $label),
            'ref_active'      => sprintf(__('%s aktif bir kullanıcı olmalı', 'inventoryquality'), $label),
            'older_than_days' => sprintf(__('%s %d günden eski', 'inventoryquality'), $label, (int) ($c['days'] ?? 0)),
            'within_days'     => sprintf(__('%s son %d gün içinde olmalı', 'inventoryquality'), $label, (int) ($c['days'] ?? 0)),
            default           => $label,
        };
    }

    private static function valueList(string $field, array $vals, array $def): string
    {
        $out = [];
        foreach ($vals as $v) {
            $out[] = (string) ($def['labels']['values'][$field . ':' . $v] ?? $v);
        }
        return implode(', ', $out);
    }
}
