<?php

/**
 * Düzeltme / Onay — önceki / yeni değer, anlık görüntü, gerekçe, kanıt, onay adımları ve kararlar.
 * İşlemler: Onayla · Gerekçeyle reddet · Uygula / Yeniden dene · İptal · Onaylayanı yeniden ata.
 */

use GlpiPlugin\Inventoryquality\AssetAdapter;
use GlpiPlugin\Inventoryquality\AuditLog;
use GlpiPlugin\Inventoryquality\Catalog;
use GlpiPlugin\Inventoryquality\CorrectionService;
use GlpiPlugin\Inventoryquality\Db;
use GlpiPlugin\Inventoryquality\JobQueue;
use GlpiPlugin\Inventoryquality\Menu;
use GlpiPlugin\Inventoryquality\Rights;
use GlpiPlugin\Inventoryquality\Rule;
use GlpiPlugin\Inventoryquality\RuleTemplates;
use GlpiPlugin\Inventoryquality\ScanRunner;
use GlpiPlugin\Inventoryquality\Ui;

include('../../../inc/includes.php');

Session::checkRight(Rights::NAME, Rights::VIEW);

$id = (int) ($_REQUEST['id'] ?? 0);
$self = Menu::base() . '/correction.form.php?id=' . $id;
try {
    $c = CorrectionService::load($id);
} catch (InvalidArgumentException $e) {
    throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $after = static function (string $status) use ($c): string {
            if ($status === CorrectionService::APPLIED) {
                AuditLog::as('service:recheck', static fn() => ScanRunner::recheckItem((string) $c['itemtype'], (int) $c['items_id']));
                JobQueue::enqueue('ticket_sync', 'ticket_sync:' . $c['itemtype'] . ':' . $c['items_id'], ['itemtype' => $c['itemtype'], 'items_id' => (int) $c['items_id']]);
            }
            return CorrectionService::statusLabel($status);
        };
        if (isset($_POST['approve'])) {
            Ui::msg(sprintf(__('Onaylandı → %s', 'inventoryquality'), $after(CorrectionService::decide($id, true, (string) ($_POST['comment'] ?? '')))));
        } elseif (isset($_POST['reject'])) {
            CorrectionService::decide($id, false, (string) ($_POST['comment'] ?? ''));
            Ui::msg(__('Reddedildi; envanter değişmedi, bulgu açık.', 'inventoryquality'));
        } elseif (isset($_POST['apply'])) {
            Ui::msg(sprintf(__('Sonuç: %s', 'inventoryquality'), $after(CorrectionService::apply($id))));
        } elseif (isset($_POST['cancel'])) {
            CorrectionService::cancel($id, (string) ($_POST['comment'] ?? ''));
            Ui::msg(__('Düzeltme iptal edildi.', 'inventoryquality'));
        } elseif (isset($_POST['reassign'])) {
            CorrectionService::reassign($id, (int) ($_POST['step'] ?? 1), (int) ($_POST['groups_id'] ?? 0), (int) ($_POST['users_id'] ?? 0), (string) ($_POST['mode'] ?? 'any'));
            Ui::msg(__('Onaylayan yeniden atandı.', 'inventoryquality'));
        }
    } catch (InvalidArgumentException $e) {
        Ui::msg($e->getMessage(), true);
    }
    Ui::back($self);
}

/** @var \DBmysql $DB */
global $DB;
$it = (string) $c['itemtype'];
$eid = (int) $c['entities_id'];
$uid = (int) Session::getLoginUserID();
$ch = Db::decode($c['changes']);
$snap = Db::decode($c['snapshot']);
$policy = Db::decode($c['policy']);
$rule = Db::row(Rule::getTable(), ['id' => (int) $c['rules_id']]) ?? ['code' => '?', 'name' => '', 'id' => 0];
$changes = [];
foreach ($ch as $k => $x) {
    $changes[] = ['label' => Catalog::get($it, (string) $k)['label'] ?? $k, 'old' => (string) ($x['old_label'] ?? ''), 'new' => (string) ($x['new_label'] ?? '')];
}
$approvals = [];
foreach (CorrectionService::approvals($id) as $a) {
    $target = $a['target_type'] === 'Group' ? Dropdown::getDropdownName('glpi_groups', (int) $a['target_id']) : getUserName((int) $a['target_id']);
    $approvals[] = ['step' => (int) $a['step'], 'target' => ($a['target_type'] === 'Group' ? __('Grup', 'inventoryquality') . ': ' : '') . $target,
        'mode' => $a['mode'] === 'all' ? __('herkes onaylamalı', 'inventoryquality') : __('biri yeterli', 'inventoryquality'),
        'status' => match ((string) $a['status']) {
            'waiting' => __('Bekliyor', 'inventoryquality'), 'pending' => __('Önceki adım bekleniyor', 'inventoryquality'), 'approved' => __('Onayladı', 'inventoryquality'),
            'rejected' => __('Reddetti', 'inventoryquality'), 'void' => __('Geçersiz', 'inventoryquality'), default => (string) $a['status'],
        },
        'by' => (int) $a['users_id'] > 0 ? getUserName((int) $a['users_id']) : '', 'date' => Ui::dt($a['decision_date']), 'comment' => (string) $a['comment']];
}
$audit = [];
foreach (AuditLog::forObject(CorrectionService::class, $id) as $a) {
    $audit[] = ['date' => Ui::dt($a['date']), 'action' => (string) $a['action'], 'actor' => AuditLog::actorLabel($a), 'reason' => (string) $a['reason']];
}
$steps = [];
foreach ((array) ($policy['steps'] ?? []) as $n => $sdef) {
    $steps[] = sprintf(__('%d. adım: %s%s (%s)', 'inventoryquality'), (int) $n,
        (int) ($sdef['groups_id'] ?? 0) > 0 ? __('grup', 'inventoryquality') . ' ' . Dropdown::getDropdownName('glpi_groups', (int) $sdef['groups_id']) : '',
        (int) ($sdef['users_id'] ?? 0) > 0 ? ' ' . __('kişi', 'inventoryquality') . ' ' . getUserName((int) $sdef['users_id']) : '',
        ($sdef['mode'] ?? 'any') === 'all' ? __('herkes', 'inventoryquality') : __('biri yeterli', 'inventoryquality'));
}
$open = in_array($c['status'], CorrectionService::OPEN, true);

Ui::header(__('Düzeltme', 'inventoryquality') . ' #' . $id, 'correction');
Ui::render('correction_form.html.twig', [
    'c'         => $c,
    'self'      => $self,
    'status'    => CorrectionService::statusLabel((string) $c['status']),
    'asset'     => ['label' => AssetAdapter::label($it, (int) $c['items_id']), 'url' => class_exists($it) ? $it::getFormURLWithID((int) $c['items_id']) : ''],
    'entity'    => Dropdown::getDropdownName('glpi_entities', $eid),
    'rule'      => $rule,
    'changes'   => $changes,
    'read_at'   => Ui::dt($snap['read_at'] ?? null),
    'requester' => getUserName((int) $c['users_id']),
    'date'      => Ui::dt($c['date_creation']),
    'applied'   => Ui::dt($c['applied_at']),
    'policy'    => RuleTemplates::approvalLabel($policy) . (!empty($policy['allow_self']) ? ' · ' . __('kendi talebini onaylama açık', 'inventoryquality') : ''),
    'steps'     => $steps,
    'approvals' => $approvals,
    'audit'     => $audit,
    'evidence'  => $c['evidence_file'] !== '' ? ['name' => (string) $c['evidence_name'], 'size' => round((int) $c['evidence_size'] / 1024), 'url' => Menu::base() . '/evidence.php?id=' . $id] : null,
    'can'       => [
        'decide'   => CorrectionService::decisionRow($c, $uid) !== null,
        'apply'    => CorrectionService::canApply($c),
        'cancel'   => $open && ((int) $c['users_id'] === $uid || Rights::has(Rights::ASSIGN)),
        'reassign' => in_array($c['status'], [CorrectionService::PENDING_APPROVAL, CorrectionService::REVIEW], true) && Rights::has(Rights::ASSIGN) && $steps,
    ],
    'step_nums' => array_keys((array) ($policy['steps'] ?? [])),
    'dd_group'  => Group::dropdown(['name' => 'groups_id', 'entity' => $eid, 'display' => false]),
    'dd_user'   => User::dropdown(['name' => 'users_id', 'right' => 'all', 'entity' => $eid, 'display' => false]),
], 'correction');
Html::footer();
