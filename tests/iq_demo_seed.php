<?php
// Gerçek GLPI'da DEMO verisi: ayrı "IQ Demo Birimi" + "IQ-DEMO" önekli kayıtlar; kurallar yalnız bu birime uygulanır.
// Kullanım: php iq_demo_seed.php seed <yönetici_kullanıcı>  |  php iq_demo_seed.php clean <yönetici_kullanıcı>
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING & ~E_USER_DEPRECATED);
$root = getenv('GLPI_ROOT_DIR') ?: '/var/www/glpi';
chdir($root);
@mkdir('/tmp/glpicache', 0777, true);
@mkdir('/tmp/glpilog', 0777, true);
define('GLPI_CACHE_DIR', '/tmp/glpicache');
define('GLPI_LOG_DIR', '/tmp/glpilog');
$_SERVER['REQUEST_URI'] = '/';
require $root . '/vendor/autoload.php';
(new \Glpi\Kernel\Kernel('production'))->boot();

use GlpiPlugin\Inventoryquality\AuditLog;
use GlpiPlugin\Inventoryquality\EntityConfig;
use GlpiPlugin\Inventoryquality\Finding;
use GlpiPlugin\Inventoryquality\Install;
use GlpiPlugin\Inventoryquality\JobQueue;
use GlpiPlugin\Inventoryquality\Rule;
use GlpiPlugin\Inventoryquality\RuleTemplates;
use GlpiPlugin\Inventoryquality\ScanRunner;

global $DB;
[, $mode, $admin] = $argv + [null, 'seed', 'btadmin'];
$u = $DB->request(['FROM' => 'glpi_users', 'WHERE' => ['name' => $admin]])->current();
if (!$u) {
    exit("yönetici bulunamadı: $admin\n");
}
$auth = new Auth();
$auth->auth_succeded = true;
$auth->user = new User();
$auth->user->getFromDB((int) $u['id']);
Session::init($auth);

function demo_ent(): array
{
    global $DB;
    return array_map('intval', array_column(iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_entities', 'WHERE' => ['name' => 'IQ Demo Birimi']]), false), 'id'));
}

function demo_clean(): void
{
    global $DB;
    $ents = demo_ent();
    $rules = array_map('intval', array_column(iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => Rule::getTable(), 'WHERE' => ['code' => ['LIKE', 'DEMO-%']]]), false), 'id'));
    $fids = $rules ? array_map('intval', array_column(iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => Finding::getTable(), 'WHERE' => ['rules_id' => $rules]]), false), 'id')) : [];
    if ($fids) {
        foreach ($DB->request(['SELECT' => ['tickets_id'], 'FROM' => Install::P . 'ticketlinks', 'WHERE' => ['findings_id' => $fids]]) as $l) {
            (new Ticket())->delete(['id' => $l['tickets_id']], true);
        }
        foreach (['ticketlinks', 'cycles', 'exceptions'] as $t) {
            $DB->delete(Install::P . $t, ['findings_id' => $fids]);
        }
        $cids = array_column(iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => Install::P . 'corrections', 'WHERE' => ['findings_id' => $fids]]), false), 'id');
        if ($cids) {
            $DB->delete(Install::P . 'approvals', ['corrections_id' => $cids]);
            $DB->delete(Install::P . 'corrections', ['id' => $cids]);
        }
        $DB->delete(Finding::getTable(), ['id' => $fids]);
    }
    if ($rules) {
        $DB->delete(Install::P . 'evaluations', ['rules_id' => $rules]);
        $DB->delete(Rule::VERSIONS, ['rules_id' => $rules]);
        $DB->delete(Rule::getTable(), ['id' => $rules]);
    }
    foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_computers', 'WHERE' => ['name' => ['LIKE', 'IQ-DEMO%']]]) as $r) {
        $DB->delete(Install::P . 'attestations', ['itemtype' => 'Computer', 'items_id' => $r['id']]);
        $DB->delete('glpi_agents', ['itemtype' => 'Computer', 'items_id' => $r['id']]);
        $DB->delete(JobQueue::TABLE, ['job_key' => ['LIKE', '%:Computer:' . $r['id']]]);
        (new Computer())->delete(['id' => $r['id']], true);
    }
    // Gruplar / kullanıcılar / sözlükler doğrudan silinir: bazı kurulumlarda eski eklenti tabloları (ör. statecheck)
    // GLPI'nin Group / User silme kancalarını bozuyor. İlişki satırları da temizlenir.
    $gids = array_column(iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_groups', 'WHERE' => ['name' => ['LIKE', 'IQ-DEMO%']]]), false), 'id');
    if ($gids) {
        foreach (['glpi_groups_users', 'glpi_groups_items', 'glpi_groups_tickets'] as $t) {
            $DB->delete($t, ['groups_id' => $gids]);
        }
        $DB->delete('glpi_groups', ['id' => $gids]);
    }
    $uids = array_column(iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_users', 'WHERE' => ['name' => ['LIKE', 'iqdemo_%']]]), false), 'id');
    if ($uids) {
        foreach (['glpi_profiles_users', 'glpi_groups_users', 'glpi_tickets_users'] as $t) {
            $DB->delete($t, ['users_id' => $uids]);
        }
        $DB->delete('glpi_users', ['id' => $uids]);
    }
    $DB->delete('glpi_locations', ['name' => ['LIKE', 'IQ-DEMO%']]);
    $DB->delete('glpi_states', ['name' => ['LIKE', 'IQ-DEMO%']]);
    foreach ($ents as $e) {
        $DB->delete(EntityConfig::TABLE, ['entities_id' => $e]);
        try {
            (new Entity())->delete(['id' => $e], true);
        } catch (\Throwable $ex) {
            $DB->delete('glpi_profiles_users', ['entities_id' => $e]);
            $DB->delete('glpi_entities', ['id' => $e]);
        }
    }
    $DB->delete(ScanRunner::TABLE, ['actor' => 'demo']);
}

demo_clean();
if ($mode === 'clean') {
    exit("demo verisi silindi\n");
}

// ---------------------------------------------------------------- birim, sözlükler, kişiler
$e = (new Entity())->add(['name' => 'IQ Demo Birimi', 'entities_id' => 0, 'comment' => 'inventoryquality demo verisi — "php iq_demo_seed.php clean" ile silinir']);
$st = [];
foreach (['Kullanımda', 'Depoda', 'Hurdaya ayrılacak'] as $n) {
    $st[$n] = (new State())->add(['name' => "IQ-DEMO $n", 'entities_id' => $e, 'is_recursive' => 1]);
}
$loc = [];
foreach (['Genel Müdürlük 3. Kat', 'Bilgi İşlem Odası', 'Ankara Şube', 'Depo'] as $n) {
    $loc[$n] = (new Location())->add(['name' => "IQ-DEMO $n", 'entities_id' => $e, 'is_recursive' => 1]);
}
$gDQ = (new Group())->add(['name' => 'IQ-DEMO Veri Kalitesi', 'entities_id' => $e, 'is_recursive' => 1, 'is_assign' => 1]);
$gNet = (new Group())->add(['name' => 'IQ-DEMO Son Kullanıcı Destek', 'entities_id' => $e, 'is_recursive' => 1, 'is_assign' => 1]);
$gAppr = (new Group())->add(['name' => 'IQ-DEMO Envanter Onay', 'entities_id' => $e, 'is_recursive' => 1]);
$users = [];
foreach ([['iqdemo_ayse', 'Ayşe', 'Yılmaz', 1], ['iqdemo_mehmet', 'Mehmet', 'Kaya', 1], ['iqdemo_zeynep', 'Zeynep', 'Demir', 1], ['iqdemo_eski', 'Ali', 'Ayrıldı', 0]] as [$n, $f, $r, $act]) {
    $users[$n] = (new User())->add(['name' => $n, 'firstname' => $f, 'realname' => $r, 'is_active' => 1, '_profiles_id' => 1, '_entities_id' => $e, '_is_recursive' => 1]);
    if (!$act) {
        (new User())->update(['id' => $users[$n], 'is_active' => 0]);
    }
}
(new Group_User())->add(['groups_id' => $gAppr, 'users_id' => (int) $u['id']]);
(new Group_User())->add(['groups_id' => $gNet, 'users_id' => $users['iqdemo_zeynep']]);

// ---------------------------------------------------------------- bilgisayarlar (bilinçli sorunlarla)
$C = static function (string $name, array $f) use ($e): int {
    return (int) (new Computer())->add(['name' => "IQ-DEMO $name", 'entities_id' => $e] + $f);
};
$c = [];
$c['LT-042'] = $C('LT-042', ['states_id' => $st['Kullanımda'], 'users_id' => $users['iqdemo_eski'], 'serial' => 'PF2X0042']);
$c['LT-043'] = $C('LT-043', ['states_id' => $st['Kullanımda'], 'users_id' => $users['iqdemo_ayse'], 'locations_id' => $loc['Genel Müdürlük 3. Kat'], 'serial' => 'PF2X0043', '_groups_id_tech' => [$gNet]]);
$c['LT-044'] = $C('LT-044', ['states_id' => $st['Kullanımda'], 'users_id' => $users['iqdemo_mehmet'], 'locations_id' => $loc['Ankara Şube'], 'serial' => 'PF2X0044', 'users_id_tech' => $users['iqdemo_zeynep']]);
$c['LT-045'] = $C('LT-045', ['states_id' => $st['Kullanımda'], 'users_id' => $users['iqdemo_ayse'], 'serial' => '']);
$c['PC-101'] = $C('PC-101', ['states_id' => $st['Kullanımda'], 'users_id' => $users['iqdemo_zeynep'], 'locations_id' => $loc['Bilgi İşlem Odası'], 'serial' => 'CZC1010', '_groups_id_tech' => [$gNet]]);
$c['PC-102'] = $C('PC-102', ['states_id' => $st['Kullanımda'], 'locations_id' => $loc['Bilgi İşlem Odası'], 'serial' => 'CZC1020']);
$c['PC-103'] = $C('PC-103', ['states_id' => $st['Depoda'], 'serial' => 'CZC1030']);
$c['PC-104'] = $C('PC-104', ['states_id' => $st['Depoda'], 'locations_id' => $loc['Depo'], 'serial' => 'CZC1040']);
$c['SRV-01'] = $C('SRV-01', ['states_id' => $st['Kullanımda'], 'locations_id' => $loc['Bilgi İşlem Odası'], 'serial' => 'SRV0001', 'users_id_tech' => $users['iqdemo_mehmet'], '_groups_id_tech' => [$gNet]]);
$c['SRV-02'] = $C('SRV-02', ['states_id' => $st['Kullanımda'], 'locations_id' => $loc['Bilgi İşlem Odası'], 'serial' => 'SRV0002']);
$c['ESKI-07'] = $C('ESKI-07', ['states_id' => $st['Hurdaya ayrılacak'], 'users_id' => $users['iqdemo_eski']]);
// Ajan bilgisi: bazıları güncel, bazıları eski.
$now = time();
foreach (['LT-043' => 1, 'LT-044' => 2, 'PC-101' => 0, 'SRV-01' => 0, 'SRV-02' => 45, 'PC-102' => 60] as $n => $days) {
    $DB->insert('glpi_agents', ['deviceid' => 'iqdemo-' . strtolower($n), 'name' => 'iqdemo-' . strtolower($n), 'itemtype' => 'Computer', 'items_id' => $c[$n],
        'entities_id' => $e, 'agenttypes_id' => 1, 'last_contact' => date('Y-m-d H:i:s', $now - $days * 86400)]);
}

// ---------------------------------------------------------------- birim ayarı ve kurallar (yalnız demo biriminde)
EntityConfig::save($e, ['groups_id_quality' => $gDQ, 'ticket_mode' => 'grouped', 'due_days' => 10]);
$mk = static function (string $tpl, string $code, string $name, array $in, int $sev, int $w) use ($e): int {
    $id = Rule::create($tpl, $code, $name, 'Computer', $e, true);
    Rule::publish($id, RuleTemplates::build($tpl, 'Computer', $in), $sev, $w, 'demo');
    Rule::setActive($id, true);
    return $id;
};
$active = [$st['Kullanımda']];
$r = [];
$r[] = $mk('required', 'DEMO-01', 'Konum zorunlu', ['field' => 'core:locations_id', 'approval' => 'one', 'appr1_group' => $gAppr, 'appr1_mode' => 'any'], 2, 3);
$r[] = $mk('required', 'DEMO-01B', 'Seri numarası zorunlu', ['field' => 'core:serial'], 2, 2);
$r[] = $mk('responsible', 'DEMO-02', 'Aktif varlığın sorumlusu olmalı', ['scope_states' => $active], 3, 3);
$r[] = $mk('inactive_user', 'DEMO-03', 'Kullanıcı aktif olmalı', ['field' => 'core:users_id'], 3, 2);
$r[] = $mk('conditional', 'DEMO-05', 'Kullanımdaysa konum dolu olmalı', ['field' => 'core:locations_id', 'pre_field' => 'core:states_id', 'pre_op' => 'in', 'pre_values' => $active], 2, 2);
$r[] = $mk('freshness', 'DEMO-07', 'Ajan son 30 günde görülmeli', ['field' => 'agent:last_contact', 'days' => 30, 'scope_states' => $active], 2, 1);
$r[] = $mk('attestation', 'DEMO-08', 'Kullanıcı ve konum 180 günde bir teyit edilmeli', ['attest_fields' => ['core:users_id', 'core:locations_id'], 'days' => 180, 'scope_states' => $active], 1, 1);

// ---------------------------------------------------------------- tarama
AuditLog::as('cron:iqscan', static function (): void {
    JobQueue::process(60);
    $DB = $GLOBALS['DB'];
    $sid = ScanRunner::start('full', null, 'demo');
    while (ScanRunner::tick(60)['slices'] > 0) {
    }
    JobQueue::process(120);
});
echo json_encode(['entity' => $e, 'computers' => count($c), 'rules' => count($r)]) . "\n";
