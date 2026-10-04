<?php

/**
 * Bulgu Detayı — beklenen / mevcut durum, dönem geçmişi, destek kayıtları, denetim izi ve işlemler:
 * Üstlen · Ata · Yeniden kontrol et · "Düzelttim, doğrula". Bulgu yalnız güncel veride kural PASS verince çözülür.
 */

use GlpiPlugin\Inventoryquality\AssetAdapter;
use GlpiPlugin\Inventoryquality\AssignmentResolver;
use GlpiPlugin\Inventoryquality\AttestationService;
use GlpiPlugin\Inventoryquality\AuditLog;
use GlpiPlugin\Inventoryquality\Catalog;
use GlpiPlugin\Inventoryquality\CorrectionService;
use GlpiPlugin\Inventoryquality\Db;
use GlpiPlugin\Inventoryquality\Evaluation;
use GlpiPlugin\Inventoryquality\Evidence;
use GlpiPlugin\Inventoryquality\ExceptionService;
use GlpiPlugin\Inventoryquality\Finding;
use GlpiPlugin\Inventoryquality\FindingService;
use GlpiPlugin\Inventoryquality\JobQueue;
use GlpiPlugin\Inventoryquality\Menu;
use GlpiPlugin\Inventoryquality\Rights;
use GlpiPlugin\Inventoryquality\Rule;
use GlpiPlugin\Inventoryquality\RuleEvaluator;
use GlpiPlugin\Inventoryquality\RuleTemplates;
use GlpiPlugin\Inventoryquality\ScanRunner;
use GlpiPlugin\Inventoryquality\TicketBridge;
use GlpiPlugin\Inventoryquality\Ui;

include('../../../inc/includes.php');

Session::checkRight(Rights::NAME, Rights::VIEW);

$id = (int) ($_REQUEST['id'] ?? 0);
$self = Menu::base() . '/finding.form.php?id=' . $id;
$finding = new Finding();
if ($id <= 0 || !$finding->getFromDB($id) || !$finding->canViewItem()) {
    throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
}
$f = $finding->fields;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $recheck = static function () use ($f): string {
            $res = AuditLog::as('service:recheck', static fn() => ScanRunner::recheckItem((string) $f['itemtype'], (int) $f['items_id']));
            JobQueue::enqueue('ticket_sync', 'ticket_sync:' . $f['itemtype'] . ':' . $f['items_id'], ['itemtype' => $f['itemtype'], 'items_id' => (int) $f['items_id']]);
            $now = Db::row(Finding::getTable(), ['id' => (int) $f['id']]);
            return sprintf(__('Yeniden kontrol edildi: %s → bulgu durumu "%s".', 'inventoryquality'), $res[(int) $f['rules_id']] ?? '—', Finding::statusLabel((string) ($now['status'] ?? '')));
        };
        if (isset($_POST['take'])) {
            FindingService::take($id);
            Ui::msg(__('Bulgu üstlenildi.', 'inventoryquality'));
        } elseif (isset($_POST['assign'])) {
            FindingService::assign($id, (int) ($_POST['users_id_assign'] ?? 0), (int) ($_POST['groups_id_assign'] ?? 0));
            Ui::msg(__('Atama kaydedildi.', 'inventoryquality'));
        } elseif (isset($_POST['recheck'])) {
            if (!Rights::has(Rights::SCAN) && !FindingService::isAssignee($f, (int) Session::getLoginUserID())) {
                throw new InvalidArgumentException(__('Yeniden kontrol yetkiniz yok.', 'inventoryquality'));
            }
            Ui::msg($recheck());
        } elseif (isset($_POST['mark_fixed'])) {
            FindingService::markFixed($id, trim((string) ($_POST['note'] ?? '')));
            Ui::msg($recheck());
        } elseif (isset($_POST['propose'])) {
            $ev = isset($_FILES['evidence']) ? Evidence::store($_FILES['evidence']) : null;
            try {
                $r = CorrectionService::propose($id, (string) ($_POST['field'] ?? ''), $_POST['new_value'] ?? '', (string) ($_POST['reason'] ?? ''), $ev);
            } catch (InvalidArgumentException $e) {
                if ($ev) {
                    @unlink(Evidence::dir() . '/' . $ev['file']);
                }
                throw $e;
            }
            if ($r['status'] === CorrectionService::APPLIED) {
                $msg = $recheck();
                Ui::msg(sprintf(__('Düzeltme #%d uygulandı. %s', 'inventoryquality'), $r['id'], $msg));
            } else {
                Ui::msg(sprintf(__('Düzeltme #%d: %s.', 'inventoryquality'), $r['id'], CorrectionService::statusLabel($r['status'])), in_array($r['status'], [CorrectionService::FAILED, CorrectionService::CONFLICT, CorrectionService::REVIEW], true));
            }
            Ui::back(Menu::base() . '/correction.form.php?id=' . $r['id']);
        } elseif (isset($_POST['grant_exception'])) {
            ExceptionService::grant($id, (string) ($_POST['ex_reason'] ?? ''), (string) ($_POST['ex_end'] ?? ''), (string) ($_POST['ex_comp'] ?? ''));
            Ui::msg(__('İstisna verildi. Bulgu çözülmüş sayılmaz; süre dolunca kayıt yeniden kontrol edilir.', 'inventoryquality'));
        } elseif (isset($_POST['revoke_exception'])) {
            ExceptionService::revoke((int) $_POST['revoke_exception'], (string) ($_POST['revoke_reason'] ?? ''));
            Ui::msg(__('İstisna geri alındı; kayıt yeniden kontrol edildi.', 'inventoryquality'));
        } elseif (isset($_POST['attest'])) {
            AttestationService::attest((string) $f['itemtype'], (int) $f['items_id'], (array) ($_POST['attest_fields'] ?? []), (string) ($_POST['attest_comment'] ?? ''));
            Ui::msg(__('Teyit kaydedildi.', 'inventoryquality') . ' ' . $recheck());
        }
    } catch (InvalidArgumentException $e) {
        Ui::msg($e->getMessage(), true);
    }
    Ui::back($self);
}

/** @var \DBmysql $DB */
global $DB;
$itemtype = (string) $f['itemtype'];
$rule = Db::row(Rule::getTable(), ['id' => (int) $f['rules_id']]) ?? ['code' => '?', 'name' => '', 'id' => 0];
$version = Rule::version((int) $f['ruleversions_id']);
$detail = Db::decode($f['detail']);
$observed = [];
foreach ((array) ($detail['observed'] ?? []) as $k => $s) {
    $observed[] = ['label' => Catalog::get($itemtype, (string) $k)['label'] ?? $k, 'value' => $s];
}
$cycles = [];
foreach (Finding::cycles($id) as $c) {
    $cycles[] = $c + ['opened' => Ui::dt($c['opened_at']), 'closed' => Ui::dt($c['closed_at']), 'status_label' => $c['close_status'] !== '' ? Finding::statusLabel((string) $c['close_status']) : ''];
}
$tickets = [];
foreach (TicketBridge::linksOf($id) as $l) {
    $t = new Ticket();
    $ok = $t->getFromDB((int) $l['tickets_id']);
    $tickets[] = [
        'id' => (int) $l['tickets_id'], 'url' => $ok ? Ticket::getFormURLWithID((int) $l['tickets_id']) : '',
        'name' => $ok ? (string) $t->fields['name'] : __('(silinmiş)', 'inventoryquality'),
        'status' => $ok ? Ticket::getStatus((int) $t->fields['status']) : '', 'cycle' => (int) $l['cycle'],
        'open' => (int) $l['is_open'] === 1, 'note' => (string) $l['close_note'], 'date' => Ui::dt($l['date_creation']),
    ];
}
$audit = [];
foreach (AuditLog::forObject(Finding::class, $id) as $a) {
    $audit[] = ['date' => Ui::dt($a['date']), 'action' => (string) $a['action'], 'actor' => AuditLog::actorLabel($a), 'reason' => (string) $a['reason']];
}
$eval = Evaluation::forItem($itemtype, (int) $f['items_id'])[(int) $f['rules_id']] ?? null;
$uid = (int) Session::getLoginUserID();
$eid = (int) $f['entities_id'];
$active = in_array($f['status'], Finding::ACTIVE, true);
$isAssignee = FindingService::isAssignee($f, $uid);

// 3. aşama: düzeltme önerisi, açık öneriler, istisna, iş sahibi teyidi.
$curVersion = Rule::version((int) ($rule['ruleversions_id'] ?? 0));
$targets = $curVersion ? CorrectionService::targetsFor($f, $curVersion['def']) : [];
$openCorr = [];
foreach ($DB->request(['FROM' => CorrectionService::TABLE, 'WHERE' => ['findings_id' => $id], 'ORDER' => 'id DESC', 'LIMIT' => 10]) as $c) {
    $ch = Db::decode($c['changes']);
    $openCorr[] = ['id' => (int) $c['id'], 'status' => CorrectionService::statusLabel((string) $c['status']), 'open' => in_array($c['status'], CorrectionService::OPEN, true),
        'date' => Ui::dt($c['date_creation']), 'user' => getUserName((int) $c['users_id']),
        'change' => implode('; ', array_map(static fn($k, $x) => (Catalog::get($itemtype, (string) $k)['label'] ?? $k) . ': ' . ($x['old_label'] ?? '') . ' → ' . ($x['new_label'] ?? ''), array_keys($ch), $ch))];
}
$hasOpenCorr = (bool) array_filter($openCorr, static fn($c) => $c['open']);
$propose = null;
if ($active && $targets && !$hasOpenCorr && Rights::has(Rights::PROPOSE)) {
    $field0 = (string) array_key_first($targets);
    $fd = $targets[$field0];
    $cur = AssetAdapter::readOne($itemtype, (int) $f['items_id'], [$field0]);
    $widget = match ($fd['datatype']) {
        'user'  => User::dropdown(['name' => 'new_value', 'right' => 'all', 'entity' => $eid, 'display' => false]),
        'fk'    => Dropdown::show($fd['ref_itemtype'], ['name' => 'new_value', 'entity' => $eid, 'display' => false]),
        'text'  => "<textarea class='form-control' name='new_value' rows='3' maxlength='65535'></textarea>",
        default => "<input class='form-control' name='new_value' maxlength='255'>",
    };
    $policy = $curVersion['def']['policy']['approval'] ?? ['level' => 'none'];
    $propose = [
        'field' => $field0, 'label' => $fd['label'], 'current' => RuleEvaluator::summarize($cur[$field0] ?? null), 'read_at' => Ui::dt(Db::now()),
        'widget' => $widget, 'policy' => RuleTemplates::approvalLabel($policy),
        'direct' => ($policy['level'] ?? 'none') === 'none' && Rights::has(Rights::APPLY),
        'types' => implode(', ', array_map('strtoupper', array_values(Evidence::TYPES))), 'max_mb' => Evidence::MAX_BYTES / 1048576,
    ];
}
$exception = ExceptionService::activeFor($id);
$attest = null;
if (($rule['template'] ?? '') === 'attestation' && $curVersion && AttestationService::canAttest($itemtype, (int) $f['items_id'], $uid)) {
    $fields = Catalog::attestList((string) $curVersion['def']['target']);
    $vals = AssetAdapter::readOne($itemtype, (int) $f['items_id'], $fields) ?? [];
    $attest = ['fields' => array_map(static fn($k) => ['key' => $k, 'label' => Catalog::get($itemtype, $k)['label'] ?? $k, 'value' => RuleEvaluator::summarize($vals[$k] ?? null)], $fields),
        'days' => (int) ($curVersion['def']['assert']['days'] ?? 0)];
}

Ui::header(Finding::getTypeName(1) . ' #' . $id, 'finding');
Ui::render('finding_detail.html.twig', [
    'f'           => $f,
    'self'        => $self,
    'badge'       => Finding::statusBadge((string) $f['status']),
    'severity'    => Finding::severityLabel((int) $f['severity']),
    'asset'       => ['label' => AssetAdapter::label($itemtype, (int) $f['items_id']), 'type' => class_exists($itemtype) ? $itemtype::getTypeName(1) : $itemtype,
                      'url' => class_exists($itemtype) ? $itemtype::getFormURLWithID((int) $f['items_id']) : ''],
    'entity'      => Dropdown::getDropdownName('glpi_entities', $eid),
    'rule'        => $rule,
    'version'     => (int) ($version['version'] ?? 0),
    'expected'    => $version ? RuleTemplates::describe($version['def'], $itemtype) : '',
    'help'        => RuleTemplates::all()[$rule['template'] ?? '']['help'] ?? '',
    'observed'    => $observed,
    'reason'      => (string) ($detail['reason'] ?? ''),
    'assign'      => [
        'user'   => (int) $f['users_id_assign'] > 0 ? getUserName((int) $f['users_id_assign']) : '',
        'group'  => (int) $f['groups_id_assign'] > 0 ? Dropdown::getDropdownName('glpi_groups', (int) $f['groups_id_assign']) : '',
        'reason' => AssignmentResolver::reasonLabel((string) $f['assign_reason']),
        'waiting' => $f['assign_state'] === 'waiting',
    ],
    'dates'       => ['first' => Ui::dt($f['first_seen']), 'last' => Ui::dt($f['last_seen']), 'check' => Ui::dt($f['last_check']), 'due' => Ui::dt($f['due_date']),
                      'overdue' => $active && $f['due_date'] && strtotime((string) $f['due_date']) < Db::time()],
    'eval'        => $eval ? ['badge' => Ui::resultBadge((string) $eval['result']), 'date' => Ui::dt($eval['evaluated_at']), 'error' => (string) $eval['error_code']] : null,
    'cycles'      => $cycles,
    'tickets'     => $tickets,
    'audit'       => $audit,
    'can'         => [
        'take'     => $active && in_array($f['status'], [Finding::OPEN, Finding::REVIEW], true) && ($isAssignee || Rights::has(Rights::ASSIGN)),
        'assign'   => $active && Rights::has(Rights::ASSIGN),
        'recheck'  => Rights::has(Rights::SCAN) || $isAssignee,
        'fixed'    => $active && ($isAssignee || Rights::has(Rights::SCAN)),
    ],
    'dd_user'     => Rights::has(Rights::ASSIGN) ? User::dropdown(['name' => 'users_id_assign', 'value' => (int) $f['users_id_assign'], 'right' => 'all', 'entity' => $eid, 'display' => false]) : '',
    'dd_group'    => Rights::has(Rights::ASSIGN) ? Group::dropdown(['name' => 'groups_id_assign', 'value' => (int) $f['groups_id_assign'], 'condition' => ['is_assign' => 1], 'entity' => $eid, 'display' => false]) : '',
    'propose'     => $propose,
    'no_correction_reason' => ($active && !$targets) ? (RuleTemplates::allowsCorrection((string) ($rule['template'] ?? '')) ? __('Bu alan eklenti içinden düzeltilemez; GLPI ekranından düzeltin.', 'inventoryquality')
        : (($rule['template'] ?? '') === 'freshness' ? __('Güncellik bulgusu için tarih elle ileri alınmaz: ajan / veri kaynağını inceleyin.', 'inventoryquality') : __('Bu bulgu iş sahibi teyidiyle kapanır.', 'inventoryquality'))) : '',
    'corrections' => $openCorr,
    'exception'   => $exception ? $exception + ['end' => Ui::dt($exception['end_date']), 'by' => getUserName((int) $exception['users_id'])] : null,
    'can_exception' => Rights::has(Rights::EXCEPTION) && $active && $f['last_result'] === RuleEvaluator::FAIL && !$exception,
    'can_revoke'  => Rights::has(Rights::EXCEPTION) && $exception !== null,
    'ex_min'      => date('Y-m-d', Db::time() + 86400),
    'ex_max'      => date('Y-m-d', Db::time() + ExceptionService::MAX_DAYS * 86400),
    'attest'      => $attest,
], 'finding');
Html::footer();
