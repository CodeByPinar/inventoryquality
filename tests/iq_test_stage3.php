<?php
// inventoryquality 0.2.0 — 3. aşama testleri: DQ-07/08, düzeltme + onay, anlık görüntü / çakışma, atomiklik,
// istisna, güvenli CSV, kanıt eki. AYRI test kurulumu (~/iq/glpi, glpi11iq).
require __DIR__ . '/iq_boot.php';
require __DIR__ . '/iq_seed.php';

use GlpiPlugin\Inventoryquality\AttestationService;
use GlpiPlugin\Inventoryquality\AuditLog;
use GlpiPlugin\Inventoryquality\CorrectionProcessor;
use GlpiPlugin\Inventoryquality\CorrectionService as CS;
use GlpiPlugin\Inventoryquality\Db;
use GlpiPlugin\Inventoryquality\EntityConfig;
use GlpiPlugin\Inventoryquality\Evaluation;
use GlpiPlugin\Inventoryquality\Evidence;
use GlpiPlugin\Inventoryquality\ExceptionService;
use GlpiPlugin\Inventoryquality\Export;
use GlpiPlugin\Inventoryquality\Finding;
use GlpiPlugin\Inventoryquality\Rights;
use GlpiPlugin\Inventoryquality\Rule;
use GlpiPlugin\Inventoryquality\RuleEvaluator;
use GlpiPlugin\Inventoryquality\RuleTemplates;
use GlpiPlugin\Inventoryquality\ScanRunner;
use GlpiPlugin\Inventoryquality\TicketBridge;

global $DB;
iq_login(2);
$ALL = $_SESSION['glpiactiveprofile'][Rights::NAME] ?? Rights::ALL;

/** Aynı süreçte kullanıcı değiştir (oturum kimliği + eklenti hakları; profil / birimler aynı kalır). */
function iq_su(int $uid, ?int $rights = null): void
{
    global $ALL;
    $_SESSION['glpiID'] = $uid;
    $_SESSION['glpiname'] = (string) (Db::row('glpi_users', ['id' => $uid])['name'] ?? 'x');
    $_SESSION['glpiactiveprofile'][Rights::NAME] = $rights ?? $ALL;
}
function f3(int $cid, int $rid): ?array
{
    global $DB;
    return $DB->request(['FROM' => Finding::getTable(), 'WHERE' => ['itemtype' => 'Computer', 'items_id' => $cid, 'rules_id' => $rid]])->current() ?: null;
}
function rule3(string $tpl, string $code, array $in, bool $active = true): int
{
    $id = Rule::create($tpl, $code, '', 'Computer', 0, true);
    Rule::publish($id, RuleTemplates::build($tpl, 'Computer', $in), 2, 2, 'test');
    if ($active) {
        Rule::setActive($id, true);
    }
    return $id;
}
function scanAll(): void
{
    queue();
    ScanRunner::start('full', null, 'test');
    while (ScanRunner::tick(30)['slices'] > 0) {
    }
    queue();
}
function comp(int $id): array
{
    global $DB;
    return $DB->request(['FROM' => 'glpi_computers', 'WHERE' => ['id' => $id]])->current();
}
function raises(callable $fn, string $needle = ''): bool
{
    try {
        $fn();
        return false;
    } catch (InvalidArgumentException $e) {
        return $needle === '' || str_contains($e->getMessage(), $needle);
    }
}

iq_cleanup_glpi();
iq_wipe();
$DB->delete('glpi_agents', ['deviceid' => ['LIKE', 'iqt-%']]);
try {
    $s = iq_seed();
    $loc2 = (new Location())->add(['name' => 'IQT Oda 2', 'entities_id' => 0, 'is_recursive' => 1]);
    $loc3 = (new Location())->add(['name' => 'IQT Oda 3', 'entities_id' => 0, 'is_recursive' => 1]);
    $ap1 = (new User())->add(['name' => 'iqt_ap1', 'is_active' => 1, '_profiles_id' => 4, '_entities_id' => 0, '_is_recursive' => 1]);
    $ap2 = (new User())->add(['name' => 'iqt_ap2', 'is_active' => 1, '_profiles_id' => 4, '_entities_id' => 0, '_is_recursive' => 1]);
    $ap3 = (new User())->add(['name' => 'iqt_ap3', 'is_active' => 1, '_profiles_id' => 4, '_entities_id' => 0, '_is_recursive' => 1]);
    $gA1 = (new Group())->add(['name' => 'IQT Onay 1', 'entities_id' => 0, 'is_recursive' => 1]);
    $gA2 = (new Group())->add(['name' => 'IQT Onay 2', 'entities_id' => 0, 'is_recursive' => 1]);
    foreach ([[$gA1, $ap1], [$gA1, $ap2], [$gA1, 2], [$gA2, $ap2]] as [$g, $u]) {
        (new Group_User())->add(['groups_id' => $g, 'users_id' => $u]);
    }
    EntityConfig::save($s['eA'], ['groups_id_quality' => $s['gDQ'], 'ticket_mode' => 'grouped', 'due_days' => 10]);

    // ----------------------------------------------------------------- DQ-07 güncellik
    $r7 = rule3('freshness', 'S3-07', ['field' => 'agent:last_contact', 'days' => 30]);
    $DB->insert('glpi_agents', ['deviceid' => 'iqt-43', 'name' => 'iqt-43', 'itemtype' => 'Computer', 'items_id' => $s['c43'], 'entities_id' => $s['eA'], 'agenttypes_id' => 1, 'last_contact' => Db::now()]);
    $DB->insert('glpi_agents', ['deviceid' => 'iqt-44', 'name' => 'iqt-44', 'itemtype' => 'Computer', 'items_id' => $s['c44'], 'entities_id' => $s['eA'], 'agenttypes_id' => 1, 'last_contact' => date('Y-m-d H:i:s', time() - 40 * 86400)]);
    scanAll();
    iq_ok((Evaluation::forItem('Computer', $s['c43'])[$r7]['result'] ?? '') === 'PASS' && (Evaluation::forItem('Computer', $s['c44'])[$r7]['result'] ?? '') === 'FAIL'
        && (Evaluation::forItem('Computer', $s['c42'])[$r7]['result'] ?? '') === 'FAIL', 'DQ-07: ajan bugün görüldü PASS, 40 gün önce FAIL, hiç ajan yok FAIL');
    $f7 = f3($s['c44'], $r7);
    iq_ok($f7 && CS::targetsFor($f7, Rule::version((int) Db::row(Rule::getTable(), ['id' => $r7])['ruleversions_id'])['def']) === [], 'DQ-07 için düzeltme önerilemez (tarih elle ileri alınmaz)');
    $r7b = rule3('freshness', 'S3-07B', ['field' => 'agent:last_contact', 'days' => 30, 'only_dynamic' => 1]);
    scanAll();
    iq_ok((Evaluation::forItem('Computer', $s['c42'])[$r7b]['result'] ?? '') === RuleEvaluator::NA, 'DQ-07 "yalnız otomatik envanter": elle girilmiş kayıt uygulanmaz');
    Rule::setActive($r7, false);
    Rule::setActive($r7b, false);

    // ----------------------------------------------------------------- DQ-08 iş sahibi teyidi
    $r8 = rule3('attestation', 'S3-08', ['attest_fields' => ['core:users_id', 'core:locations_id'], 'days' => 180]);
    scanAll();
    iq_ok(f3($s['c43'], $r8)['status'] === Finding::OPEN, 'DQ-08: teyit yok → uygunsuz');
    iq_su($s['tech'], 0);
    iq_ok(AttestationService::canAttest('Computer', $s['c43'], $s['tech']) && !AttestationService::canAttest('Computer', $s['c42'], $s['tech']),
        'teyit: varlığın kullanıcısı teyit verebilir, ilgisiz kullanıcı veremez (yetkisi yoksa)');
    AttestationService::attest('Computer', $s['c43'], ['core:users_id'], 'yalnız kullanıcı');
    iq_su(2);
    ScanRunner::recheckItem('Computer', $s['c43']);
    iq_ok(f3($s['c43'], $r8)['status'] === Finding::OPEN, 'DQ-08: alanların yalnız biri teyit edildi → hâlâ uygunsuz');
    iq_su($s['tech'], 0);
    AttestationService::attest('Computer', $s['c43'], ['core:users_id', 'core:locations_id'], 'kontrol ettim');
    iq_su(2);
    ScanRunner::recheckItem('Computer', $s['c43']);
    iq_ok(f3($s['c43'], $r8)['status'] === Finding::RESOLVED, 'DQ-08: iki alan teyit edildi → çözüldü');
    $att = AttestationService::recent('Computer', $s['c43'])[0];
    iq_ok((int) $att['users_id'] === $s['tech'] && in_array('core:locations_id', $att['fields_list'], true) && str_contains((string) $att['field_values'], 'IQT Depo'), 'teyit kaydı: kim, hangi alanlar, hangi değerler');
    $DB->update(AttestationService::TABLE, ['date' => date('Y-m-d H:i:s', time() - 200 * 86400)], ['items_id' => $s['c43']]);
    ScanRunner::recheckItem('Computer', $s['c43']);
    iq_ok(f3($s['c43'], $r8)['status'] === Finding::OPEN && (int) f3($s['c43'], $r8)['cycle'] === 2, 'DQ-08: teyit 200 gün önce → periyot aşıldı, yeni dönem');
    Rule::setActive($r8, false);

    // ----------------------------------------------------------------- T08 onaysız düzeltme (eklenti içinden)
    $r1 = rule3('required', 'S3-01', ['field' => 'core:locations_id']);
    scanAll();
    $f1 = f3($s['c44'], $r1);
    $auditBefore = countElementsInTable(AuditLog::TABLE, ['action' => 'correction_apply']);
    iq_ok(raises(fn() => CS::propose((int) $f1['id'], 'core:locations_id', $loc2, ''), 'gerekçe'), 'gerekçe zorunlu');
    iq_ok(raises(fn() => CS::propose((int) $f1['id'], 'core:locations_id', 999999, 'x yanlış'), 'bulunamadı'), 'olmayan değer önerilemez');
    iq_ok(raises(fn() => CS::propose((int) $f1['id'], 'core:serial', 'X', 'başka alan'), 'önerilemez'), 'kuralın dışındaki alana öneri yapılamaz');
    iq_su(2, Rights::VIEW);
    iq_ok(raises(fn() => CS::propose((int) $f1['id'], 'core:locations_id', $loc2, 'yetkisiz deneme'), 'yetkiniz yok'), 'öneri yetkisi olmayan kullanıcı öneremez');
    iq_su(2);
    $p = CS::propose((int) $f1['id'], 'core:locations_id', $loc2, 'Depo kaydı güncellendi');
    iq_ok($p['status'] === CS::APPLIED && (int) comp($s['c44'])['locations_id'] === $loc2, 'T08 onaysız politika + uygulama yetkisi → hemen uygulandı');
    iq_ok(countElementsInTable(AuditLog::TABLE, ['action' => 'correction_apply']) === $auditBefore + 1 && f3($s['c44'], $r1)['status'] === Finding::PENDING_VERIFICATION,
        'T08 tek denetim kaydı; bulgu "Doğrulama bekliyor" (henüz çözülmedi)');
    iq_ok(CorrectionProcessor::process($p['id']) === CS::APPLIED && countElementsInTable(AuditLog::TABLE, ['action' => 'correction_apply']) === $auditBefore + 1,
        'T08 aynı düzeltme yeniden işlenince ikinci kez uygulanmadı');
    queue();
    iq_ok(f3($s['c44'], $r1)['status'] === Finding::RESOLVED, 'T08 yeniden kontrol PASS → çözüldü');

    // Onaysız ama uygulama yetkisi yok → uygulanmayı bekler
    (new Computer())->update(['id' => $s['c44'], 'locations_id' => 0]);
    queue();
    iq_su($ap3, Rights::VIEW | Rights::PROPOSE);
    $p2 = CS::propose((int) f3($s['c44'], $r1)['id'], 'core:locations_id', $loc3, 'öneriyorum');
    iq_ok($p2['status'] === CS::APPROVED && (int) comp($s['c44'])['locations_id'] === 0, 'yalnız öneri yetkisi: onaysız politikada bile "Uygulanmayı bekliyor", envanter değişmedi');
    iq_su(2);
    iq_ok(CS::apply($p2['id']) === CS::APPLIED && (int) comp($s['c44'])['locations_id'] === $loc3, 'uygulama yetkilisi "Uygula" → uygulandı');
    queue();

    // ----------------------------------------------------------------- T06 onay gerekli + ret
    $r2 = rule3('responsible', 'S3-02', ['approval' => 'one', 'appr1_group' => $gA1, 'appr1_mode' => 'any']);
    scanAll();
    $f2 = f3($s['c42'], $r2);
    $c2 = CS::propose((int) $f2['id'], 'core:users_id_tech', $s['tech'], 'teknik sorumlu atanmalı');
    iq_ok($c2['status'] === CS::PENDING_APPROVAL && (int) comp($s['c42'])['users_id_tech'] === 0 && f3($s['c42'], $r2)['status'] === Finding::PENDING_APPROVAL,
        'T06 onay bekliyor: hedef alan değişmedi, bulgu "Onay bekliyor"');
    iq_ok(raises(fn() => CS::decide($c2['id'], true, 'kendim'), 'bekleyen bir onay'), 'kendi talebini onaylama kapalı (öneren grup üyesi olsa da)');
    iq_su($ap1);
    iq_ok(raises(fn() => CS::decide($c2['id'], false, ''), 'Ret gerekçesi'), 'ret gerekçesi zorunlu');
    iq_ok(CS::decide($c2['id'], false, 'kişi yanlış') === CS::REJECTED, 'T06 ret');
    iq_su(2);
    iq_ok((int) comp($s['c42'])['users_id_tech'] === 0 && f3($s['c42'], $r2)['status'] === Finding::OPEN, 'T06 ret: envanter değişmedi, kalite bulgusu açık');
    $c2b = CS::propose((int) $f2['id'], 'core:users_id_tech', $s['tech'], 'ikinci deneme');
    iq_su($ap2, Rights::VIEW);
    iq_ok(raises(fn() => CS::decide($c2b['id'], true, ''), 'bekleyen bir onay'), 'onay yetkisi olmayan grup üyesi onaylayamaz');
    iq_su($ap2);
    iq_ok(CS::decide($c2b['id'], true, 'uygun') === CS::APPLIED && (int) comp($s['c42'])['users_id_tech'] === $s['tech'], 'gruptan bir yetkili onayladı → uygulandı');
    iq_su(2);
    queue();
    iq_ok(f3($s['c42'], $r2)['status'] === Finding::RESOLVED, 'onaylı düzeltme sonrası yeniden kontrol PASS → çözüldü');

    // ----------------------------------------------------------------- iki sıralı adım, "belirlenmiş herkes"
    $r3 = rule3('required', 'S3-03', ['field' => 'core:serial', 'approval' => 'two', 'appr1_group' => $gA1, 'appr1_mode' => 'all', 'appr2_group' => $gA2, 'appr2_mode' => 'any']);
    scanAll();
    $c3 = CS::propose((int) f3($s['c42'], $r3)['id'], 'core:serial', 'SN-042', 'etiketten okundu');
    $rows = CS::approvals($c3['id']);
    $s1 = array_filter($rows, fn($r) => (int) $r['step'] === 1);
    $s2 = array_filter($rows, fn($r) => (int) $r['step'] === 2);
    iq_ok(count($s1) === 2 && !array_filter($s1, fn($r) => (int) $r['target_id'] === 2) && array_values($s2)[0]['status'] === 'pending',
        '"belirlenmiş herkes": 1. adımda öneren hariç 2 kişi; 2. adım kapalı (pending)');
    iq_su($ap1);
    iq_ok(CS::decide($c3['id'], true) === CS::PENDING_APPROVAL && countElementsInTable(CS::APPROVALS, ['corrections_id' => $c3['id'], 'step' => 2, 'status' => 'waiting']) === 0,
        'bir kişi onayladı: 1. adım bitmedi, 2. adım açılmadı');
    iq_su($ap2);
    iq_ok(CS::decide($c3['id'], true) === CS::PENDING_APPROVAL && countElementsInTable(CS::APPROVALS, ['corrections_id' => $c3['id'], 'step' => 2, 'status' => 'waiting']) === 1
        && comp($s['c42'])['serial'] === null, 'herkes onayladı: 2. adım şimdi açıldı; envanter hâlâ değişmedi');
    iq_ok(CS::decide($c3['id'], true, '2. adım') === CS::APPLIED && comp($s['c42'])['serial'] === 'SN-042', '2. adım onayı → uygulandı');
    iq_su(2);
    queue();

    // ----------------------------------------------------------------- T07 çakışma
    $r4 = rule3('required', 'S3-04', ['field' => 'core:contact', 'approval' => 'one', 'appr1_user' => $ap1]);
    scanAll();
    $c4 = CS::propose((int) f3($s['c43'], $r4)['id'], 'core:contact', 'Bilgi İşlem', 'kişi bilgisi');
    (new Computer())->update(['id' => $s['c43'], 'contact' => 'Başkası yazdı']);
    iq_su($ap1);
    iq_ok(CS::decide($c4['id'], true) === CS::CONFLICT && comp($s['c43'])['contact'] === 'Başkası yazdı', 'T07 onay sürecinde hedef alan değişti → CONFLICT, başka değişiklik ezilmedi');
    iq_su(2);
    iq_ok(str_contains((string) Db::row(CS::TABLE, ['id' => $c4['id']])['result_message'], 'değişti'), 'çakışma nedeni görünür');
    (new Computer())->update(['id' => $s['c43'], 'contact' => '']);
    queue();
    $c4b = CS::propose((int) f3($s['c43'], $r4)['id'], 'core:contact', 'Bilgi İşlem', 'yeni öneri');
    (new Computer())->update(['id' => $s['c43'], 'comment' => 'ilgisiz alan değişti']);
    iq_su($ap1);
    iq_ok(CS::decide($c4b['id'], true) === CS::APPLIED, 'ilgisiz alanın değişmesi çakışma üretmez');
    iq_su(2);
    queue();

    // ----------------------------------------------------------------- T09 yazma / denetim adımı hatası
    $r5 = rule3('required', 'S3-05', ['field' => 'core:otherserial']);
    scanAll();
    CorrectionProcessor::$failAfterAudit = true;
    $c5 = CS::propose((int) f3($s['c42'], $r5)['id'], 'core:otherserial', 'ENV-42', 'envanter etiketi');
    CorrectionProcessor::$failAfterAudit = false;
    iq_ok($c5['status'] === CS::FAILED && comp($s['c42'])['otherserial'] === null
        && countElementsInTable(AuditLog::TABLE, ['action' => 'correction_apply', 'items_id' => $c5['id']]) === 0, 'T09 hata → alan ve denetim kaydı birlikte geri alındı, "uygulandı" denmedi');
    iq_ok(countElementsInTable('glpi_logs', ['itemtype' => 'Computer', 'items_id' => $s['c42'], 'new_value' => ['LIKE', '%ENV-42%']]) === 0, 'T09 GLPI tarihçesine de yazılmadı (aynı işlem)');
    iq_ok(CS::apply($c5['id']) === CS::APPLIED && comp($s['c42'])['otherserial'] === 'ENV-42', '"Yeniden dene" → bir kez uygulandı');
    queue();

    // ----------------------------------------------------------------- onaylayan pasifleşti → İnceleme gerekli; yeniden atama
    $r6 = rule3('required', 'S3-06', ['field' => 'core:contact_num', 'approval' => 'one', 'appr1_user' => $ap3]);
    scanAll();
    $c6 = CS::propose((int) f3($s['c42'], $r6)['id'], 'core:contact_num', '1234', 'dahili numara');
    (new User())->update(['id' => $ap3, 'is_active' => 0]);
    iq_ok(CS::checkApprovers() === 1 && Db::row(CS::TABLE, ['id' => $c6['id']])['status'] === CS::REVIEW && f3($s['c42'], $r6)['status'] === Finding::REVIEW,
        'onaylayan pasifleşti → düzeltme ve bulgu "İnceleme gerekli" (onay otomatik verilmedi)');
    CS::reassign($c6['id'], 1, $gA2, 0, 'any');
    iq_ok(Db::row(CS::TABLE, ['id' => $c6['id']])['status'] === CS::PENDING_APPROVAL && countElementsInTable(AuditLog::TABLE, ['action' => 'approval_reassign', 'items_id' => $c6['id']]) === 1,
        'yetkili yeniden atama → tekrar onay bekliyor; geçmişe yazıldı');
    iq_su($ap2);
    iq_ok(CS::decide($c6['id'], true) === CS::APPLIED, 'yeni onaylayan onayladı → uygulandı');
    iq_su(2);
    queue();
    $r6c = rule3('required', 'S3-06C', ['field' => 'core:name', 'approval' => 'one', 'appr1_user' => 2]);
    (new Computer())->update(['id' => $s['c44'], 'name' => '']);
    queue();
    $c6c = CS::propose((int) f3($s['c44'], $r6c)['id'], 'core:name', 'IQT LT-044', 'ad girildi');
    iq_ok($c6c['status'] === CS::REVIEW, 'onaylayan yalnız öneren kişiyse (kendi onayı kapalı) → "İnceleme gerekli"');
    CS::cancel($c6c['id']);

    // ----------------------------------------------------------------- bekleyen öneri + başka yoldan düzeltme
    $c7 = CS::propose((int) f3($s['c44'], $r6c)['id'], 'core:name', 'IQT LT-044', 'ad yeniden');
    (new Computer())->update(['id' => $s['c44'], 'name' => 'IQT LT-044']);
    queue();
    iq_ok(f3($s['c44'], $r6c)['status'] === Finding::RESOLVED && Db::row(CS::TABLE, ['id' => $c7['id']])['status'] === CS::CANCELLED, 'veri GLPI ekranından düzeltildi → bulgu çözüldü, bekleyen öneri iptal');

    // ----------------------------------------------------------------- T11 istisna
    $fx = f3($s['cB'], $r1);
    iq_ok(raises(fn() => ExceptionService::grant((int) $fx['id'], '', date('Y-m-d', time() + 86400))), 'istisna gerekçesi zorunlu');
    iq_ok(raises(fn() => ExceptionService::grant((int) $fx['id'], 'uzun süre', date('Y-m-d', time() + 400 * 86400))), 'istisna en fazla 365 gün');
    iq_su(2, Rights::VIEW);
    iq_ok(raises(fn() => ExceptionService::grant((int) $fx['id'], 'yetkisiz istisna', date('Y-m-d', time() + 86400)), 'yetkiniz yok'), 'istisna yetkisi gerekir');
    iq_su(2);
    $scoreBefore = \GlpiPlugin\Inventoryquality\QualityCalculator::compute();
    $ex = ExceptionService::grant((int) $fx['id'], 'Cihaz depoya taşınıyor', date('Y-m-d', time() + 7 * 86400), 'Haftalık sayım');
    queue();
    $scoreAfter = \GlpiPlugin\Inventoryquality\QualityCalculator::compute();
    iq_ok(f3($s['cB'], $r1)['status'] === Finding::EXCEPTION && $scoreAfter['fail'] === $scoreBefore['fail'], 'istisna: bulgu "İstisna", FAIL PASS sayılmadı (puan değişmedi)');
    scanAll();
    iq_ok(f3($s['cB'], $r1)['status'] === Finding::EXCEPTION, 'istisna altında tarama FAIL → istisna korunur');
    $DB->update(ExceptionService::TABLE, ['end_date' => date('Y-m-d H:i:s', time() - 60)], ['id' => $ex]);
    iq_ok(AuditLog::as('cron:iqexpire', fn() => ExceptionService::expireDue()) === 1 && f3($s['cB'], $r1)['status'] === Finding::OPEN
        && Db::row(ExceptionService::TABLE, ['id' => $ex])['status'] === 'expired', 'T11 süre doldu + hâlâ FAIL → Açık');
    $ex2 = ExceptionService::grant((int) $fx['id'], 'ikinci istisna', date('Y-m-d', time() + 3 * 86400));
    (new Computer())->update(['id' => $s['cB'], 'locations_id' => $loc2]);
    queue();
    iq_ok(f3($s['cB'], $r1)['status'] === Finding::RESOLVED && Db::row(ExceptionService::TABLE, ['id' => $ex2])['status'] === 'ended', 'istisna sürerken veri düzeldi → PASS ile çözüldü, istisna sona erdi');
    (new Computer())->update(['id' => $s['cB'], 'locations_id' => 0]);
    queue();
    $ex3 = ExceptionService::grant((int) f3($s['cB'], $r1)['id'], 'üçüncü', date('Y-m-d', time() + 3 * 86400));
    $v1 = Rule::version((int) Db::row(Rule::getTable(), ['id' => $r1])['ruleversions_id']);
    $broken = $v1['def'];
    $broken['assert']['field'] = 'core:yok_alan';
    $DB->update(Rule::VERSIONS, ['definition' => json_encode($broken)], ['id' => $v1['id']]);
    $DB->update(ExceptionService::TABLE, ['end_date' => date('Y-m-d H:i:s', time() - 60)], ['id' => $ex3]);
    ExceptionService::expireDue();
    iq_ok(f3($s['cB'], $r1)['status'] === Finding::REVIEW, 'T11 süre doldu + veri okunamadı (UNKNOWN) → İnceleme gerekli');
    $DB->update(Rule::VERSIONS, ['definition' => json_encode($v1['def'])], ['id' => $v1['id']]);
    $f9 = f3($s['cB'], $r1);
    ScanRunner::recheckItem('Computer', $s['cB']);
    $ex4 = ExceptionService::grant((int) f3($s['cB'], $r1)['id'], 'geri alınacak', date('Y-m-d', time() + 3 * 86400));
    ExceptionService::revoke($ex4, 'vazgeçildi');
    iq_ok(Db::row(ExceptionService::TABLE, ['id' => $ex4])['status'] === 'revoked' && f3($s['cB'], $r1)['status'] === Finding::OPEN, 'istisna geri alındı → yeniden kontrol, Açık');

    // ----------------------------------------------------------------- güvenli CSV
    (new Computer())->update(['id' => $s['c42'], 'name' => '=HYPERLINK("http://x","tıkla")']);
    queue();
    $csv = Export::findingsCsv();
    iq_ok(str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, "\"'=HYPERLINK(\"\"http://x\"\",\"\"tıkla\"\")\"") && !preg_match('/;"=/', $csv),
        'CSV: formül olarak yorumlanabilecek hücre başına tek tırnak eklendi; UTF-8 BOM');
    (new Computer())->update(['id' => $s['c42'], 'name' => 'IQT LT-042']);
    iq_ok(Export::safeCell('+1') === "'+1" && Export::safeCell('-2') === "'-2" && Export::safeCell('@SUM') === "'@SUM" && Export::safeCell('normal') === 'normal', 'CSV hücre güvenliği (=, +, -, @)');
    $act = $_SESSION['glpiactiveentities'];
    $show = $_SESSION['glpishowallentities'] ?? 0;
    $_SESSION['glpiactiveentities'] = [$s['eB']];
    $_SESSION['glpiactiveentities_string'] = "'" . $s['eB'] . "'";
    $_SESSION['glpishowallentities'] = 0;
    $csvB = Export::findingsCsv();
    $_SESSION['glpiactiveentities'] = $act;
    $_SESSION['glpiactiveentities_string'] = "'" . implode("','", $act) . "'";
    $_SESSION['glpishowallentities'] = $show;
    iq_ok(!str_contains($csvB, 'IQT LT-043') && str_contains($csvB, 'IQT LT-B01'), 'CSV birim kısıtlı (yalnız erişilen birim)');

    // ----------------------------------------------------------------- kanıt eki
    $tmp = tempnam('/tmp', 'iqe');
    file_put_contents($tmp, "Sayım tutanağı: LT-042 Oda 2'de.\n");
    $ev = Evidence::store(['name' => 'tutanak.txt', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK], true);
    iq_ok($ev && $ev['mime'] === 'text/plain' && Evidence::path($ev['file']) !== null, 'kanıt: düz metin kabul edildi, rastgele adla saklandı');
    file_put_contents($tmp, "<?php echo 'x'; ?>");
    iq_ok(raises(fn() => Evidence::store(['name' => 'resim.png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK], true), 'PDF'), 'kanıt: uzantısı png olan PHP içeriği reddedildi (tür içerikten)');
    $big = tempnam('/tmp', 'iqb');
    file_put_contents($big, str_repeat('a', Evidence::MAX_BYTES + 1));
    iq_ok(raises(fn() => Evidence::store(['name' => 'b.txt', 'tmp_name' => $big, 'error' => UPLOAD_ERR_OK], true), 'MB'), 'kanıt: boyut sınırı');
    iq_ok(Evidence::path('../../config/config_db.php') === null && Evidence::path('abc.php') === null, 'kanıt: yol / ad doğrulaması (dizin dışına çıkılamaz)');
    @unlink($tmp);
    @unlink($big);
    @unlink(Evidence::dir() . '/' . $ev['file']);

    // ----------------------------------------------------------------- destek kaydında düzeltme notları
    $notes = 0;
    foreach ($DB->request(['FROM' => 'glpi_itilfollowups', 'WHERE' => ['itemtype' => 'Ticket', 'content' => ['LIKE', '%Düzeltme%']]]) as $n) {
        $notes++;
    }
    iq_ok($notes > 0, "destek kaydında düzeltme olayları takip notu olarak görünüyor ($notes)");
    iq_ok(countElementsInTable('glpi_crontasks', ['itemtype' => 'GlpiPlugin\\Inventoryquality\\Cron']) === 3, 'üç otomatik görev (iqqueue, iqscan, iqexpire)');
} catch (Throwable $e) {
    iq_ok(false, 'İSTİSNA: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    echo $e->getTraceAsString() . "\n";
} finally {
    CorrectionProcessor::$failAfterAudit = false;
    iq_su(2);
    iq_wipe();
    $DB->delete('glpi_agents', ['deviceid' => ['LIKE', 'iqt-%']]);
    iq_cleanup_glpi();
}
iq_done();
