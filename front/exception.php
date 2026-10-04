<?php

/**
 * İstisnalar — geçerli / süresi dolan / geri alınan süreli istisnalar (kurum birimi kısıtlı). Süre dolumu saatlik
 * otomatik işlemle (iqexpire) yeniden kontrol edilir.
 */

use GlpiPlugin\Inventoryquality\AssetAdapter;
use GlpiPlugin\Inventoryquality\Db;
use GlpiPlugin\Inventoryquality\ExceptionService;
use GlpiPlugin\Inventoryquality\Finding;
use GlpiPlugin\Inventoryquality\Menu;
use GlpiPlugin\Inventoryquality\Rights;
use GlpiPlugin\Inventoryquality\Rule;
use GlpiPlugin\Inventoryquality\Ui;

include('../../../inc/includes.php');

Session::checkRight(Rights::NAME, Rights::VIEW);
$self = Menu::base() . '/exception.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['revoke'])) {
    try {
        ExceptionService::revoke((int) $_POST['revoke'], (string) ($_POST['reason'] ?? ''));
        Ui::msg(__('İstisna geri alındı; kayıt yeniden kontrol edildi.', 'inventoryquality'));
    } catch (InvalidArgumentException $e) {
        Ui::msg($e->getMessage(), true);
    }
    Ui::back($self);
}

/** @var \DBmysql $DB */
global $DB;
$status = in_array($_GET['status'] ?? '', ['active', 'expired', 'revoked', 'ended', 'all'], true) ? (string) $_GET['status'] : 'active';
$T = ExceptionService::TABLE;
$where = getEntitiesRestrictCriteria($T, 'entities_id', '', false);
if ($status !== 'all') {
    $where[$T . '.status'] = $status;
}
$list = [];
foreach ($DB->request(['FROM' => $T, 'WHERE' => $where, 'ORDER' => 'end_date ASC', 'LIMIT' => 300]) as $e) {
    $f = Db::row(Finding::getTable(), ['id' => (int) $e['findings_id']]) ?? [];
    $rule = $f ? (Db::row(Rule::getTable(), ['id' => (int) $f['rules_id']]) ?? []) : [];
    $it = (string) ($f['itemtype'] ?? '');
    $list[] = $e + [
        'asset' => $f ? AssetAdapter::label($it, (int) $f['items_id']) : '—', 'rule' => (string) ($rule['code'] ?? ''),
        'end' => Ui::dt($e['end_date']), 'by' => getUserName((int) $e['users_id']), 'status_label' => ExceptionService::statusLabel((string) $e['status']),
        'soon' => $e['status'] === 'active' && strtotime((string) $e['end_date']) < Db::time() + 7 * 86400,
    ];
}

Ui::header(__('İstisnalar', 'inventoryquality'), 'exception');
Ui::render('exception_list.html.twig', [
    'self' => $self, 'status' => $status, 'list' => $list, 'can_revoke' => Rights::has(Rights::EXCEPTION),
], 'exception');
Html::footer();
