<?php

/**
 * Ayarlar — kurum birimi ayarları (veri kalitesi grubu, destek kaydı modu, talep sahibi, varsayılan süre),
 * genel ayarlar (parti boyutu, zaman bütçeleri) ve alan kataloğu.
 */

use GlpiPlugin\Inventoryquality\AssetAdapter;
use GlpiPlugin\Inventoryquality\Catalog;
use GlpiPlugin\Inventoryquality\Config;
use GlpiPlugin\Inventoryquality\EntityConfig;
use GlpiPlugin\Inventoryquality\Menu;
use GlpiPlugin\Inventoryquality\Rights;
use GlpiPlugin\Inventoryquality\Ui;

include('../../../inc/includes.php');

Session::checkRight(Rights::NAME, Rights::CONFIG);

$eid = (int) ($_REQUEST['entities_id'] ?? ($_SESSION['glpiactive_entity'] ?? 0));
if (!Session::haveAccessToEntity($eid)) {
    $eid = (int) ($_SESSION['glpiactive_entity'] ?? 0);
}
$self = Menu::base() . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (isset($_POST['save_entity']) && Session::haveAccessToEntity($eid)) {
        EntityConfig::save($eid, $_POST);
        Ui::msg(__('Birim ayarları kaydedildi.', 'inventoryquality'));
    } elseif (isset($_POST['inherit_entity']) && Session::haveAccessToEntity($eid) && $eid > 0) {
        EntityConfig::inherit($eid);
        Ui::msg(__('Birim artık üst birimin ayarlarını devralıyor.', 'inventoryquality'));
    } elseif (isset($_POST['save_global'])) {
        Config::set(array_intersect_key($_POST, Config::DEFAULTS));
        Ui::msg(__('Genel ayarlar kaydedildi.', 'inventoryquality'));
    } elseif (isset($_POST['sync_catalog'])) {
        Catalog::sync();
        Ui::msg(__('Alan kataloğu yenilendi; kurallar doğrulandı.', 'inventoryquality'));
    }
    Ui::back($self . '?entities_id=' . $eid);
}

/** @var \DBmysql $DB */
global $DB;
$eff = EntityConfig::effective($eid);
$own = EntityConfig::own($eid);
$catalog = [];
foreach (AssetAdapter::supportedTypes() as $t) {
    foreach ($DB->request(['FROM' => Catalog::TABLE, 'WHERE' => ['itemtype' => $t], 'ORDER' => 'field_key']) as $f) {
        $catalog[] = $f + ['type_label' => $t::getTypeName(1)];
    }
}
$candidates = [];
foreach (AssetAdapter::CANDIDATES as $t) {
    if (class_exists($t)) {
        $candidates[] = $t::getTypeName(1);
    }
}
$global = [];
foreach (Config::DEFAULTS as $k => $v) {
    $global[$k] = Config::get($k);
}

Ui::header(__('Ayarlar', 'inventoryquality'), 'config');
Ui::render('config.html.twig', [
    'self'       => $self,
    'eid'        => $eid,
    'entity'     => Dropdown::getDropdownName('glpi_entities', $eid),
    'own'        => $own !== null,
    'source'     => $eff['source_entities_id'] >= 0 ? Dropdown::getDropdownName('glpi_entities', (int) $eff['source_entities_id']) : __('varsayılan', 'inventoryquality'),
    'eff'        => $eff,
    'dd_entity'  => Entity::dropdown(['name' => 'entities_id', 'value' => $eid, 'entity' => $_SESSION['glpiactiveentities'] ?? [], 'display' => false, 'on_change' => 'this.form.submit()']),
    'dd_group'   => Group::dropdown(['name' => 'groups_id_quality', 'value' => (int) $eff['groups_id_quality'], 'condition' => ['is_assign' => 1], 'entity' => $eid, 'display' => false]),
    'dd_user'    => User::dropdown(['name' => 'users_id_requester', 'value' => (int) $eff['users_id_requester'], 'right' => 'all', 'entity' => $eid, 'display' => false]),
    'global'     => $global,
    'catalog'    => $catalog,
    'types'      => array_map(static fn($t) => $t::getTypeName(1), AssetAdapter::supportedTypes()),
    'candidates' => $candidates,
], 'config');
Html::footer();
