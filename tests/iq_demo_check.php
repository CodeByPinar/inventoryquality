<?php
// Demo verisi üzerinde doğrulama + örnek akışlar (teyit, istisna, düzeltme önerisi). Yalnız "IQ Demo Birimi".
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING & ~E_USER_DEPRECATED);
$root = getenv('GLPI_ROOT_DIR') ?: '/var/www/glpi';
chdir($root);
define('GLPI_CACHE_DIR', '/tmp/glpicache');
define('GLPI_LOG_DIR', '/tmp/glpilog');
$_SERVER['REQUEST_URI'] = '/';
require $root . '/vendor/autoload.php';
(new \Glpi\Kernel\Kernel('production'))->boot();

use GlpiPlugin\Inventoryquality\AttestationService;
use GlpiPlugin\Inventoryquality\AuditLog;
use GlpiPlugin\Inventoryquality\CorrectionService;
use GlpiPlugin\Inventoryquality\Db;
use GlpiPlugin\Inventoryquality\Evaluation;
use GlpiPlugin\Inventoryquality\ExceptionService;
use GlpiPlugin\Inventoryquality\Finding;
use GlpiPlugin\Inventoryquality\Install;
use GlpiPlugin\Inventoryquality\JobQueue;
use GlpiPlugin\Inventoryquality\QualityCalculator;
use GlpiPlugin\Inventoryquality\Rule;
use GlpiPlugin\Inventoryquality\ScanRunner;

global $DB;
$admin = $argv[1] ?? 'btadmin';
$u = $DB->request(['FROM' => 'glpi_users', 'WHERE' => ['name' => $admin]])->current();
$auth = new Auth();
$auth->auth_succeded = true;
$auth->user = new User();
$auth->user->getFromDB((int) $u['id']);
Session::init($auth);
$e = (int) ($DB->request(['FROM' => 'glpi_entities', 'WHERE' => ['name' => 'IQ Demo Birimi']])->current()['id'] ?? 0);
if ($e <= 0) {
    exit("demo birimi yok\n");
}
$fail = 0;
$ok = static function (bool $c, string $m) use (&$fail): void {
    echo ($c ? '  OK   ' : '  FAIL ') . $m . "\n";
    $fail += $c ? 0 : 1;
};
$comp = static fn(string $n) => (int) ($DB->request(['FROM' => 'glpi_computers', 'WHERE' => ['name' => "IQ-DEMO $n"]])->current()['id'] ?? 0);
$rule = static fn(string $code) => (int) ($DB->request(['FROM' => Rule::getTable(), 'WHERE' => ['code' => $code]])->current()['id'] ?? 0);
$f = static function (string $n, string $code) use ($comp, $rule): ?array {
    global $DB;
    return $DB->request(['FROM' => Finding::getTable(), 'WHERE' => ['itemtype' => 'Computer', 'items_id' => $comp($n), 'rules_id' => $rule($code)]])->current() ?: null;
};

echo "== Kural bazında sonuç (yalnız demo birimi)\n";
foreach (QualityCalculator::byRule([$e]) as $r) {
    printf("  %-9s %-48s puan %-6s kapsam %-6s PASS %d FAIL %d UNKNOWN %d açık bulgu %d\n", $r['rule']['code'], $r['rule']['name'],
        QualityCalculator::pct($r['score'], '—'), QualityCalculator::pct($r['coverage'], '—'), $r['n']['PASS'] ?? 0, $r['n']['FAIL'] ?? 0, $r['n']['UNKNOWN'] ?? 0, $r['open']);
}
$sc = QualityCalculator::compute([], [$e]);
echo '  BİRİM: puan ' . QualityCalculator::pct($sc['score'], '—') . ' · kapsam ' . QualityCalculator::pct($sc['coverage'], '—') . "\n";
$cnt = QualityCalculator::findingCounts([$e]);
echo "  bulgular: aktif {$cnt['active']} · atama bekliyor {$cnt['waiting']}\n";

echo "== Beklenen sonuçlar\n";
$ok($f('LT-042', 'DEMO-01') !== null && $f('LT-042', 'DEMO-03') !== null && $f('LT-042', 'DEMO-02') !== null, 'LT-042: konum boş, kullanıcı ayrılmış (pasif), sorumlu yok → 3 bulgu');
$ok($f('LT-043', 'DEMO-01') === null && $f('LT-043', 'DEMO-02') === null && $f('LT-043', 'DEMO-07') === null, 'LT-043: düzgün kayıt (konum, teknik grup, ajan dün) → konum / sorumlu / güncellik bulgusu yok');
$ok($f('LT-045', 'DEMO-01B') !== null, 'LT-045: seri numarası boş → bulgu');
$ok($f('PC-103', 'DEMO-05') === null && (Evaluation::forItem('Computer', $comp('PC-103'))[$rule('DEMO-05')]['result'] ?? '') === 'NOT_APPLICABLE', 'PC-103: depoda → "kullanımdaysa konum" kuralı uygulanmaz');
$ok($f('SRV-02', 'DEMO-07') !== null && $f('PC-102', 'DEMO-07') !== null && $f('SRV-01', 'DEMO-07') === null, 'güncellik: SRV-02 (45 gün) ve PC-102 (60 gün) uygunsuz, SRV-01 (bugün) uygun');
$ok($f('ESKI-07', 'DEMO-02') === null && $f('ESKI-07', 'DEMO-03') !== null, 'ESKI-07: hurdaya ayrılacak → sorumlu kuralı kapsam dışı; pasif kullanıcı bulgusu var');
$f42 = $f('LT-042', 'DEMO-03');
$ok($f42 && (int) $f42['users_id_assign'] !== (int) $DB->request(['FROM' => 'glpi_users', 'WHERE' => ['name' => 'iqdemo_eski']])->current()['id'] && $f42['assign_state'] === 'assigned', 'pasif kullanıcı bulgusu pasif kişiye değil sorumlu gruba atandı');
$tk = [];
foreach ($DB->request(['SELECT' => ['tickets_id'], 'FROM' => Install::P . 'ticketlinks', 'WHERE' => ['is_open' => 1], 'GROUPBY' => 'tickets_id']) as $l) {
    $t = new Ticket();
    if ($t->getFromDB((int) $l['tickets_id']) && (int) $t->fields['entities_id'] === $e) {
        $tk[] = '#' . $t->getID() . ' ' . $t->fields['name'];
    }
}
$ok(count($tk) > 0, count($tk) . ' düzeltme destek kaydı açıldı (varlık + sorumlu başına bir): ' . implode(' | ', array_slice($tk, 0, 4)) . (count($tk) > 4 ? ' …' : ''));

echo "== Akışlar\n";
// 1) GLPI ekranından düzeltme gibi: LT-042 konumu girildi → olay kuyruğu → yeniden kontrol
$loc = (int) $DB->request(['FROM' => 'glpi_locations', 'WHERE' => ['name' => 'IQ-DEMO Genel Müdürlük 3. Kat']])->current()['id'];
(new Computer())->update(['id' => $comp('LT-042'), 'locations_id' => $loc]);
AuditLog::as('cron:iqqueue', static fn() => JobQueue::process(60));
$ok($f('LT-042', 'DEMO-01')['status'] === Finding::RESOLVED && $f('LT-042', 'DEMO-05')['status'] === Finding::RESOLVED, 'LT-042 konum girildi → iki konum bulgusu doğrulanarak çözüldü');
// 2) İş sahibi teyidi
$id44 = $comp('LT-044');
AttestationService::attest('Computer', $id44, ['core:users_id', 'core:locations_id'], 'demo teyidi');
AuditLog::as('service:recheck', static fn() => ScanRunner::recheckItem('Computer', $id44));
$ok($f('LT-044', 'DEMO-08')['status'] === Finding::RESOLVED, 'LT-044 kullanıcı + konum teyit edildi → teyit bulgusu çözüldü');
// 3) İstisna
$fx = $f('SRV-02', 'DEMO-07');
ExceptionService::grant((int) $fx['id'], 'Sunucu bakım penceresinde; ajan yeniden kurulacak', date('Y-m-d', time() + 14 * 86400), 'Haftalık elle kontrol');
$ok($f('SRV-02', 'DEMO-07')['status'] === Finding::EXCEPTION, 'SRV-02 güncellik bulgusuna 14 günlük istisna verildi (puan değişmez)');
// 4) Onaylı düzeltme önerisi: öneren tek onaylayan olduğu için "İnceleme gerekli" (kendi onayı kapalı) — beklenen davranış
$f45 = $f('LT-045', 'DEMO-01');
$res = CorrectionService::propose((int) $f45['id'], 'core:locations_id', $loc, 'Demo: sayımda 3. katta görüldü');
$ok($res['status'] === CorrectionService::REVIEW, 'LT-045 konum önerisi: onay grubunda öneren dışında kimse yok → "İnceleme gerekli" (kendi talebini onaylama kapalı)');
echo "  düzeltme #{$res['id']} — Düzeltmeler ekranından onaylayan yeniden atanabilir ya da iptal edilebilir\n";
JobQueue::process(60);
echo $fail ? "BAŞARISIZ: $fail\n" : "TÜMÜ GEÇTİ\n";
