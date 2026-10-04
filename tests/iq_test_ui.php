<?php
// inventoryquality 0.1.0 — ekran ve işlem testleri (gerçek sayfa dosyaları ayrı süreçte, oturum açık kullanıcıyla).
// Kullanım: php iq_test_ui.php [snap]   snap verilirse sayfa HTML'leri ~/iq/glpi/public/_snap altına yazılır.
require __DIR__ . '/iq_boot.php';
require __DIR__ . '/iq_seed.php';

use GlpiPlugin\Inventoryquality\AssetTab;
use GlpiPlugin\Inventoryquality\Db;
use GlpiPlugin\Inventoryquality\EntityConfig;
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

iq_cleanup_glpi();
iq_wipe();
try {
    $s = iq_seed();

    $p = page('front/index.php');
    $save('01_genel_bakis_bos', $p);
    iq_ok($ok200($p) && str_contains($p['body'], 'Kalite puanı') && str_contains($p['body'], 'Hesaplanamadı'), 'Genel Bakış (boş): 200, "Hesaplanamadı" ' . $p['error']);

    $p = page('front/rule.php', 'GET', ['new' => 1]);
    $save('02_kurallar_yeni', $p);
    iq_ok($ok200($p) && str_contains($p['body'], 'Şablondan yeni kural') && str_contains($p['body'], 'DQ-05'), 'Kurallar: şablon kartı (DQ-01…06) ' . $p['error']);

    $p = page('front/rule.php', 'POST', [], ['create' => 1, 'template' => 'required', 'code' => 'ui-01', 'name' => 'Konum zorunlu', 'itemtype' => 'Computer', 'entities_id' => 0, 'is_recursive' => 1]);
    $rid = (int) ($DB->request(['SELECT' => ['id'], 'FROM' => Rule::getTable(), 'WHERE' => ['code' => 'UI-01']])->current()['id'] ?? 0);
    iq_ok($p['code'] === 302 && str_contains($p['redirect'], 'rule.form.php?id=' . $rid) && $rid > 0, 'kural oluşturma → kural formuna yönlendirme (kod büyük harfe çevrildi) ' . msgs($p));

    $p = page('front/rule.php', 'POST', [], ['create' => 1, 'template' => 'required', 'code' => 'UI-01', 'itemtype' => 'Computer', 'entities_id' => 0]);
    iq_ok($p['code'] === 302 && str_contains(msgs($p), 'kullanılıyor'), 'aynı kodla ikinci kural reddedildi: ' . msgs($p));

    $p = page('front/rule.form.php', 'GET', ['id' => $rid]);
    $save('03_kural_formu', $p);
    iq_ok($ok200($p) && str_contains($p['body'], 'Yayınlanmadı') && str_contains($p['body'], 'core:locations_id'), 'kural formu: yayınlanmamış, hedef alan seçenekleri ' . $p['error']);

    $before = countElementsInTable(Finding::getTable(), []);
    $p = page('front/rule.form.php', 'POST', [], ['id' => $rid, 'field' => 'core:locations_id', 'severity' => 3, 'weight' => 2, 'due_days' => 0, 'ticket' => 'inherit', 'preview' => 1]);
    $save('04_kural_onizleme', $p);
    iq_ok($ok200($p) && str_contains($p['body'], 'Önizleme (kayıt yazılmadı)') && preg_match('/Uygunsuz\s*3/u', strip_tags($p['body'])) && countElementsInTable(Finding::getTable(), []) === $before,
        'önizleme: 3 uygunsuz gösterildi, bulgu yazılmadı ' . $p['error']);

    $p = page('front/rule.form.php', 'POST', [], ['id' => $rid, 'field' => 'core:locations_id', 'severity' => 3, 'weight' => 2, 'due_days' => 5, 'ticket' => 'inherit', 'comment' => 'ilk sürüm', 'publish' => 1]);
    $r = Db::row(Rule::getTable(), ['id' => $rid]);
    iq_ok($p['code'] === 302 && (int) $r['ruleversions_id'] > 0 && (int) Rule::version((int) $r['ruleversions_id'])['severity'] === 3, 'yayınla → v1 (önem Yüksek) ' . msgs($p));

    $p = page('front/rule.form.php', 'POST', [], ['id' => $rid, 'activate' => 1]);
    iq_ok($p['code'] === 302 && (int) Db::row(Rule::getTable(), ['id' => $rid])['is_active'] === 1, 'etkinleştir ' . msgs($p));

    // Değer kümesi: çoklu açılır liste adı (values[]) doğru çözülüyor mu?
    page('front/rule.php', 'POST', [], ['create' => 1, 'template' => 'valueset', 'code' => 'UI-04', 'itemtype' => 'Computer', 'entities_id' => 0, 'is_recursive' => 1]);
    $rid4 = (int) ($DB->request(['SELECT' => ['id'], 'FROM' => Rule::getTable(), 'WHERE' => ['code' => 'UI-04']])->current()['id'] ?? 0);
    $p = page('front/rule.form.php', 'POST', [], ['id' => $rid4, 'field' => 'core:states_id', 'refresh' => 1]);
    preg_match('/<select[^>]*name=["\']([^"\']*values[^"\']*)["\']/', $p['body'], $mm);
    iq_ok($ok200($p) && ($mm[1] ?? '') === 'values[]' && str_contains($p['body'], 'name="scope_states[]"'), 'çoklu seçim alan adları "[]" ile (tarayıcı tüm değerleri gönderir): ' . ($mm[1] ?? '(bulunamadı)'));
    $valName = preg_replace('/\[\]$/', '', $mm[1] ?? 'values');
    $p = page('front/rule.form.php', 'POST', [], ['id' => $rid4, 'field' => 'core:states_id', $valName => [$s['stUse'], $s['stStore']], 'severity' => 2, 'weight' => 1, 'ticket' => 'off', 'publish' => 1]);
    $v4 = Rule::version((int) Db::row(Rule::getTable(), ['id' => $rid4])['ruleversions_id']);
    iq_ok($p['code'] === 302 && $v4 && $v4['def']['assert']['values'] == [$s['stUse'], $s['stStore']], 'değer kümesi yayınlandı (gerçek kimlikler) ' . msgs($p));

    // Koşullu: önkoşul alanını uygula → değer alanı gelir → yayınla.
    page('front/rule.php', 'POST', [], ['create' => 1, 'template' => 'conditional', 'code' => 'UI-05', 'itemtype' => 'Computer', 'entities_id' => 0, 'is_recursive' => 1]);
    $rid5 = (int) ($DB->request(['SELECT' => ['id'], 'FROM' => Rule::getTable(), 'WHERE' => ['code' => 'UI-05']])->current()['id'] ?? 0);
    $p = page('front/rule.form.php', 'POST', [], ['id' => $rid5, 'field' => 'core:locations_id', 'pre_field' => 'core:states_id', 'pre_op' => 'in', 'refresh' => 1]);
    iq_ok($ok200($p) && preg_match('/name=["\']pre_values/', $p['body']), '"Alanı uygula" önkoşul değer alanını getirdi ' . $p['error']);
    $p = page('front/rule.form.php', 'POST', [], ['id' => $rid5, 'field' => 'core:locations_id', 'pre_field' => 'core:states_id', 'pre_op' => 'in', 'pre_values' => [$s['stUse']], 'severity' => 2, 'weight' => 2, 'publish' => 1]);
    page('front/rule.form.php', 'POST', [], ['id' => $rid5, 'activate' => 1]);
    $v5 = Rule::version((int) Db::row(Rule::getTable(), ['id' => $rid5])['ruleversions_id']);
    iq_ok($v5 && $v5['def']['precondition']['values'] === [$s['stUse']] && str_contains($p['redirect'], 'rule.form.php'), 'koşullu kural yayınlandı ' . msgs($p));
    $p = page('front/rule.form.php', 'POST', [], ['id' => $rid5, 'field' => 'core:locations_id', 'pre_field' => 'core:states_id', 'pre_op' => 'in', 'publish' => 1]);
    iq_ok($p['code'] === 302 && str_contains(msgs($p), 'değer'), 'eksik önkoşul değeri anlaşılır hatayla reddedildi: ' . msgs($p));

    // Birim ayarı ekrandan; sonra tarama.
    $p = page('front/config.php', 'POST', [], ['entities_id' => $s['eA'], 'groups_id_quality' => $s['gDQ'], 'ticket_mode' => 'grouped', 'due_days' => 7, 'save_entity' => 1]);
    EntityConfig::reset();
    iq_ok($p['code'] === 302 && EntityConfig::effective($s['eA'])['ticket_mode'] === 'grouped' && EntityConfig::effective($s['eA'])['groups_id_quality'] === $s['gDQ'], 'Ayarlar: birim ayarı kaydedildi ' . msgs($p));
    $p = page('front/jobs.php', 'POST', [], ['start_full' => 1]);
    iq_ok($p['code'] === 302 && str_contains(msgs($p), 'Tam tarama'), 'İşler: tam tarama başlatıldı: ' . msgs($p));
    page('front/jobs.php', 'POST', [], ['process' => 1]);
    queue();
    while (ScanRunner::tick(30)['slices'] > 0) {
    }
    queue();

    $p = page('front/index.php');
    $save('05_genel_bakis', $p);
    iq_ok($ok200($p) && str_contains($p['body'], 'UI-01') && preg_match('/%\d/', strip_tags($p['body'])), 'Genel Bakış: kural satırı ve yüzde puan ' . $p['error']);

    $p = page('front/finding.php');
    $save('06_bulgular', $p);
    iq_ok($ok200($p) && str_contains($p['body'], 'ajax/search.php'), 'Bulgular sayfası: GLPI arama kabı (sonuçlar AJAX ile yüklenir) ' . $p['error']);
    // Liste satırları: GLPI arama verisi (AJAX'ın döndürdüğü ile aynı motor) — görünen değerler.
    $sd = Search::getDatas(Finding::class, ['criteria' => [], 'reset' => 'reset', 'forcetoview' => [1, 2, 4, 6, 7, 9, 11]]);
    $cells = '';
    foreach ($sd['data']['rows'] ?? [] as $row) {
        foreach ($row as $col) {
            if (is_array($col) && isset($col['displayname'])) {
                $cells .= ' ' . $col['displayname'];
            }
        }
    }
    iq_ok(str_contains($cells, 'IQT LT-042') && str_contains($cells, 'UI-01') && str_contains($cells, 'Açık') && str_contains($cells, 'IQT Veri Kalitesi'),
        'Bulgular listesi verisi: varlık bağlantısı, kural kodu, durum, sorumlu grup (' . count($sd['data']['rows'] ?? []) . ' satır)');
    $sr = Search::getDatas(Rule::class, ['criteria' => [], 'reset' => 'reset', 'forcetoview' => [1, 3, 4, 5, 6]]);
    $rc = '';
    foreach ($sr['data']['rows'] ?? [] as $row) {
        foreach ($row as $col) {
            if (is_array($col) && isset($col['displayname'])) {
                $rc .= ' ' . $col['displayname'];
            }
        }
    }
    iq_ok(str_contains($rc, 'UI-01') && str_contains($rc, 'Zorunlu alan') && str_contains($rc, 'Bilgisayar'), 'Kurallar listesi verisi: kod, şablon adı, varlık tipi');

    $f = $DB->request(['FROM' => Finding::getTable(), 'WHERE' => ['items_id' => $s['c42'], 'rules_id' => $rid]])->current();
    $p = page('front/finding.form.php', 'GET', ['id' => $f['id']]);
    $save('07_bulgu_detay', $p);
    iq_ok($ok200($p) && str_contains($p['body'], 'Beklenen durum') && str_contains($p['body'], 'Konum dolu olmalı') && str_contains($p['body'], 'Yeniden kontrol et') && str_contains($p['body'], 'groups_id_assign'),
        'Bulgu detayı: beklenen / mevcut, işlemler, atama alanları ' . $p['error']);
    iq_ok(str_contains($p['body'], 'Envanter veri kalitesi - IQT LT-042'), 'Bulgu detayı: bağlı destek kaydı listede');

    $p = page('front/finding.form.php', 'POST', [], ['id' => $f['id'], 'take' => 1]);
    iq_ok($p['code'] === 302 && Db::row(Finding::getTable(), ['id' => $f['id']])['status'] === Finding::IN_PROGRESS, 'Üstlen → İşlemde ' . msgs($p));
    $p = page('front/finding.form.php', 'POST', [], ['id' => $f['id'], 'assign' => 1, 'groups_id_assign' => $s['gNet'], 'users_id_assign' => $s['old']]);
    iq_ok($p['code'] === 302 && str_contains(msgs($p), 'aktif değil') && (int) Db::row(Finding::getTable(), ['id' => $f['id']])['groups_id_assign'] !== $s['gNet'], 'pasif kullanıcıya elle atama reddedildi: ' . msgs($p));
    $p = page('front/finding.form.php', 'POST', [], ['id' => $f['id'], 'assign' => 1, 'groups_id_assign' => $s['gNet'], 'users_id_assign' => 0]);
    $fx = Db::row(Finding::getTable(), ['id' => $f['id']]);
    iq_ok($p['code'] === 302 && (int) $fx['groups_id_assign'] === $s['gNet'] && $fx['assign_reason'] === 'manual', 'gruba elle atama ' . msgs($p));
    $p = page('front/finding.form.php', 'POST', [], ['id' => $f['id'], 'mark_fixed' => 1, 'note' => 'konum girdim']);
    iq_ok($p['code'] === 302 && str_contains(msgs($p), 'Yeniden kontrol') && Db::row(Finding::getTable(), ['id' => $f['id']])['status'] === Finding::OPEN, '"Düzelttim" → anında yeniden kontrol; veri değişmediği için açık kaldı: ' . msgs($p));
    (new Computer())->update(['id' => $s['c42'], 'locations_id' => $s['loc']]);
    $p = page('front/finding.form.php', 'POST', [], ['id' => $f['id'], 'recheck' => 1]);
    iq_ok($p['code'] === 302 && Db::row(Finding::getTable(), ['id' => $f['id']])['status'] === Finding::RESOLVED, 'konum girildikten sonra "Yeniden kontrol et" → Çözüldü: ' . msgs($p));

    $p = page('front/jobs.php');
    $save('08_isler', $p);
    iq_ok($ok200($p) && str_contains($p['body'], 'Taramalar') && str_contains($p['body'], 'iqqueue') && str_contains($p['body'], 'front/cron.php'), 'İşler: taramalar, görevler, cron satırı ' . $p['error']);

    $p = page('front/config.php', 'GET', ['entities_id' => $s['eA']]);
    $save('09_ayarlar', $p);
    iq_ok($ok200($p) && str_contains($p['body'], 'Alan kataloğu') && str_contains($p['body'], 'core:locations_id') && str_contains($p['body'], 'Bu birimin kendi ayarı var'), 'Ayarlar: katalog ve birim ayarı ' . $p['error']);
    $p = page('front/config.php', 'POST', [], ['entities_id' => $s['eA'], 'batch_size' => 150, 'scan_budget_sec' => 200, 'queue_budget_sec' => 100, 'job_max_attempts' => 4, 'preview_limit' => 40, 'low_coverage_pct' => 75, 'save_global' => 1]);
    iq_ok($p['code'] === 302 && (int) Config::getConfigurationValues('plugin:inventoryquality')['batch_size'] === 150, 'Ayarlar: genel ayarlar kaydedildi');
    $p = page('front/config.php', 'POST', [], ['entities_id' => $s['eA'], 'sync_catalog' => 1]);
    iq_ok($p['code'] === 302 && str_contains(msgs($p), 'kataloğu yenilendi'), 'Ayarlar: kataloğu yenile ' . msgs($p));

    $p = page('front/rule.form.php', 'GET', ['id' => $rid]);
    iq_ok($ok200($p) && str_contains($p['body'], 'Pasifleştir') && str_contains($p['body'], 'v1'), 'kural formu (etkin): sürüm tablosu, pasifleştir düğmesi');
    $p = page('front/rule.form.php', 'POST', [], ['id' => $rid4, 'remove' => 1]);
    iq_ok(countElementsInTable(Rule::getTable(), ['id' => $rid4]) === 1 || str_contains(msgs($p), 'silindi'), 'kural silme: değerlendirmesi yoksa silinir, varsa reddedilir: ' . msgs($p));

    // Varlık sekmesi
    $c = new Computer();
    $c->getFromDB($s['c42']);
    $tabName = (new AssetTab())->getTabNameForItem($c);
    ob_start();
    AssetTab::displayTabContentForItem($c);
    $tab = ob_get_clean();
    if ($snap) {
        file_put_contents("$snapDir/10_varlik_sekmesi.html", '<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="/lib/base.min.css"><link rel="stylesheet" href="/css_compiled/css_glpi.min.css"><body class="p-3">' . $tab);
    }
    iq_ok(str_contains(strip_tags((string) $tabName), 'Veri Kalitesi') && str_contains($tab, 'Uygulanan kurallar') && str_contains($tab, 'UI-01'), 'Varlık sekmesi "Veri Kalitesi": bulgular + uygulanan kurallar');

    // Yetki ve birim yalıtımı
    $p = page('front/index.php', 'GET', [], [], $s['tech']);
    iq_ok($p['code'] === 403, 'eklenti yetkisi olmayan profil (Technician, 0) → 403 (kod ' . $p['code'] . ')');
    $p = page('front/finding.form.php', 'GET', ['id' => $f['id']], [], $s['ub']);
    iq_ok($p['code'] === 404, 'B birimi kullanıcısı A biriminin bulgusunu açamaz → 404 (kod ' . $p['code'] . ')');
    $fb = $DB->request(['FROM' => Finding::getTable(), 'WHERE' => ['items_id' => $s['cB']]])->current();
    $p = page('front/finding.form.php', 'GET', ['id' => $fb['id']], [], $s['ub']);
    iq_ok($ok200($p), 'B birimi kullanıcısı kendi bulgusunu açar');
    $p = page('front/rule.form.php', 'POST', [], ['id' => $rid, 'deactivate' => 1], $s['ub']);
    iq_ok($p['code'] === 403 && (int) Db::row(Rule::getTable(), ['id' => $rid])['is_active'] === 1, 'B kullanıcısı kök birim kuralını görür ama değiştiremez → 403 (kod ' . $p['code'] . ')');
    $p = page('front/rule.form.php', 'GET', ['id' => $rid], [], $s['ub']);
    iq_ok($ok200($p) && !str_contains($p['body'], 'name="publish"'), 'B kullanıcısına kök birim kuralı salt okunur gösterilir');
} catch (Throwable $e) {
    iq_ok(false, 'İSTİSNA: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    iq_wipe();
    iq_cleanup_glpi();
    \GlpiPlugin\Inventoryquality\Config::set(\GlpiPlugin\Inventoryquality\Config::DEFAULTS);
}
iq_done();
