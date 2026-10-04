<?php

/**
 * Kurallar — şablondan yeni kural (DQ-01…DQ-06) ve kural listesi.
 */

use GlpiPlugin\Inventoryquality\AssetAdapter;
use GlpiPlugin\Inventoryquality\Menu;
use GlpiPlugin\Inventoryquality\Rights;
use GlpiPlugin\Inventoryquality\Rule;
use GlpiPlugin\Inventoryquality\RuleTemplates;
use GlpiPlugin\Inventoryquality\Ui;

include('../../../inc/includes.php');

Session::checkRight(Rights::NAME, Rights::VIEW);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['create'])) {
    Session::checkRight(Rights::NAME, Rights::RULES);
    $eid = (int) ($_POST['entities_id'] ?? 0);
    try {
        if (!Session::haveAccessToEntity($eid)) {
            throw new InvalidArgumentException(__('Bu kurum birimine erişiminiz yok.', 'inventoryquality'));
        }
        $id = Rule::create((string) ($_POST['template'] ?? ''), (string) ($_POST['code'] ?? ''), (string) ($_POST['name'] ?? ''),
            (string) ($_POST['itemtype'] ?? ''), $eid, !empty($_POST['is_recursive']));
        Ui::msg(__('Kural oluşturuldu. Tanımı doldurup önizleyin, ardından yayınlayın ve etkinleştirin.', 'inventoryquality'));
        Ui::back(Menu::base() . '/rule.form.php?id=' . $id);
    } catch (InvalidArgumentException $e) {
        Ui::msg($e->getMessage(), true);
        Ui::back(Menu::base() . '/rule.php');
    }
}

$templates = [];
foreach (RuleTemplates::all() as $k => $t) {
    $code = $t['code'];
    $n = 1;
    while (countElementsInTable(Rule::getTable(), ['code' => $code]) > 0) {
        $code = $t['code'] . '-' . (++$n);
    }
    $templates[$k] = $t + ['suggest' => $code];
}
$types = [];
foreach (AssetAdapter::supportedTypes() as $t) {
    $types[$t] = $t::getTypeName(1);
}
$candidates = [];
foreach (AssetAdapter::CANDIDATES as $t) {
    if (class_exists($t)) {
        $candidates[] = $t::getTypeName(1);
    }
}

Ui::header(Rule::getTypeName(2), 'rule');
Ui::render('rule_list.html.twig', [
    'can_create' => Rights::has(Rights::RULES),
    'open_new'   => !empty($_GET['new']),
    'templates'  => $templates,
    'types'      => $types,
    'candidates' => $candidates,
    'dd_entity'  => Entity::dropdown(['name' => 'entities_id', 'value' => (int) ($_SESSION['glpiactive_entity'] ?? 0), 'entity' => $_SESSION['glpiactiveentities'] ?? [], 'display' => false]),
    'self'       => Menu::base() . '/rule.php',
], 'rule');
echo Html::scriptBlock("document.querySelectorAll('input.iq-tpl').forEach(function(r){r.addEventListener('change',function(){var f=r.form;f.code.value=r.dataset.code;f.name.value=r.dataset.label;});});");
Search::show(Rule::class);
Html::footer();
