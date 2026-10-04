<?php

namespace GlpiPlugin\Inventoryquality;

use CommonDBTM;
use InvalidArgumentException;
use Session;

/**
 * Kalite kuralı. Kararlı kimlik (code) + kapsam (birim, alt birim politikası, varlık tipi) + yayındaki sürüm.
 * Tanım (koşul, durum kapsamı, önem, ağırlık, süre, sorumlu, destek kaydı politikası) SÜRÜMDE tutulur:
 * yayına alma yeni sürüm oluşturur; değişikliği yapan kişi ve zaman kaydedilir. Kural sürümü bulgu kimliğinin
 * parçası değildir (sürüm değişikliği yinelenen bulgu üretmez).
 */
class Rule extends CommonDBTM
{
    public static $rightname = Rights::NAME;
    public $dohistory = false;

    public const VERSIONS = Install::P . 'ruleversions';

    public static function getTypeName($nb = 0)
    {
        return _n('Kalite kuralı', 'Kalite kuralları', $nb, 'inventoryquality');
    }

    public static function getIcon()
    {
        return 'ti ti-list-check';
    }

    public static function canCreate(): bool
    {
        return Rights::has(Rights::RULES);
    }

    public static function canUpdate(): bool
    {
        return Rights::has(Rights::RULES);
    }

    public static function canDelete(): bool
    {
        return Rights::has(Rights::RULES);
    }

    public static function canPurge(): bool
    {
        return Rights::has(Rights::RULES);
    }

    public static function getFormURL($full = true)
    {
        return Menu::base() . '/rule.form.php';
    }

    public static function getSearchURL($full = true)
    {
        return Menu::base() . '/rule.php';
    }

    public function rawSearchOptions()
    {
        $t = $this->getTable();
        return [
            ['id' => 'common', 'name' => self::getTypeName(1)],
            ['id' => 1, 'table' => $t, 'field' => 'name', 'name' => __('Ad', 'inventoryquality'), 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => __('Kimlik', 'inventoryquality'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 3, 'table' => $t, 'field' => 'code', 'name' => __('Kod', 'inventoryquality'), 'datatype' => 'specific', 'searchtype' => ['contains', 'notcontains', 'equals', 'notequals'], 'massiveaction' => false],
            ['id' => 4, 'table' => $t, 'field' => 'itemtype', 'name' => __('Varlık tipi', 'inventoryquality'), 'datatype' => 'specific', 'massiveaction' => false],
            ['id' => 5, 'table' => $t, 'field' => 'template', 'name' => __('Şablon', 'inventoryquality'), 'datatype' => 'specific', 'massiveaction' => false],
            ['id' => 6, 'table' => $t, 'field' => 'is_active', 'name' => __('Etkin', 'inventoryquality'), 'datatype' => 'bool', 'massiveaction' => false],
            ['id' => 7, 'table' => $t, 'field' => 'suspended_reason', 'name' => __('Askı nedeni', 'inventoryquality'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 19, 'table' => $t, 'field' => 'date_mod', 'name' => __('Son değişiklik', 'inventoryquality'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => __('Kurum birimi', 'inventoryquality'), 'datatype' => 'dropdown', 'massiveaction' => false],
            ['id' => 86, 'table' => $t, 'field' => 'is_recursive', 'name' => __('Alt birimler', 'inventoryquality'), 'datatype' => 'bool', 'massiveaction' => false],
        ];
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        $v = is_array($values) ? ($values[$field] ?? '') : $values;
        if ($field === 'itemtype') {
            return class_exists((string) $v) ? htmlescape($v::getTypeName(1)) : htmlescape((string) $v);
        }
        if ($field === 'code') {
            return "<span class='text-nowrap'>" . htmlescape((string) $v) . '</span>';
        }
        if ($field === 'template') {
            return htmlescape(RuleTemplates::label((string) $v));
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /** Kural yalnız kendi kapsam birimine erişimi olan kullanıcıya görünür / düzenlenebilir. */
    public static function canAccess(array $rule): bool
    {
        return Session::haveAccessToEntity((int) $rule['entities_id'], (bool) $rule['is_recursive']);
    }

    // ---------------------------------------------------------------- oluşturma, sürüm, etkinlik

    public static function create(string $template, string $code, string $name, string $itemtype, int $entitiesId, bool $recursive): int
    {
        global $DB;
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{1,31}$/', $code)) {
            throw new InvalidArgumentException(__('Kod 2–32 karakter olmalı (harf, rakam, nokta, tire, alt çizgi).', 'inventoryquality'));
        }
        if (!isset(RuleTemplates::all()[$template])) {
            throw new InvalidArgumentException(__('Bilinmeyen kural şablonu.', 'inventoryquality'));
        }
        if (!AssetAdapter::isSupported($itemtype)) {
            throw new InvalidArgumentException(__('Bu varlık tipi henüz doğrulanmadı.', 'inventoryquality'));
        }
        if (countElementsInTable(self::getTable(), ['code' => $code]) > 0) {
            throw new InvalidArgumentException(sprintf(__('"%s" kodu kullanılıyor; kural kodu değişmez ve tekildir.', 'inventoryquality'), $code));
        }
        $name = trim($name) !== '' ? trim($name) : RuleTemplates::label($template);
        $now = Db::now();
        $DB->insert(self::getTable(), [
            'code' => $code, 'name' => mb_substr($name, 0, 255), 'entities_id' => $entitiesId, 'is_recursive' => $recursive ? 1 : 0,
            'itemtype' => $itemtype, 'template' => $template, 'is_active' => 0, 'date_creation' => $now, 'date_mod' => $now,
        ]);
        $id = (int) $DB->insertId();
        AuditLog::write('rule_create', self::class, $id, $entitiesId, null, ['code' => $code, 'template' => $template, 'itemtype' => $itemtype]);
        return $id;
    }

    /**
     * Yeni sürüm yayınlar (öncekiler geçmişte kalır). Kural etkinse kapsam yeni sürümle yeniden taranır.
     * @param array<string,mixed> $def RuleTemplates::build çıktısı
     */
    public static function publish(int $rulesId, array $def, int $severity, int $weight, string $comment = ''): int
    {
        global $DB;
        $rule = Db::row(self::getTable(), ['id' => $rulesId]);
        if (!$rule) {
            throw new InvalidArgumentException('rule');
        }
        $severity = isset(RuleTemplates::SEVERITIES[$severity]) ? $severity : 2;
        $weight = min(100, max(1, $weight));
        $last = (int) ($DB->request(['SELECT' => ['MAX' => 'version AS v'], 'FROM' => self::VERSIONS, 'WHERE' => ['rules_id' => $rulesId]])->current()['v'] ?? 0);
        $DB->insert(self::VERSIONS, [
            'rules_id' => $rulesId, 'version' => $last + 1, 'definition' => Db::json($def), 'severity' => $severity, 'weight' => $weight,
            'users_id' => (int) Session::getLoginUserID(false), 'comment' => mb_substr($comment, 0, 2000), 'date_creation' => Db::now(),
        ]);
        $vid = (int) $DB->insertId();
        $DB->update(self::getTable(), ['ruleversions_id' => $vid, 'suspended_reason' => '', 'date_mod' => Db::now()], ['id' => $rulesId]);
        AuditLog::write('rule_publish', self::class, $rulesId, (int) $rule['entities_id'], ['version' => $last], ['version' => $last + 1, 'severity' => $severity, 'weight' => $weight, 'definition' => $def], $comment);
        if ((int) $rule['is_active'] === 1) {
            JobQueue::enqueue('scan_rule', 'scan_rule:' . $rulesId, ['rules_id' => $rulesId]);
        }
        return $vid;
    }

    public static function setActive(int $rulesId, bool $active): void
    {
        global $DB;
        $rule = Db::row(self::getTable(), ['id' => $rulesId]);
        if (!$rule || (bool) $rule['is_active'] === $active) {
            return;
        }
        if ($active && (int) $rule['ruleversions_id'] === 0) {
            throw new InvalidArgumentException(__('Önce kuralın bir sürümünü yayınlayın.', 'inventoryquality'));
        }
        $DB->update(self::getTable(), ['is_active' => $active ? 1 : 0, 'date_mod' => Db::now()], ['id' => $rulesId]);
        AuditLog::write($active ? 'rule_activate' : 'rule_deactivate', self::class, $rulesId, (int) $rule['entities_id']);
        if ($active) {
            JobQueue::enqueue('scan_rule', 'scan_rule:' . $rulesId, ['rules_id' => $rulesId]);
        } else {
            // Kuralı kapatmak geçmiş bulguları "düzeltildi" yapmaz: kapsam dışına alınır, puandan çıkar.
            FindingService::closeForRule($rulesId, __('Kural pasifleştirildi', 'inventoryquality'));
            $DB->delete(Evaluation::TABLE, ['rules_id' => $rulesId]);
        }
    }

    /** Bulgusu / değerlendirmesi olmayan kural silinebilir; aksi halde yalnız pasifleştirilir. */
    public static function canRemove(int $rulesId): bool
    {
        return countElementsInTable(Finding::getTable(), ['rules_id' => $rulesId]) === 0
            && countElementsInTable(Evaluation::TABLE, ['rules_id' => $rulesId]) === 0;
    }

    public function post_purgeItem()
    {
        global $DB;
        $DB->delete(self::VERSIONS, ['rules_id' => (int) $this->getID()]);
    }

    /** @return array<string,mixed>|null */
    public static function version(int $versionId): ?array
    {
        $v = Db::row(self::VERSIONS, ['id' => $versionId]);
        if ($v) {
            $v['def'] = Db::decode($v['definition']);
        }
        return $v;
    }

    /** @return list<array<string,mixed>> */
    public static function versions(int $rulesId): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::VERSIONS, 'WHERE' => ['rules_id' => $rulesId], 'ORDER' => 'version DESC']), false);
    }

    /**
     * Etkin ve askıda olmayan kurallar, yayındaki sürümleriyle.
     * @param list<int>|null $only yalnız bu kural kimlikleri
     * @return list<array<string,mixed>> her satırda 'v' (sürüm satırı + 'def') ve 'entities' (kapsam birimleri)
     */
    public static function activeRules(?string $itemtype = null, ?array $only = null): array
    {
        global $DB;
        $where = ['is_active' => 1, 'suspended_reason' => '', 'ruleversions_id' => ['>', 0]];
        if ($itemtype !== null) {
            $where['itemtype'] = $itemtype;
        }
        if ($only !== null) {
            if (!$only) {
                return [];
            }
            $where['id'] = $only;
        }
        $out = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => $where, 'ORDER' => 'id']) as $r) {
            if (!AssetAdapter::isSupported((string) $r['itemtype'])) {
                continue;
            }
            $v = self::version((int) $r['ruleversions_id']);
            if (!$v) {
                continue;
            }
            $r['v'] = $v;
            $r['entities'] = self::scopeEntities((int) $r['entities_id'], (bool) $r['is_recursive']);
            $out[] = $r;
        }
        return $out;
    }

    /** @return list<int> */
    public static function scopeEntities(int $entitiesId, bool $recursive): array
    {
        if (!$recursive) {
            return [$entitiesId];
        }
        return array_values(array_map('intval', getSonsOf('glpi_entities', $entitiesId)));
    }

    /**
     * Katalogla tutarlılık: kuralın kullandığı alan kaybolduysa ya da veri tipi değiştiyse kural ASKIYA alınır
     * (yapılandırma uyarısı); düzeldiyse askı kalkar.
     */
    public static function validateAll(): void
    {
        global $DB;
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['ruleversions_id' => ['>', 0]]]) as $r) {
            $v = self::version((int) $r['ruleversions_id']);
            $reason = '';
            if (!AssetAdapter::isSupported((string) $r['itemtype'])) {
                $reason = __('Varlık tipi desteklenmiyor', 'inventoryquality');
            } elseif ($v) {
                foreach ((array) ($v['def']['field_types'] ?? []) as $key => $type) {
                    $f = Catalog::get((string) $r['itemtype'], (string) $key);
                    if ($f === null) {
                        $reason = sprintf(__('Alan bulunamadı: %s', 'inventoryquality'), $key);
                        break;
                    }
                    if ($f['datatype'] !== $type) {
                        $reason = sprintf(__('Alanın veri tipi değişti: %s (%s → %s)', 'inventoryquality'), $key, $type, $f['datatype']);
                        break;
                    }
                }
            }
            if ($reason !== (string) $r['suspended_reason']) {
                $DB->update(self::getTable(), ['suspended_reason' => $reason, 'date_mod' => Db::now()], ['id' => (int) $r['id']]);
                AuditLog::write($reason === '' ? 'rule_unsuspend' : 'rule_suspend', self::class, (int) $r['id'], (int) $r['entities_id'], null, null, $reason);
            }
        }
    }
}
