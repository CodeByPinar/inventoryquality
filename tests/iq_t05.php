<?php
// T05 — birim yalıtımı: ayrı süreçte YALNIZ B biriminde profili olan kullanıcıyla.
// Kullanım: php iq_t05.php <kullanıcı> <A bulgusu> <B bulgusu> <B birimi>
require __DIR__ . '/iq_boot.php';

use GlpiPlugin\Inventoryquality\Finding;
use GlpiPlugin\Inventoryquality\FindingService;
use GlpiPlugin\Inventoryquality\QualityCalculator;

global $DB;
[, $uid, $fa, $fb, $eB] = array_map('intval', $argv);
iq_login($uid);
$c = QualityCalculator::findingCounts();
$rows = iterator_to_array($DB->request(['SELECT' => ['entities_id'], 'FROM' => Finding::getTable(), 'WHERE' => getEntitiesRestrictCriteria(Finding::getTable(), 'entities_id', '', false)]), false);
$ents = array_unique(array_map(static fn($r) => (int) $r['entities_id'], $rows));
iq_ok($rows && $ents === [$eB], 'T05 sayaç / liste ölçütü yalnız B birimi (' . count($rows) . ' bulgu, aktif ' . $c['active'] . ')');
try {
    FindingService::loadForAction($fa);
    iq_ok(false, 'T05 başka birimin bulgusu açılmamalı');
} catch (InvalidArgumentException $e) {
    iq_ok(true, 'T05 başka birimin bulgusu kimlikle istenince "bulunamadı"');
}
$f = new Finding();
iq_ok($f->getFromDB($fa) && !$f->canViewItem(), 'T05 Finding::canViewItem başka birimde false');
$s = Search::getDatas(Finding::class, ['criteria' => [], 'reset' => 'reset']);
$ids = array_map(static fn($r) => (int) $r['id'], $s['data']['rows'] ?? []);
iq_ok(!in_array($fa, $ids, true) && in_array($fb, $ids, true), 'T05 GLPI arama (Bulgular listesi) birim kısıtlı: ' . count($ids) . ' satır');
$sc = QualityCalculator::compute();
iq_ok(($sc['pass'] + $sc['fail'] + $sc['unknown']) > 0, 'T05 puan yalnız erişilen birimin değerlendirmelerinden');
