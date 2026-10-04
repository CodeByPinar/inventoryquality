<?php

namespace GlpiPlugin\Inventoryquality;

use CommonDBTM;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;

/**
 * Varlık sekmesi "Veri Kalitesi": varlığın bulguları, uygulanan kuralların son sonucu, değerlendirme kapsamı.
 */
class AssetTab extends CommonGLPI
{
    public static $rightname = Rights::NAME;

    public static function getTypeName($nb = 0)
    {
        return __('Veri Kalitesi', 'inventoryquality');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof CommonDBTM || !Rights::has(Rights::VIEW) || !AssetAdapter::isSupported($item->getType()) || $item->isNewItem()) {
            return '';
        }
        $n = countElementsInTable(Finding::getTable(), ['itemtype' => $item->getType(), 'items_id' => (int) $item->getID(), 'status' => Finding::ACTIVE]);
        return self::createTabEntry(self::getTypeName(), $n, null, 'ti ti-checklist');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof CommonDBTM || !Rights::has(Rights::VIEW) || !$item->canViewItem()) {
            return false;
        }
        global $DB;
        $type = $item->getType();
        $id = (int) $item->getID();
        $findings = [];
        foreach ($DB->request(['FROM' => Finding::getTable(), 'WHERE' => ['itemtype' => $type, 'items_id' => $id], 'ORDER' => ['status', 'id DESC']]) as $f) {
            $rule = Db::row(Rule::getTable(), ['id' => (int) $f['rules_id']]) ?? [];
            $findings[] = ['f' => $f, 'rule' => $rule, 'badge' => Finding::statusBadge((string) $f['status']), 'observed' => Db::decode($f['detail'])['observed'] ?? []];
        }
        $evals = [];
        foreach (Evaluation::forItem($type, $id) as $rid => $e) {
            $rule = Db::row(Rule::getTable(), ['id' => $rid]) ?? ['code' => '?', 'name' => ''];
            $v = Rule::version((int) $e['ruleversions_id']);
            $evals[] = ['e' => $e, 'rule' => $rule, 'version' => (int) ($v['version'] ?? 0), 'describe' => $v ? RuleTemplates::describe($v['def'], $type) : ''];
        }
        $score = QualityCalculator::compute(['itemtype' => $type, 'items_id' => $id], [(int) $item->fields['entities_id']]);
        // İş sahibi teyidi (DQ-08): uygulanan teyit kuralları, son teyitler, teyit formu.
        $asset = AssetAdapter::readOne($type, $id, []) ?? ['_meta' => ['entities_id' => (int) $item->fields['entities_id'], 'states_id' => (int) ($item->fields['states_id'] ?? 0)]];
        $attRules = AttestationService::rulesFor($type, $asset);
        $attFields = [];
        foreach ($attRules as $ar) {
            foreach ($ar['fields'] as $k) {
                $attFields[$k] = true;
            }
        }
        $vals = $attFields ? (AssetAdapter::readOne($type, $id, array_keys($attFields)) ?? []) : [];
        $attest = [
            'rules'  => array_map(static fn($ar) => ['code' => $ar['rule']['code'], 'days' => $ar['days'],
                'labels' => implode(', ', array_map(static fn($k) => Catalog::get($type, $k)['label'] ?? $k, $ar['fields']))], $attRules),
            'fields' => array_map(static fn($k) => ['key' => $k, 'label' => Catalog::get($type, $k)['label'] ?? $k, 'value' => RuleEvaluator::summarize($vals[$k] ?? null)], array_keys($attFields)),
            'recent' => array_map(static fn($a) => ['date' => (string) $a['date'], 'user' => getUserName((int) $a['users_id']),
                'fields' => implode(', ', array_map(static fn($k) => Catalog::get($type, $k)['label'] ?? $k, $a['fields_list'])), 'comment' => (string) $a['comment']],
                AttestationService::recent($type, $id, 5)),
            'can'    => $attFields && AttestationService::canAttest($type, $id, (int) \Session::getLoginUserID()),
        ];
        TemplateRenderer::getInstance()->display('@inventoryquality/asset_tab.html.twig', [
            'findings'  => $findings,
            'evals'     => $evals,
            'score'     => $score,
            'score_txt' => QualityCalculator::pct($score['score'], __('Hesaplanamadı', 'inventoryquality')),
            'cov_txt'   => QualityCalculator::pct($score['coverage'], __('Kapsam yok', 'inventoryquality')),
            'can_scan'  => Rights::has(Rights::SCAN),
            'base'      => Menu::base(),
            'itemtype'  => $type,
            'items_id'  => $id,
            'labels'    => Catalog::fields($type),
            'attest'    => $attest,
        ]);
        return true;
    }
}
