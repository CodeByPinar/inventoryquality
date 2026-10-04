<?php
// inventoryquality 0.1.0 — motor kabul testleri (AYRI test kurulumu ~/iq/glpi, veritabanı glpi11iq).
// Test verisi "IQT " önekiyle oluşturulur ve sonda silinir; eklenti tabloları test başında ve sonunda boşaltılır.
require __DIR__ . '/iq_boot.php';

use GlpiPlugin\Inventoryquality\AssetAdapter;
use GlpiPlugin\Inventoryquality\AuditLog;
use GlpiPlugin\Inventoryquality\Catalog;
use GlpiPlugin\Inventoryquality\EntityConfig;
use GlpiPlugin\Inventoryquality\Evaluation;
use GlpiPlugin\Inventoryquality\Finding;
use GlpiPlugin\Inventoryquality\FindingService;
use GlpiPlugin\Inventoryquality\Install;
use GlpiPlugin\Inventoryquality\JobQueue;
use GlpiPlugin\Inventoryquality\QualityCalculator;
use GlpiPlugin\Inventoryquality\Rule;
use GlpiPlugin\Inventoryquality\RuleEvaluator;
use GlpiPlugin\Inventoryquality\RuleTemplates;
use GlpiPlugin\Inventoryquality\ScanRunner;
use GlpiPlugin\Inventoryquality\TicketBridge;

global $DB;
iq_login(2);

function iq_wipe(): void
{
    global $DB;
    foreach (array_keys(Install::tables()) as $t) {
        if ($t !== Install::P . 'fieldmaps') {
            $DB->delete($t, ['id' => ['>', 0]]);
        }
    }
    EntityConfig::reset();
}
function iq_cleanup_glpi(): void
{
    global $DB;
    foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['name' => ['LIKE', 'Envanter veri kalitesi - IQT%']]]) as $r) {
        (new Ticket())->delete(['id' => $r['id']], true);
    }
    foreach (['Computer' => 'glpi_computers', 'Group' => 'glpi_groups', 'Location' => 'glpi_locations', 'State' => 'glpi_states'] as $cls => $tbl) {
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => $tbl, 'WHERE' => ['name' => ['LIKE', 'IQT%']]]) as $r) {
            (new $cls())->delete(['id' => $r['id']], true);
        }
    }
    foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_users', 'WHERE' => ['name' => ['LIKE', 'iqt_%']]]) as $r) {
        (new User())->delete(['id' => $r['id']], true);
    }
    foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_entities', 'WHERE' => ['name' => ['LIKE', 'IQT%']], 'ORDER' => 'level DESC']) as $r) {
        (new Entity())->delete(['id' => $r['id']], true);
    }
}
function queue(): array
{
    return AuditLog::as('cron:iqqueue', static fn() => JobQueue::process(60));
}
function fnd(int $cid, int $rid): ?array
{
    global $DB;
    $r = $DB->request(['FROM' => Finding::getTable(), 'WHERE' => ['itemtype' => 'Computer', 'items_id' => $cid, 'rules_id' => $rid]])->current();
    return $r ?: null;
}
function mkrule(string $tpl, string $code, array $in, int $sev = 2, int $w = 2): int
{
    $id = Rule::create($tpl, $code, '', 'Computer', 0, true);
    Rule::publish($id, RuleTemplates::build($tpl, 'Computer', $in), $sev, $w, 'test');
    return $id;
}

iq_cleanup_glpi();
iq_wipe();

try {
    // ------------------------------------------------------------ test verisi
    $eA = (new Entity())->add(['name' => 'IQT Birim A', 'entities_id' => 0]);
    $eB = (new Entity())->add(['name' => 'IQT Birim B', 'entities_id' => 0]);
    $stUse = (new State())->add(['name' => 'IQT Kullanımda', 'entities_id' => 0, 'is_recursive' => 1]);
    $stStore = (new State())->add(['name' => 'IQT Depoda', 'entities_id' => 0, 'is_recursive' => 1]);
    $loc = (new Location())->add(['name' => 'IQT Depo', 'entities_id' => 0, 'is_recursive' => 1]);
    $tech = (new User())->add(['name' => 'iqt_tech', 'is_active' => 1, '_profiles_id' => 6, '_entities_id' => $eA, '_is_recursive' => 1]);
    $old = (new User())->add(['name' => 'iqt_old', 'is_active' => 1, '_profiles_id' => 6, '_entities_id' => $eA, '_is_recursive' => 1]);
    $ub = (new User())->add(['name' => 'iqt_b', 'is_active' => 1, '_profiles_id' => 4, '_entities_id' => $eB, '_is_recursive' => 1]);
    (new User())->update(['id' => $old, 'is_active' => 0]);
    $gDQ = (new Group())->add(['name' => 'IQT Veri Kalitesi', 'entities_id' => $eA, 'is_recursive' => 1, 'is_assign' => 1]);
    $gNet = (new Group())->add(['name' => 'IQT Ağ Ekibi', 'entities_id' => $eA, 'is_recursive' => 1, 'is_assign' => 1]);
    $c42 = (new Computer())->add(['name' => 'IQT LT-042', 'entities_id' => $eA, 'states_id' => $stUse, 'users_id' => $old, 'locations_id' => 0]);
    $c43 = (new Computer())->add(['name' => 'IQT LT-043', 'entities_id' => $eA, 'states_id' => $stUse, 'users_id' => $tech, 'locations_id' => $loc, '_groups_id_tech' => [$gNet]]);
    $c44 = (new Computer())->add(['name' => 'IQT LT-044', 'entities_id' => $eA, 'states_id' => $stStore, 'locations_id' => 0]);
    $cB = (new Computer())->add(['name' => 'IQT LT-B01', 'entities_id' => $eB, 'states_id' => $stUse, 'locations_id' => 0]);
    iq_ok($eA > 0 && $c42 > 0 && $cB > 0 && $old > 0, 'test verisi oluşturuldu');
    $gt = countElementsInTable('glpi_groups_items', ['itemtype' => 'Computer', 'items_id' => $c43, 'groups_id' => $gNet, 'type' => 2]);
    iq_ok($gt === 1, 'LT-043 teknik grubu bağlı (glpi_groups_items tür 2)');

    EntityConfig::save($eA, ['groups_id_quality' => $gDQ, 'ticket_mode' => 'grouped', 'due_days' => 10]);

    // ------------------------------------------------------------ T03 boşluk anlamı (saf değerlendirici)
    $d = static fn(string $t, $v) => ['ok' => true, 'type' => $t, 'value' => $v];
    $ne = static fn(array $v) => RuleEvaluator::test(['field' => 'x', 'op' => 'not_empty'], ['x' => $v], time());
    iq_ok($ne($d('string', '   ')) === false && $ne($d('string', '0')) === true && $ne($d('string', null)) === false, 'T03 metin: yalnız boşluk = boş, "0" = dolu');
    iq_ok($ne($d('int', 0)) === true && $ne($d('bool', false)) === true && $ne($d('bool', null)) === false, 'T03 sayısal 0 ve false geçerli değer');
    iq_ok($ne($d('fk', 0)) === false && $ne($d('multi', [])) === false && $ne($d('multi', ['Group:1'])) === true, 'T03 açılır liste 0 = seçilmemiş; boş çoklu seçim boş');
    iq_ok(RuleEvaluator::test(['field' => 'x', 'op' => 'not_empty'], ['x' => ['ok' => false, 'error' => 'e']], time()) === null, 'okunamayan alan → bilinmiyor (boş sayılmaz)');

    // ------------------------------------------------------------ T14 puan
    $s = QualityCalculator::fromWeights(7, 3, 2);
    iq_ok($s['score'] === 70.0 && $s['coverage'] === 83.3 && QualityCalculator::pct($s['score'], '-') === '%70' && QualityCalculator::pct($s['coverage'], '-') === '%83,3', 'T14 7 PASS / 3 FAIL / 2 UNKNOWN → %70 puan, %83,3 kapsam');
    $z = QualityCalculator::fromWeights(0, 0, 2);
    iq_ok($z['score'] === null && $z['coverage'] === 0.0 && QualityCalculator::fromWeights(0, 0, 0)['coverage'] === null, 'değerlendirilen 0 → Hesaplanamadı; uygulanabilir 0 → Kapsam yok (ikisi de %100 değil)');

    // ------------------------------------------------------------ kurallar
    $r1 = mkrule('required', 'IQT-01', ['field' => 'core:locations_id']);
    $r2 = mkrule('inactive_user', 'IQT-03', ['field' => 'core:users_id'], 3, 2);
    $r3 = mkrule('conditional', 'IQT-05', ['field' => 'core:locations_id', 'pre_field' => 'core:states_id', 'pre_op' => 'in', 'pre_values' => [$stUse]]);
    $r4 = mkrule('responsible', 'IQT-02', ['scope_states' => [$stUse]], 3, 3);
    try {
        mkrule('valueset', 'IQT-04', ['field' => 'core:states_id', 'values' => [999999]]);
        iq_ok(false, 'olmayan değer kimliği reddedilmeli');
    } catch (InvalidArgumentException $e) {
        iq_ok(true, 'değer kümesinde var olmayan kayıt kimliği reddedildi (görünen ad değil gerçek kimlik)');
    }
    try {
        Rule::create('required', 'IQT-01', '', 'Computer', 0, true);
        iq_ok(false, 'aynı kod reddedilmeli');
    } catch (InvalidArgumentException $e) {
        iq_ok(true, 'kural kodu tekil');
    }
    foreach ([$r1, $r2, $r3, $r4] as $r) {
        Rule::setActive($r, true);
    }
    $before = [countElementsInTable(Finding::getTable(), []), countElementsInTable(Evaluation::TABLE, []), countElementsInTable('glpi_tickets', [])];
    $prev = ScanRunner::preview(['itemtype' => 'Computer', 'entities' => Rule::scopeEntities(0, true), 'v' => ['def' => Rule::version((int) Db_rule($r1)['ruleversions_id'])['def']]], 50);
    $afterPrev = [countElementsInTable(Finding::getTable(), []), countElementsInTable(Evaluation::TABLE, []), countElementsInTable('glpi_tickets', [])];
    iq_ok($prev['counts']['FAIL'] === 3 && $before === $afterPrev, 'önizleme etkiyi gösterir (3 uygunsuz) ve bulgu / değerlendirme / destek kaydı üretmez');
    queue(); // varlık oluşturma olayları + scan_rule işleri

    // ------------------------------------------------------------ T01 tam tarama
    $snap = $DB->request(['FROM' => 'glpi_computers', 'WHERE' => ['id' => $c42]])->current();
    $sid = ScanRunner::start('full', null, 'test');
    while (ScanRunner::tick(30)['slices'] > 0 && Db_scan($sid)['status'] === 'running') {
    }
    $run = Db_scan($sid);
    iq_ok($run['status'] === 'completed' && (int) $run['n_fail'] > 0, "tam tarama tamamlandı (FAIL={$run['n_fail']}, PASS={$run['n_pass']}, NA={$run['n_na']})");
    $f1 = fnd($c42, $r1);
    $after = $DB->request(['FROM' => 'glpi_computers', 'WHERE' => ['id' => $c42]])->current();
    iq_ok($f1 && $f1['status'] === Finding::OPEN && countElementsInTable(Finding::getTable(), ['items_id' => $c42, 'rules_id' => $r1]) === 1, 'T01 zorunlu alan boş → bir FAIL ve tek bulgu');
    iq_ok($snap == $after, 'T01 mevcut veri değişmedi');
    iq_ok(fnd($c44, $r3) === null && (Evaluation::forItem('Computer', $c44)[$r3]['result'] ?? '') === RuleEvaluator::NA, 'DQ-05: durum "Depoda" → önkoşul sağlanmıyor, NOT_APPLICABLE, bulgu yok');
    iq_ok(fnd($c44, $r4) === null && !isset(Evaluation::forItem('Computer', $c44)[$r4]), 'DQ-02 durum kapsamı dışındaki varlık değerlendirilmez');
    iq_ok(fnd($c43, $r4) === null && (Evaluation::forItem('Computer', $c43)[$r4]['result'] ?? '') === RuleEvaluator::PASS, 'DQ-02: teknik grubu olan varlık PASS');
    iq_ok(($f4 = fnd($c42, $r4)) && $f4['status'] === Finding::OPEN, 'DQ-02: sorumlusu olmayan aktif varlık FAIL');

    // ------------------------------------------------------------ T04 atama
    $f2 = fnd($c42, $r2);
    iq_ok($f2 && (int) $f2['users_id_assign'] !== $old && (int) $f2['groups_id_assign'] === $gDQ && $f2['assign_reason'] === 'entity_group', 'T04 pasif kullanıcı bulgusu pasif kişiye değil birimin veri kalitesi grubuna atandı');
    $fb = fnd($cB, $r1);
    iq_ok($fb && $fb['assign_state'] === 'waiting', 'T04 sorumlu bulunamayan bulgu "Atama bekliyor"');
    iq_ok(!\GlpiPlugin\Inventoryquality\AssignmentResolver::validUser($old, $eA) && \GlpiPlugin\Inventoryquality\AssignmentResolver::validUser($tech, $eA), 'pasif kullanıcı atanamaz, aktif teknisyen atanabilir');

    // ------------------------------------------------------------ destek kaydı
    queue();
    $links = iterator_to_array($DB->request(['FROM' => TicketBridge::LINKS, 'WHERE' => ['findings_id' => [(int) $f1['id'], (int) $f2['id'], (int) $f4['id']], 'is_open' => 1]]), false);
    $tids = array_values(array_unique(array_map(static fn($l) => (int) $l['tickets_id'], $links)));
    iq_ok(count($links) >= 3 && count($tids) === 1, 'aynı varlık + aynı sorumlu grup → tek destek kaydında birden çok bulgu (' . count($links) . ' bağlantı)');
    $t = new Ticket();
    $t->getFromDB($tids[0] ?? 0);
    iq_ok((int) ($t->fields['entities_id'] ?? -1) === $eA && countElementsInTable('glpi_items_tickets', ['tickets_id' => $tids[0] ?? 0, 'itemtype' => 'Computer', 'items_id' => $c42]) === 1
        && countElementsInTable('glpi_groups_tickets', ['tickets_id' => $tids[0] ?? 0, 'groups_id' => $gDQ, 'type' => CommonITILActor::ASSIGN]) === 1
        && str_contains((string) $t->fields['content'], 'IQT-01'), 'kayıt: doğru birim, varlık bağlantısı (tür + kimlik), atanan grup, kural kodları içerikte');
    iq_ok(countElementsInTable(TicketBridge::LINKS, ['findings_id' => (int) $fb['id']]) === 0, 'atama bekleyen / yalnız-bulgu modundaki birimde kayıt açılmadı');

    // ------------------------------------------------------------ T02 tekrar / eşzamanlı
    $sid2 = ScanRunner::start('full', null, 'test');
    while (Db_scan($sid2)['status'] === 'running' && ScanRunner::tick(30)['slices'] > 0) {
    }
    $rr = Rule::activeRules('Computer', [$r1])[0];
    $data = AssetAdapter::readOne('Computer', $c42, ['core:locations_id', 'core:users_id_tech', 'rel:groups_tech']);
    $res = RuleEvaluator::evaluate($rr['v']['def'], $data);
    FindingService::apply('Computer', $c42, $eA, $rr, $res, $data);
    FindingService::apply('Computer', $c42, $eA, $rr, $res, $data);
    TicketBridge::syncAsset('Computer', $c42);
    TicketBridge::syncAsset('Computer', $c42);
    queue();
    $f1b = fnd($c42, $r1);
    iq_ok(countElementsInTable(Finding::getTable(), ['items_id' => $c42, 'rules_id' => $r1]) === 1 && (int) $f1b['seen_count'] >= 3, "T02 tekrar tarama + çift uygulama → tek güncel bulgu (tekrar sayısı {$f1b['seen_count']})");
    $open = array_unique(array_column(iterator_to_array($DB->request(['FROM' => TicketBridge::LINKS, 'WHERE' => ['findings_id' => (int) $f1['id'], 'is_open' => 1]]), false), 'tickets_id'));
    iq_ok(count($open) === 1, 'T02 tek açık takip kaydı korundu');

    // ------------------------------------------------------------ düzeltme: GLPI ekranından (olay → kuyruk → yeniden kontrol)
    (new Computer())->update(['id' => $c42, 'locations_id' => $loc]);
    iq_ok(countElementsInTable(JobQueue::TABLE, ['job_key' => "recheck:Computer:$c42", 'status' => 'pending']) === 1, 'varlık kaydedilince yalnız o kayıt kuyruğa alındı (tam tarama yok)');
    queue();
    $f1c = fnd($c42, $r1);
    $f3c = fnd($c42, $r3);
    iq_ok($f1c['status'] === Finding::RESOLVED && $f1c['last_result'] === RuleEvaluator::PASS, 'konum girildi → yeniden kontrol PASS → bulgu çözüldü');
    $cyc = Finding::cycles((int) $f1c['id']);
    iq_ok($cyc && $cyc[0]['close_status'] === Finding::RESOLVED && $cyc[0]['closed_at'] !== null, 'dönem kapandı (çözüm kaydı)');
    $t->getFromDB($tids[0]);
    iq_ok(!in_array((int) $t->fields['status'], [CommonITILObject::SOLVED, CommonITILObject::CLOSED], true)
        && countElementsInTable('glpi_itilfollowups', ['itemtype' => 'Ticket', 'items_id' => $tids[0]]) >= 1, 'diğer bulgular (pasif kullanıcı, sorumlu) açık → kayıt çözülmedi, takip notu eklendi');

    // ------------------------------------------------------------ T10 kayıt elle kapatıldı
    $DB->update('glpi_tickets', ['status' => CommonITILObject::CLOSED], ['id' => $tids[0]]);
    (new Ticket())->getFromDB($tids[0]);
    TicketBridge::checkTicket($tids[0]);
    $f2d = fnd($c42, $r2);
    $newLinks = iterator_to_array($DB->request(['FROM' => TicketBridge::LINKS, 'WHERE' => ['findings_id' => (int) $f2d['id'], 'is_open' => 1]]), false);
    iq_ok($f2d['status'] === Finding::OPEN, 'T10 kayıt kapatıldı ama kural hâlâ FAIL → bulgu açık');
    iq_ok(count($newLinks) === 1 && (int) $newLinks[0]['tickets_id'] !== $tids[0], 'T10 politika: eski bağlantı saklandı, yeni takip kaydı açıldı');
    iq_ok(countElementsInTable(AuditLog::TABLE, ['action' => 'ticket_closed_early', 'items_id' => $tids[0]]) === 1, 'T10 tutarsızlık denetim izine yazıldı');
    $nt = new Ticket();
    $nt->getFromDB((int) ($newLinks[0]['tickets_id'] ?? 0));
    iq_ok(str_contains((string) ($nt->fields['content'] ?? ''), '#' . $tids[0]), 'yeni kayıt önceki kaydı anıyor');

    // ------------------------------------------------------------ tümü çözülünce çözüm (GLPI politikası korunur)
    (new User())->update(['id' => $old, 'is_active' => 1]);
    queue(); // user_change → recheck
    queue();
    (new Computer())->update(['id' => $c42, 'users_id_tech' => $tech]);
    queue();
    $f2e = fnd($c42, $r2);
    $f4e = fnd($c42, $r4);
    $nt->getFromDB((int) $newLinks[0]['tickets_id']);
    iq_ok($f2e['status'] === Finding::RESOLVED && $f4e['status'] === Finding::RESOLVED, 'kullanıcı aktifleşti + teknik sorumlu atandı → bulgular PASS ile çözüldü');
    iq_ok((int) $nt->fields['status'] === CommonITILObject::SOLVED && countElementsInTable('glpi_itilsolutions', ['itemtype' => 'Ticket', 'items_id' => $nt->getID()]) === 1,
        'bağlı tüm bulgular çözüldü → kayda çözüm eklendi (Çözüldü; Kapalı\'ya GLPI politikası götürür)');

    // ------------------------------------------------------------ tekrar açılma (yeni dönem)
    (new Computer())->update(['id' => $c42, 'locations_id' => 0]);
    queue();
    $f1r = fnd($c42, $r1);
    iq_ok($f1r['status'] === Finding::OPEN && (int) $f1r['cycle'] === 2 && count(Finding::cycles((int) $f1r['id'])) === 2, 'çözülmüş kayıtta yeniden FAIL → yeni dönem (önceki çözüm geçmişi korundu)');
    $l2 = iterator_to_array($DB->request(['FROM' => TicketBridge::LINKS, 'WHERE' => ['findings_id' => (int) $f1r['id'], 'is_open' => 1]]), false);
    iq_ok(count($l2) === 1 && (int) $l2[0]['cycle'] === 2 && (int) $l2[0]['tickets_id'] !== $tids[0], 'kapalı kayıt yerine yeni takip kaydı açıldı');

    // ------------------------------------------------------------ "Düzelttim" beyanı tek başına çözmez
    FindingService::markFixed((int) $f1r['id'], 'düzelttim');
    ScanRunner::recheckItem('Computer', $c42);
    iq_ok(fnd($c42, $r1)['status'] === Finding::OPEN, 'beyan + yeniden kontrol FAIL → bulgu açık kaldı (doğrulama başarısız)');

    // ------------------------------------------------------------ T12 okunamayan veri
    $v1 = Rule::version((int) Db_rule($r1)['ruleversions_id']);
    $broken = $v1['def'];
    $broken['assert']['field'] = 'core:yok_boyle_alan';
    $DB->update(Rule::VERSIONS, ['definition' => json_encode($broken)], ['id' => $v1['id']]);
    ScanRunner::recheckItem('Computer', $c42);
    $f1u = fnd($c42, $r1);
    iq_ok($f1u['status'] === Finding::REVIEW && $f1u['last_result'] === RuleEvaluator::UNKNOWN, 'T12 alan okunamadı → UNKNOWN, bulgu çözülmedi ("İnceleme gerekli")');
    $DB->update(Rule::VERSIONS, ['definition' => json_encode($v1['def'])], ['id' => $v1['id']]);

    // ------------------------------------------------------------ T13 şema kırılması / yeni sürüm
    $DB->update(Rule::VERSIONS, ['definition' => json_encode(array_merge($v1['def'], ['field_types' => ['core:locations_id' => 'string']]))], ['id' => $v1['id']]);
    Rule::validateAll();
    iq_ok(str_contains((string) Db_rule($r1)['suspended_reason'], 'veri tipi'), 'T13 alanın veri tipi değişti → kural askıya alındı, görünür neden');
    iq_ok(!in_array($r1, array_map(static fn($r) => (int) $r['id'], Rule::activeRules('Computer')), true), 'askıdaki kural değerlendirilmez');
    $DB->update(Rule::VERSIONS, ['definition' => json_encode($v1['def'])], ['id' => $v1['id']]);
    Rule::validateAll();
    iq_ok((string) Db_rule($r1)['suspended_reason'] === '', 'şema düzelince askı kalktı');
    $vNew = Rule::publish($r1, RuleTemplates::build('required', 'Computer', ['field' => 'core:locations_id']), 4, 5, 'önem arttı');
    queue();
    while (ScanRunner::tick(30)['slices'] > 0) {
    }
    $e = Evaluation::forItem('Computer', $c42)[$r1] ?? [];
    iq_ok((int) ($e['ruleversions_id'] ?? 0) === $vNew && (int) $e['weight'] === 5 && countElementsInTable(Finding::getTable(), ['items_id' => $c42, 'rules_id' => $r1]) === 1,
        'T13 yeni kural sürümü kapsamı yeniden taradı; yinelenen bulgu yok');

    // ------------------------------------------------------------ T05 birim yalıtımı (ayrı süreç, B kullanıcısı)
    $out = [];
    exec('php ' . escapeshellarg(__DIR__ . '/iq_t05.php') . " $ub " . (int) $f1r['id'] . ' ' . (int) $fb['id'] . " $eB 2>&1", $out);
    foreach ($out as $line) {
        if (preg_match('/^\s+(OK|FAIL)\s+(.*)$/', $line, $m)) {
            iq_ok($m[1] === 'OK', $m[2]);
        } elseif (trim($line) !== '' && !str_starts_with($line, 'TÜMÜ') && !str_starts_with($line, 'BAŞARISIZ')) {
            echo "  [t05] $line\n";
        }
    }

    // ------------------------------------------------------------ kural pasifleştirme
    Rule::setActive($r4, false);
    $f4x = fnd($cB, $r4) ?? fnd($c42, $r4);
    iq_ok(countElementsInTable(Finding::getTable(), ['rules_id' => $r4, 'status' => Finding::ACTIVE]) === 0 && countElementsInTable(Finding::getTable(), ['rules_id' => $r4, 'status' => Finding::RESOLVED]) >= 1
        && countElementsInTable(Evaluation::TABLE, ['rules_id' => $r4]) === 0, 'kural kapatıldı → aktif bulgular kapsam dışı (çözüldü sayılmadı), puandan çıktı');

    // ------------------------------------------------------------ varlık silindi
    (new Computer())->delete(['id' => $cB]);
    queue();
    iq_ok(fnd($cB, $r1)['status'] === Finding::OUT_OF_SCOPE && !Evaluation::forItem('Computer', $cB), 'varlık çöp kutusuna → bulgu gerekçeli kapsam dışı, değerlendirme kaldırıldı');

    // ------------------------------------------------------------ yarım tarama toplu kapatmaz
    \GlpiPlugin\Inventoryquality\Config::set(['batch_size' => 1]);
    $sid3 = ScanRunner::start('full', null, 'test');
    ScanRunner::runSlice($sid3, time() - 1); // bütçe yok: bir parti bile işlenmeden durur
    $DB->update(ScanRunner::TABLE, ['heartbeat_at' => date('Y-m-d H:i:s', time() - 7 * 3600)], ['id' => $sid3]);
    $activeBefore = countElementsInTable(Finding::getTable(), ['status' => Finding::ACTIVE]);
    ScanRunner::markStale();
    iq_ok(Db_scan($sid3)['status'] === 'partial' && countElementsInTable(Finding::getTable(), ['status' => Finding::ACTIVE]) === $activeBefore, 'T12 yarıda kalan tarama "Kısmi"; değerlendirilmeyen bulgular kapatılmadı');
    \GlpiPlugin\Inventoryquality\Config::set(['batch_size' => 200]);

    // ------------------------------------------------------------ puan (gerçek veri)
    $sc = QualityCalculator::compute();
    iq_ok($sc['score'] !== null && $sc['coverage'] !== null, sprintf('güncel puan %s · kapsam %s (ağırlık PASS %d / FAIL %d / UNKNOWN %d)',
        QualityCalculator::pct($sc['score'], '-'), QualityCalculator::pct($sc['coverage'], '-'), $sc['pass'], $sc['fail'], $sc['unknown']));

    // ------------------------------------------------------------ T15 güncelleme / yeniden kurulum
    $nf = countElementsInTable(Finding::getTable(), []);
    Install::install();
    iq_ok(countElementsInTable(Finding::getTable(), []) === $nf && countElementsInTable('glpi_crontasks', ['itemtype' => 'GlpiPlugin\\Inventoryquality\\Cron']) === 3, 'T15 yeniden kurulum veriyi korudu, kopya otomatik görev oluşmadı');
    iq_ok(countElementsInTable(AuditLog::TABLE, ['actor' => 'cron:iqqueue']) > 0 && countElementsInTable(AuditLog::TABLE, ['actor' => 'user', 'users_id' => 2]) > 0, 'denetim izi: servis kimliği ile kullanıcı ayrı');
} catch (Throwable $e) {
    iq_ok(false, 'İSTİSNA: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    echo $e->getTraceAsString() . "\n";
} finally {
    iq_wipe();
    iq_cleanup_glpi();
}
iq_done();

function Db_rule(int $id): array
{
    return \GlpiPlugin\Inventoryquality\Db::row(Rule::getTable(), ['id' => $id]) ?? [];
}
function Db_scan(int $id): array
{
    return \GlpiPlugin\Inventoryquality\Db::row(ScanRunner::TABLE, ['id' => $id]) ?? [];
}
