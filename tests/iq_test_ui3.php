<?php
// inventoryquality 0.2.0 — 3. aşama ekran testleri (gerçek sayfa dosyaları, ayrı süreç, farklı kullanıcılar).
require __DIR__ . '/iq_boot.php';
require __DIR__ . '/iq_seed.php';

use GlpiPlugin\Inventoryquality\AssetTab;
use GlpiPlugin\Inventoryquality\AttestationService;
use GlpiPlugin\Inventoryquality\CorrectionService as CS;
use GlpiPlugin\Inventoryquality\Db;
use GlpiPlugin\Inventoryquality\EntityConfig;
use GlpiPlugin\Inventoryquality\ExceptionService;
use GlpiPlugin\Inventoryquality\Finding;
use GlpiPlugin\Inventoryquality\Rule;
use GlpiPlugin\Inventoryquality\ScanRunner;

global $DB;
iq_login(2);
$snap = ($argv[1] ?? '') === 'snap';
$snapDir = IQ_ROOT . '/public/_snap';
if ($snap) {
    @mkdir($snapDir, 0755, true);
}
$save = static function (string $name, array $p) use ($snap, $snapDir): void {
    if ($snap) {
        file_put_contents("$snapDir/$name.html", preg_replace('/\n@@[A-Z]+=.*?@@/s', '', $p['body']));
    }
};
$ok200 = static fn(array $p): bool => $p['code'] === 200 && $p['error'] === '' && !preg_match('/(Fatal error|Warning:|Notice:|Deprecated:|Twig\\\\Error)/', $p['body']);
$scan = static function (): void {
    queue();
    ScanRunner::start('full', null, 'test');
    while (ScanRunner::tick(30)['slices'] > 0) {
    }
    queue();
};
$fnd = static function (int $cid, int $rid): ?array {
    global $DB;
    return $DB->request(['FROM' => Finding::getTable(), 'WHERE' => ['itemtype' => 'Computer', 'items_id' => $cid, 'rules_id' => $rid]])->current() ?: null;
};
$ruleId = static function (string $code): int {
    global $DB;
    return (int) ($DB->request(['SELECT' => ['id'], 'FROM' => Rule::getTable(), 'WHERE' => ['code' => $code]])->current()['id'] ?? 0);
};

iq_cleanup_glpi();
iq_wipe();
try {
    $s = iq_seed();
    $ap1 = (new User())->add(['name' => 'iqt_ap1', 'is_active' => 1, '_profiles_id' => 4, '_entities_id' => 0, '_is_recursive' => 1]);
    $gA1 = (new Group())->add(['name' => 'IQT Onay 1', 'entities_id' => 0, 'is_recursive' => 1]);
    (new Group_User())->add(['groups_id' => $gA1, 'users_id' => $ap1]);
    (new Group_User())->add(['groups_id' => $gA1, 'users_id' => 2]);
    EntityConfig::save($s['eA'], ['groups_id_quality' => $s['gDQ'], 'ticket_mode' => 'grouped', 'due_days' => 10]);

    // ---------------------------------------------------- onaylı kural (ekrandan)
    page('front/rule.php', 'POST', [], ['create' => 1, 'template' => 'required', 'code' => 'U3-01', 'name' => 'Konum zorunlu (onaylı)', 'itemtype' => 'Computer', 'entities_id' => 0, 'is_recursive' => 1]);
    $r1 = $ruleId('U3-01');
    $p = page('front/rule.form.php', 'GET', ['id' => $r1]);
    iq_ok($ok200($p) && str_contains($p['body'], 'Düzeltme onay politikası') && str_contains($p['body'], 'name="appr1_mode"'), 'kural formu: onay politikası alanları ' . $p['error']);
    $p = page('front/rule.form.php', 'POST', [], ['id' => $r1, 'field' => 'core:locations_id', 'severity' => 2, 'weight' => 2, 'approval' => 'one', 'appr1_group' => $gA1, 'appr1_mode' => 'any', 'publish' => 1]);
    page('front/rule.form.php', 'POST', [], ['id' => $r1, 'activate' => 1]);
    $v = Rule::version((int) Db::row(Rule::getTable(), ['id' => $r1])['ruleversions_id']);
    iq_ok(($v['def']['policy']['approval']['level'] ?? '') === 'one' && (int) $v['def']['policy']['approval']['steps'][1]['groups_id'] === $gA1, 'onay politikası (1 adım, grup) yayınlandı ' . msgs($p));
    $p = page('front/rule.form.php', 'POST', [], ['id' => $r1, 'field' => 'core:locations_id', 'approval' => 'two', 'appr1_group' => $gA1, 'publish' => 1]);
    iq_ok(str_contains(msgs($p), '2. onay adımı'), '2 adımlı politikada 2. adım grubu zorunlu: ' . msgs($p));
    $scan();

    // ---------------------------------------------------- düzeltme öner → onay → uygula
    $f = $fnd($s['c42'], $r1);
    $p = page('front/finding.form.php', 'GET', ['id' => $f['id']]);
    $save('11_bulgu_duzeltme_oner', $p);
    iq_ok($ok200($p) && str_contains($p['body'], 'Düzeltme öner') && str_contains($p['body'], 'Öneriyi gönder') && str_contains($p['body'], 'enctype="multipart/form-data"') && str_contains($p['body'], '1 onay adımı'),
        'bulgu detayı: düzeltme önerisi formu (dosya eki, politika) ' . $p['error']);
    $p = page('front/finding.form.php', 'POST', [], ['id' => $f['id'], 'propose' => 1, 'field' => 'core:locations_id', 'new_value' => $s['loc'], 'reason' => 'Sayımda Depo\'da görüldü']);
    $cid = (int) ($DB->request(['SELECT' => ['MAX' => 'id AS m'], 'FROM' => CS::TABLE])->current()['m'] ?? 0);
    iq_ok($p['code'] === 302 && str_contains($p['redirect'], 'correction.form.php?id=' . $cid) && Db::row(CS::TABLE, ['id' => $cid])['status'] === CS::PENDING_APPROVAL, 'öneri gönderildi → düzeltme sayfası, onay bekliyor: ' . msgs($p));
    $p = page('front/correction.php', 'GET', ['view' => 'mine'], [], 2);
    iq_ok($ok200($p) && str_contains($p['body'], 'Onayınızı bekleyen düzeltme yok'), 'öneren kendi önerisini "onayımı bekleyenler"de görmez');
    $p = page('front/correction.php', 'GET', ['view' => 'mine'], [], $ap1);
    $save('12_duzeltmeler', $p);
    iq_ok($ok200($p) && str_contains($p['body'], 'correction.form.php?id=' . $cid), 'onaylayan "onayımı bekleyenler"de görür ' . $p['error']);
    $p = page('front/correction.form.php', 'GET', ['id' => $cid], [], $ap1);
    $save('13_duzeltme_onay', $p);
    iq_ok($ok200($p) && str_contains($p['body'], 'name="approve"') && str_contains($p['body'], 'IQT Depo') && str_contains($p['body'], 'Onay adımları'), 'düzeltme sayfası (onaylayan): önceki → yeni değer, onay düğmeleri ' . $p['error']);
    $p = page('front/correction.form.php', 'GET', ['id' => $cid], [], 2);
    iq_ok($ok200($p) && !str_contains($p['body'], 'name="approve"') && str_contains($p['body'], 'name="cancel"'), 'öneren onaylayamaz, iptal edebilir');
    $p = page('front/correction.form.php', 'POST', [], ['id' => $cid, 'reject' => 1, 'comment' => ''], $ap1);
    iq_ok(str_contains(msgs($p), 'Ret gerekçesi'), 'ret gerekçesiz reddedilemez: ' . msgs($p));
    $p = page('front/correction.form.php', 'POST', [], ['id' => $cid, 'approve' => 1, 'comment' => 'uygun'], $ap1);
    $c = $DB->request(['FROM' => 'glpi_computers', 'WHERE' => ['id' => $s['c42']]])->current();
    iq_ok($p['code'] === 302 && Db::row(CS::TABLE, ['id' => $cid])['status'] === CS::APPLIED && (int) $c['locations_id'] === $s['loc'] && $fnd($s['c42'], $r1)['status'] === Finding::RESOLVED,
        'onay → uygulandı → yeniden kontrol PASS → bulgu çözüldü: ' . msgs($p));

    // ---------------------------------------------------- istisna (ekrandan)
    $fb = $fnd($s['cB'], $r1);
    $p = page('front/finding.form.php', 'GET', ['id' => $fb['id']]);
    iq_ok($ok200($p) && str_contains($p['body'], 'name="grant_exception"'), 'bulgu detayı: istisna formu');
    $p = page('front/finding.form.php', 'POST', [], ['id' => $fb['id'], 'grant_exception' => 1, 'ex_reason' => 'Cihaz hurdaya ayrılacak', 'ex_end' => date('Y-m-d', time() + 5 * 86400), 'ex_comp' => 'Aylık kontrol']);
    iq_ok($p['code'] === 302 && $fnd($s['cB'], $r1)['status'] === Finding::EXCEPTION, 'istisna verildi: ' . msgs($p));
    $p = page('front/exception.php');
    $save('14_istisnalar', $p);
    $ex = ExceptionService::activeFor((int) $fb['id']);
    iq_ok($ok200($p) && str_contains($p['body'], 'Cihaz hurdaya ayrılacak') && str_contains($p['body'], 'name="revoke"'), 'İstisnalar sayfası ' . $p['error']);
    $p = page('front/exception.php', 'POST', [], ['revoke' => $ex['id'], 'reason' => 'vazgeçildi']);
    iq_ok($p['code'] === 302 && Db::row(ExceptionService::TABLE, ['id' => $ex['id']])['status'] === 'revoked' && $fnd($s['cB'], $r1)['status'] === Finding::OPEN, 'istisna ekrandan geri alındı: ' . msgs($p));

    // ---------------------------------------------------- iş sahibi teyidi (varlık sekmesi)
    page('front/rule.php', 'POST', [], ['create' => 1, 'template' => 'attestation', 'code' => 'U3-08', 'itemtype' => 'Computer', 'entities_id' => 0, 'is_recursive' => 1]);
    $r8 = $ruleId('U3-08');
    $p = page('front/rule.form.php', 'GET', ['id' => $r8]);
    iq_ok($ok200($p) && str_contains($p['body'], 'name="attest_fields[]"') && !str_contains($p['body'], 'Düzeltme onay politikası'), 'teyit kuralı formu: alan seçimi, onay politikası yok ' . $p['error']);
    page('front/rule.form.php', 'POST', [], ['id' => $r8, 'attest_fields' => ['core:users_id', 'core:locations_id'], 'days' => 90, 'publish' => 1]);
    page('front/rule.form.php', 'POST', [], ['id' => $r8, 'activate' => 1]);
    $scan();
    $comp = new Computer();
    $comp->getFromDB($s['c43']);
    ob_start();
    AssetTab::displayTabContentForItem($comp);
    $tab = ob_get_clean();
    if ($snap) {
        file_put_contents("$snapDir/15_varlik_teyit.html", '<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="/lib/base.min.css"><link rel="stylesheet" href="/css_compiled/css_glpi.min.css"><body class="p-3">' . $tab);
    }
    iq_ok(str_contains($tab, 'İş sahibi teyidi') && str_contains($tab, 'attest.php') && str_contains($tab, 'U3-08'), 'varlık sekmesi: teyit kuralı ve teyit formu');
    $p = page('front/attest.php', 'POST', [], ['itemtype' => 'Computer', 'items_id' => $s['c43'], 'attest_fields' => ['core:users_id', 'core:locations_id'], 'attest_comment' => 'kontrol edildi'], $s['tech']);
    iq_ok($p['code'] === 302 && str_contains($p['redirect'], 'computer.form.php') && countElementsInTable(AttestationService::TABLE, ['items_id' => $s['c43'], 'users_id' => $s['tech']]) === 1
        && $fnd($s['c43'], $r8)['status'] === Finding::RESOLVED, 'varlığın kullanıcısı teyit etti → kayıt yeniden kontrol edildi, bulgu çözüldü: ' . msgs($p));
    $p = page('front/attest.php', 'POST', [], ['itemtype' => 'Computer', 'items_id' => $s['c44'], 'attest_fields' => ['core:users_id']], $s['tech']);
    iq_ok(str_contains(msgs($p), 'teyit yetkiniz yok'), 'ilgisiz kullanıcı teyit veremez: ' . msgs($p));

    // ---------------------------------------------------- CSV, kanıt, genel bakış, güncellik formu
    $p = page('front/export.php', 'GET', ['scope' => 'all']);
    iq_ok($p['code'] === 200 && str_contains($p['body'], "\xEF\xBB\xBF") && str_contains($p['body'], '"Bulgu";"Kurum birimi"') && str_contains($p['body'], 'U3-01'), 'CSV dışa aktarma (BOM, başlık, satırlar)');
    $p = page('front/export.php', 'GET', ['scope' => 'all'], [], $s['tech']);
    iq_ok($p['code'] === 403, 'dışa aktarma yetkisi olmayan → 403 (kod ' . $p['code'] . ')');
    $p = page('front/evidence.php', 'GET', ['id' => $cid]);
    iq_ok($p['code'] === 404, 'eki olmayan düzeltmede kanıt indirme → 404');
    $p = page('front/evidence.php', 'GET', ['id' => $cid], [], $s['ub']);
    iq_ok($p['code'] === 404, 'başka birimin düzeltmesinin kanıtı → 404');
    $p = page('front/index.php');
    $save('16_genel_bakis_3', $p);
    iq_ok($ok200($p) && str_contains($p['body'], 'Düzeltmeler ve istisnalar') && str_contains($p['body'], 'export.php?scope=active'), 'Genel Bakış: düzeltme / istisna göstergeleri, CSV bağlantısı ' . $p['error']);
    page('front/rule.php', 'POST', [], ['create' => 1, 'template' => 'freshness', 'code' => 'U3-07', 'itemtype' => 'Computer', 'entities_id' => 0, 'is_recursive' => 1]);
    $p = page('front/rule.form.php', 'GET', ['id' => $ruleId('U3-07')]);
    iq_ok($ok200($p) && str_contains($p['body'], 'name="days"') && str_contains($p['body'], 'name="only_dynamic"') && str_contains($p['body'], 'agent:last_contact'), 'güncellik kuralı formu: gün, yalnız otomatik envanter, veri kaynağı ' . $p['error']);
    $p = page('front/finding.form.php', 'GET', ['id' => $fb['id']]);
    iq_ok($ok200($p) && str_contains($p['body'], 'Düzeltme önerileri'), 'bulgu detayı: düzeltme önerileri tablosu');
} catch (Throwable $e) {
    iq_ok(false, 'İSTİSNA: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    iq_wipe();
    iq_cleanup_glpi();
}
iq_done();
