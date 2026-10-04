<?php

/**
 * İşler — taramalar (sürüyor / tamamlandı / kısmi / hatalı), iş kuyruğu, başarısız işlerin güvenli yeniden denenmesi,
 * otomatik görevlerin son çalışma bilgisi. "Tam tarama başlat" ve "Kuyruğu şimdi işle" Tarama yetkisi ister.
 */

use GlpiPlugin\Inventoryquality\AuditLog;
use GlpiPlugin\Inventoryquality\Cron;
use GlpiPlugin\Inventoryquality\Db;
use GlpiPlugin\Inventoryquality\JobQueue;
use GlpiPlugin\Inventoryquality\Menu;
use GlpiPlugin\Inventoryquality\Rights;
use GlpiPlugin\Inventoryquality\ScanRunner;
use GlpiPlugin\Inventoryquality\Ui;

include('../../../inc/includes.php');

Session::checkRight(Rights::NAME, Rights::VIEW);
$self = Menu::base() . '/jobs.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Session::checkRight(Rights::NAME, Rights::SCAN);
    if (isset($_POST['start_full'])) {
        $sid = ScanRunner::start('full', null, 'user');
        $t = AuditLog::as('service:manual', static fn() => ScanRunner::tick(20));
        Ui::msg(sprintf(__('Tam tarama #%d başlatıldı (%s). Kalan kısım otomatik görevle sürer.', 'inventoryquality'), $sid,
            $t['completed'] ? __('tamamlandı', 'inventoryquality') : __('sürüyor', 'inventoryquality')));
    } elseif (isset($_POST['process'])) {
        [$q, $t] = AuditLog::as('service:manual', static fn() => [JobQueue::process(20), ScanRunner::tick(20)]);
        Ui::msg(sprintf(__('%d iş işlendi, %d başarısız; %d tarama partisi.', 'inventoryquality'), $q['done'], $q['failed'], $t['slices']));
    } elseif (isset($_POST['retry'])) {
        JobQueue::retry((int) $_POST['retry']);
        Ui::msg(__('İş yeniden kuyruğa alındı.', 'inventoryquality'));
    }
    Ui::back($self);
}

/** @var \DBmysql $DB */
global $DB;
$scans = [];
foreach (ScanRunner::recent(25) as $s) {
    $rules = Db::decode($s['rules']);
    $scans[] = $s + ['badge' => Ui::scanStatus((string) $s['status']), 'started' => Ui::dt($s['started_at']), 'finished' => Ui::dt($s['finished_at']),
        'n_rules' => count($rules), 'actor_label' => $s['actor'] === 'user' ? getUserName((int) $s['users_id']) : (string) $s['actor']];
}
$failed = [];
foreach ($DB->request(['FROM' => JobQueue::TABLE, 'WHERE' => ['status' => ['failed', 'pending']], 'ORDER' => ['status', 'id DESC'], 'LIMIT' => 50]) as $j) {
    $failed[] = $j + ['next' => Ui::dt($j['next_run_at']), 'mod' => Ui::dt($j['date_mod'])];
}
$crons = [];
foreach (Cron::info() as $n => $c) {
    $crons[] = ['name' => $n, 'info' => Cron::cronInfo($n)['description'] ?? $n, 'c' => $c,
        'last' => $c ? Ui::dt($c['lastrun'] ?? null) : '', 'mode' => $c ? ((int) $c['mode'] === CronTask::MODE_EXTERNAL ? __('Harici (sunucu cron)', 'inventoryquality') : __('GLPI içi', 'inventoryquality')) : '',
        'url' => $c ? CronTask::getFormURLWithID((int) $c['id']) : ''];
}

Ui::header(__('İşler', 'inventoryquality'), 'jobs');
Ui::render('jobs.html.twig', [
    'self'     => $self,
    'can_scan' => Rights::has(Rights::SCAN),
    'scans'    => $scans,
    'stats'    => JobQueue::stats(),
    'jobs'     => $failed,
    'crons'    => $crons,
], 'jobs');
Html::footer();
