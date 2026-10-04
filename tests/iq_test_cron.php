<?php
// Otomatik görevlerin GLPI'nin GERÇEK cron çalıştırıcısıyla (front/cron.php --force) testi.
require __DIR__ . '/iq_boot.php';
require __DIR__ . '/iq_seed.php';

use GlpiPlugin\Inventoryquality\AuditLog;
use GlpiPlugin\Inventoryquality\Finding;
use GlpiPlugin\Inventoryquality\Rule;
use GlpiPlugin\Inventoryquality\RuleTemplates;
use GlpiPlugin\Inventoryquality\ScanRunner;

global $DB;
iq_login(2);
iq_cleanup_glpi();
iq_wipe();
$run = static function (string $task): string {
    $out = [];
    exec('cd ' . escapeshellarg(IQ_ROOT) . ' && php front/cron.php --force ' . escapeshellarg($task) . ' 2>&1', $out, $rc);
    return "rc=$rc " . implode(' ', array_slice($out, -3));
};
$lastLog = static function (string $task) {
    global $DB;
    $t = new CronTask();
    $t->getFromDBbyName('GlpiPlugin\\Inventoryquality\\Cron', $task);
    return $DB->request(['FROM' => 'glpi_crontasklogs', 'WHERE' => ['crontasks_id' => (int) $t->getID()], 'ORDER' => 'id DESC', 'LIMIT' => 3]);
};
try {
    $s = iq_seed();
    $DB->delete(JobQueue_table(), ['id' => ['>', 0]]); // seed olaylarını boşalt: tarama cron'dan gelsin
    $rid = Rule::create('required', 'CRON-01', '', 'Computer', 0, true);
    Rule::publish($rid, RuleTemplates::build('required', 'Computer', ['field' => 'core:locations_id']), 2, 2);
    $DB->update(Rule::getTable(), ['is_active' => 1], ['id' => $rid]); // doğrudan: scan_rule işi oluşmasın
    $before = (int) $DB->request(['SELECT' => ['MAX' => 'id AS m'], 'FROM' => 'glpi_crontasklogs'])->current()['m'];

    $o = $run('iqscan');
    $scan = $DB->request(['FROM' => ScanRunner::TABLE, 'WHERE' => ['kind' => 'full'], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current();
    iq_ok($scan && $scan['status'] === 'completed' && str_starts_with((string) $scan['actor'], 'cron:'), 'front/cron.php --force iqscan → tam tarama tamamlandı (başlatan ' . ($scan['actor'] ?? '?') . ") · $o");
    $nf = countElementsInTable(Finding::getTable(), ['rules_id' => $rid]);
    iq_ok($nf === 3, "cron taraması bulgu açtı ($nf; boş konumlu 3 bilgisayar)");
    iq_ok(countElementsInTable(AuditLog::TABLE, ['actor' => 'cron:iqscan', 'action' => 'finding_open']) === 3, 'bulgular servis kimliğiyle (cron:iqscan) kaydedildi');

    (new Computer())->update(['id' => $s['c42'], 'locations_id' => $s['loc']]);
    $o = $run('iqqueue');
    $f = $DB->request(['FROM' => Finding::getTable(), 'WHERE' => ['items_id' => $s['c42'], 'rules_id' => $rid]])->current();
    iq_ok($f && $f['status'] === Finding::RESOLVED, "front/cron.php --force iqqueue → olay kuyruğu: düzeltilen kayıt yeniden kontrol edildi ve çözüldü · $o");

    $logs = iterator_to_array($DB->request(['FROM' => 'glpi_crontasklogs', 'WHERE' => ['id' => ['>', $before], 'state' => 2]]), false);
    iq_ok(count($logs) >= 2, 'GLPI görev günlüğünde iki görevin de bitiş kaydı var (' . count($logs) . ')');
    $ct = new CronTask();
    iq_ok($ct->getFromDBbyName('GlpiPlugin\\Inventoryquality\\Cron', 'iqqueue') && (int) $ct->fields['mode'] === CronTask::MODE_EXTERNAL && $ct->fields['lastrun'] !== null, 'iqqueue harici modda, son çalışma kaydedildi');
} catch (Throwable $e) {
    iq_ok(false, 'İSTİSNA: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    iq_wipe();
    iq_cleanup_glpi();
}
iq_done();

function JobQueue_table(): string
{
    return \GlpiPlugin\Inventoryquality\JobQueue::TABLE;
}
