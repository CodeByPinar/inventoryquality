<?php

/**
 * Genel Bakış — puan + kapsam, açık / gecikmiş bulgular, kural bazında sonuçlar, yaş dağılımı, tarama sağlığı.
 * GLPI'nin etkin kurum birimi seçimi bütün bileşenlere uygulanır.
 */

use GlpiPlugin\Inventoryquality\CorrectionService;
use GlpiPlugin\Inventoryquality\Cron;
use GlpiPlugin\Inventoryquality\Db;
use GlpiPlugin\Inventoryquality\ExceptionService;
use GlpiPlugin\Inventoryquality\Finding;
use GlpiPlugin\Inventoryquality\JobQueue;
use GlpiPlugin\Inventoryquality\QualityCalculator;
use GlpiPlugin\Inventoryquality\Rights;
use GlpiPlugin\Inventoryquality\Rule;
use GlpiPlugin\Inventoryquality\ScanRunner;
use GlpiPlugin\Inventoryquality\Ui;

include('../../../inc/includes.php');

Session::checkRight(Rights::NAME, Rights::VIEW);

/** @var \DBmysql $DB */
global $DB;
$score = QualityCalculator::compute();
$counts = QualityCalculator::findingCounts();
$byRule = [];
foreach (QualityCalculator::byRule() as $r) {
    $r['score_txt'] = QualityCalculator::pct($r['score'], '—');
    $r['cov_txt'] = QualityCalculator::pct($r['coverage'], '—');
    $r['url'] = Ui::findingsUrl([[4, 'contains', $r['rule']['code']], [6, 'equals', Finding::OPEN]]);
    $byRule[] = $r;
}
$ages = QualityCalculator::ageBuckets();
$last = ScanRunner::lastCompletedFull();
$running = countElementsInTable(ScanRunner::TABLE, ['status' => 'running']);
$suspended = countElementsInTable(Rule::getTable(), ['suspended_reason' => ['<>', ''], 'ruleversions_id' => ['>', 0]]);
$crons = [];
foreach (Cron::info() as $n => $c) {
    $crons[$n] = $c ? ['lastrun' => Ui::dt($c['lastrun'] ?? null)] : null;
}

$CT = CorrectionService::TABLE;
$ET = ExceptionService::TABLE;
$stage3 = [
    'mine'        => count(CorrectionService::waitingFor((int) Session::getLoginUserID())),
    'pending'     => countElementsInTable($CT, array_merge(['status' => [CorrectionService::PENDING_APPROVAL, CorrectionService::APPROVED, CorrectionService::REVIEW]], getEntitiesRestrictCriteria($CT, 'entities_id', '', false))),
    'problem'     => countElementsInTable($CT, array_merge(['status' => [CorrectionService::FAILED, CorrectionService::CONFLICT]], getEntitiesRestrictCriteria($CT, 'entities_id', '', false))),
    'exc_active'  => countElementsInTable($ET, array_merge(['status' => 'active'], getEntitiesRestrictCriteria($ET, 'entities_id', '', false))),
    'exc_expired' => countElementsInTable($ET, array_merge(['status' => 'expired', 'date_end' => ['>', date('Y-m-d H:i:s', Db::time() - 30 * 86400)]], getEntitiesRestrictCriteria($ET, 'entities_id', '', false))),
    'can_export'  => Rights::has(Rights::EXPORT),
];

Ui::header(__('Envanter Veri Kalitesi', 'inventoryquality'), 'overview');
Ui::render('overview.html.twig', [
    'score'          => $score,
    'score_txt'      => QualityCalculator::pct($score['score'], __('Hesaplanamadı', 'inventoryquality')),
    'cov_txt'        => QualityCalculator::pct($score['coverage'], __('Kapsam yok', 'inventoryquality')),
    'counts'         => $counts,
    'by_rule'        => $byRule,
    'ages'           => $ages,
    'age_max'        => max($ages),
    'last_evaluated' => Ui::dt($score['last_evaluated']),
    'last_full'      => $last ? ['finished' => Ui::dt($last['finished_at']), 'items' => (int) $last['items_seen']] : null,
    'running'        => $running,
    'suspended'      => $suspended,
    'jobs'           => JobQueue::stats(),
    'crons'          => $crons,
    'can_config'     => Rights::has(Rights::CONFIG),
    's3'             => $stage3,
    'urls'           => [
        'active'  => Ui::findingsUrl([[6, 'equals', Finding::OPEN]]),
        'overdue' => Ui::findingsUrl([[11, 'lessthan', 'NOW'], [6, 'notequals', Finding::RESOLVED], [6, 'notequals', Finding::OUT_OF_SCOPE], [6, 'notequals', Finding::EXCEPTION]]),
        'review'  => Ui::findingsUrl([[6, 'equals', Finding::REVIEW]]),
        'waiting' => Ui::findingsUrl([[10, 'equals', 'waiting']]),
    ],
], 'overview');
Html::footer();
