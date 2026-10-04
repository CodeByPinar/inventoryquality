<?php

/**
 * Düzeltmeler — onayımı bekleyenler, taleplerim ve tüm öneriler (kurum birimi kısıtlı).
 */

use GlpiPlugin\Inventoryquality\AssetAdapter;
use GlpiPlugin\Inventoryquality\Catalog;
use GlpiPlugin\Inventoryquality\CorrectionService;
use GlpiPlugin\Inventoryquality\Db;
use GlpiPlugin\Inventoryquality\Menu;
use GlpiPlugin\Inventoryquality\Rights;
use GlpiPlugin\Inventoryquality\Rule;
use GlpiPlugin\Inventoryquality\Ui;

include('../../../inc/includes.php');

Session::checkRight(Rights::NAME, Rights::VIEW);

/** @var \DBmysql $DB */
global $DB;
$uid = (int) Session::getLoginUserID();
$view = in_array($_GET['view'] ?? '', ['mine', 'requested', 'all'], true) ? (string) $_GET['view'] : 'mine';
$T = CorrectionService::TABLE;
$ent = getEntitiesRestrictCriteria($T, 'entities_id', '', false);
$rows = match ($view) {
    'mine'      => CorrectionService::waitingFor($uid),
    'requested' => iterator_to_array($DB->request(['FROM' => $T, 'WHERE' => array_merge(['users_id' => $uid], $ent), 'ORDER' => 'id DESC', 'LIMIT' => 200]), false),
    default     => iterator_to_array($DB->request(['FROM' => $T, 'WHERE' => $ent ?: [], 'ORDER' => 'id DESC', 'LIMIT' => 200]), false),
};
$list = [];
foreach ($rows as $c) {
    $it = (string) $c['itemtype'];
    $ch = Db::decode($c['changes']);
    $rule = Db::row(Rule::getTable(), ['id' => (int) $c['rules_id']]) ?? ['code' => '?'];
    $list[] = [
        'id' => (int) $c['id'], 'asset' => AssetAdapter::label($it, (int) $c['items_id']), 'asset_url' => class_exists($it) ? $it::getFormURLWithID((int) $c['items_id']) : '',
        'rule' => (string) $rule['code'], 'findings_id' => (int) $c['findings_id'],
        'change' => implode('; ', array_map(static fn($k, $x) => (Catalog::get($it, (string) $k)['label'] ?? $k) . ': ' . ($x['old_label'] ?? '') . ' → ' . ($x['new_label'] ?? ''), array_keys($ch), $ch)),
        'user' => getUserName((int) $c['users_id']), 'date' => Ui::dt($c['date_creation']), 'status' => CorrectionService::statusLabel((string) $c['status']), 'raw_status' => (string) $c['status'],
    ];
}

Ui::header(__('Düzeltmeler', 'inventoryquality'), 'correction');
Ui::render('correction_list.html.twig', [
    'view'  => $view,
    'list'  => $list,
    'self'  => Menu::base() . '/correction.php',
    'count_mine' => count(CorrectionService::waitingFor($uid)),
], 'correction');
Html::footer();
