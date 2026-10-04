<?php

namespace GlpiPlugin\Inventoryquality;

use CommonDBTM;
use Html;

/**
 * Kalite bulgusu. Kararlı anahtar: entity + varlık tipi + varlık kimliği + kural kimliği + hedef alan.
 * Aynı anahtarda tek güncel bulgu bulunur; her açılma–çözülme dönemi cycles tablosunda saklanır.
 * Bulgular yalnız FindingService üzerinden değişir (genel düzenleme formu yoktur).
 */
class Finding extends CommonDBTM
{
    public static $rightname = Rights::NAME;
    public $dohistory = false;

    public const OPEN                 = 'open';
    public const IN_PROGRESS          = 'in_progress';
    public const PENDING_APPROVAL     = 'pending_approval';
    public const PENDING_VERIFICATION = 'pending_verification';
    public const RESOLVED             = 'resolved';
    public const EXCEPTION            = 'exception';
    public const REVIEW               = 'review';
    public const OUT_OF_SCOPE         = 'out_of_scope';

    /** Düzeltme işi süren (aktif) durumlar. */
    public const ACTIVE = [self::OPEN, self::IN_PROGRESS, self::PENDING_APPROVAL, self::PENDING_VERIFICATION, self::REVIEW];

    public static function getTypeName($nb = 0)
    {
        return _n('Bulgu', 'Bulgular', $nb, 'inventoryquality');
    }

    public static function getIcon()
    {
        return 'ti ti-alert-triangle';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canUpdate(): bool
    {
        return false;
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }

    public static function getFormURL($full = true)
    {
        return Menu::base() . '/finding.form.php';
    }

    public static function getSearchURL($full = true)
    {
        return Menu::base() . '/finding.php';
    }

    /** @return array<string,string> */
    public static function statuses(): array
    {
        return [
            self::OPEN                 => __('Açık', 'inventoryquality'),
            self::IN_PROGRESS          => __('İşlemde', 'inventoryquality'),
            self::PENDING_APPROVAL     => __('Onay bekliyor', 'inventoryquality'),
            self::PENDING_VERIFICATION => __('Doğrulama bekliyor', 'inventoryquality'),
            self::RESOLVED             => __('Çözüldü', 'inventoryquality'),
            self::EXCEPTION            => __('İstisna', 'inventoryquality'),
            self::REVIEW               => __('İnceleme gerekli', 'inventoryquality'),
            self::OUT_OF_SCOPE         => __('Kapsam dışı', 'inventoryquality'),
        ];
    }

    public static function statusLabel(string $s): string
    {
        return self::statuses()[$s] ?? $s;
    }

    public static function statusBadge(string $s): string
    {
        $cls = match ($s) {
            self::OPEN => 'bg-danger-lt', self::IN_PROGRESS => 'bg-warning-lt', self::PENDING_APPROVAL, self::PENDING_VERIFICATION => 'bg-azure-lt',
            self::RESOLVED => 'bg-success-lt', self::EXCEPTION => 'bg-purple-lt', self::REVIEW => 'bg-orange-lt', default => 'bg-secondary-lt',
        };
        return "<span class='badge $cls'>" . htmlescape(self::statusLabel($s)) . '</span>';
    }

    public static function severityLabel(int $s): string
    {
        return __(RuleTemplates::SEVERITIES[$s] ?? (string) $s, 'inventoryquality');
    }

    public function rawSearchOptions()
    {
        $t = $this->getTable();
        return [
            ['id' => 'common', 'name' => self::getTypeName(1)],
            ['id' => 1, 'table' => $t, 'field' => 'id', 'name' => __('Bulgu', 'inventoryquality'), 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => 2, 'table' => $t, 'field' => 'items_id', 'name' => __('Varlık', 'inventoryquality'), 'datatype' => 'specific', 'additionalfields' => ['itemtype'], 'nosearch' => true, 'massiveaction' => false],
            ['id' => 3, 'table' => $t, 'field' => 'itemtype', 'name' => __('Varlık tipi', 'inventoryquality'), 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
            ['id' => 4, 'table' => Rule::getTable(), 'field' => 'code', 'name' => __('Kural kodu', 'inventoryquality'), 'datatype' => 'string', 'linkfield' => 'rules_id', 'massiveaction' => false],
            ['id' => 5, 'table' => Rule::getTable(), 'field' => 'name', 'name' => __('Kural', 'inventoryquality'), 'datatype' => 'string', 'linkfield' => 'rules_id', 'massiveaction' => false],
            ['id' => 6, 'table' => $t, 'field' => 'status', 'name' => __('Durum', 'inventoryquality'), 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
            ['id' => 7, 'table' => $t, 'field' => 'severity', 'name' => __('Önem', 'inventoryquality'), 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
            ['id' => 8, 'table' => 'glpi_users', 'field' => 'name', 'name' => __('Sorumlu kişi', 'inventoryquality'), 'datatype' => 'dropdown', 'linkfield' => 'users_id_assign', 'right' => 'all', 'massiveaction' => false],
            ['id' => 9, 'table' => 'glpi_groups', 'field' => 'completename', 'name' => __('Sorumlu grup', 'inventoryquality'), 'datatype' => 'dropdown', 'linkfield' => 'groups_id_assign', 'massiveaction' => false],
            ['id' => 10, 'table' => $t, 'field' => 'assign_state', 'name' => __('Atama', 'inventoryquality'), 'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false],
            ['id' => 11, 'table' => $t, 'field' => 'due_date', 'name' => __('Hedef tarih', 'inventoryquality'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 12, 'table' => $t, 'field' => 'last_check', 'name' => __('Son kontrol', 'inventoryquality'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 13, 'table' => $t, 'field' => 'first_seen', 'name' => __('İlk görülme', 'inventoryquality'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 14, 'table' => $t, 'field' => 'last_seen', 'name' => __('Son görülme', 'inventoryquality'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 15, 'table' => $t, 'field' => 'seen_count', 'name' => __('Tekrar sayısı', 'inventoryquality'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 16, 'table' => $t, 'field' => 'cycle', 'name' => __('Dönem', 'inventoryquality'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 17, 'table' => $t, 'field' => 'last_result', 'name' => __('Son sonuç', 'inventoryquality'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => __('Kurum birimi', 'inventoryquality'), 'datatype' => 'dropdown', 'massiveaction' => false],
        ];
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        $v = is_array($values) ? ($values[$field] ?? '') : $values;
        switch ($field) {
            case 'items_id':
                $it = is_array($values) ? (string) ($values['itemtype'] ?? '') : '';
                if ($it !== '' && class_exists($it)) {
                    $label = AssetAdapter::label($it, (int) $v);
                    return "<a href='" . htmlescape($it::getFormURLWithID((int) $v)) . "'>" . htmlescape($label) . '</a>';
                }
                return '#' . (int) $v;
            case 'itemtype':
                return class_exists((string) $v) ? htmlescape($v::getTypeName(1)) : htmlescape((string) $v);
            case 'status':
                return self::statusBadge((string) $v);
            case 'severity':
                return htmlescape(self::severityLabel((int) $v));
            case 'assign_state':
                return (string) $v === 'waiting' ? "<span class='badge bg-orange-lt'>" . __('Atama bekliyor', 'inventoryquality') . '</span>' : __('Atandı', 'inventoryquality');
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        $options['display'] = false;
        $v = is_array($values) ? ($values[$field] ?? '') : $values;
        switch ($field) {
            case 'status':
                return \Dropdown::showFromArray($name, self::statuses(), ['value' => $v] + $options);
            case 'severity':
                $sev = [];
                foreach (RuleTemplates::SEVERITIES as $k => $l) {
                    $sev[$k] = __($l, 'inventoryquality');
                }
                return \Dropdown::showFromArray($name, $sev, ['value' => $v] + $options);
            case 'assign_state':
                return \Dropdown::showFromArray($name, ['assigned' => __('Atandı', 'inventoryquality'), 'waiting' => __('Atama bekliyor', 'inventoryquality')], ['value' => $v] + $options);
            case 'itemtype':
                $types = [];
                foreach (AssetAdapter::supportedTypes() as $t) {
                    $types[$t] = $t::getTypeName(1);
                }
                return \Dropdown::showFromArray($name, $types, ['value' => $v] + $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    public static function key(int $entitiesId, string $itemtype, int $itemsId, int $rulesId, string $target): string
    {
        return hash('sha256', implode('|', [$entitiesId, $itemtype, $itemsId, $rulesId, $target]));
    }

    /** @return list<array<string,mixed>> */
    public static function cycles(int $findingsId): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => FindingService::CYCLES, 'WHERE' => ['findings_id' => $findingsId], 'ORDER' => 'cycle DESC']), false);
    }
}
