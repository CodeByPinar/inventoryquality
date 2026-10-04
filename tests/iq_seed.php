<?php
// Ortak test verisi (AYRI test veritabanı): "IQT " önekli birimler, kullanıcılar, gruplar, durumlar, bilgisayarlar.

use GlpiPlugin\Inventoryquality\AuditLog;
use GlpiPlugin\Inventoryquality\EntityConfig;
use GlpiPlugin\Inventoryquality\Install;
use GlpiPlugin\Inventoryquality\JobQueue;

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
    $ents = array_column(iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_entities', 'WHERE' => ['name' => ['LIKE', 'IQT%']]]), false), 'id');
    foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_computers', 'WHERE' => ['OR' => array_filter([
        ['name' => ['LIKE', '=HYPERLINK%']], ['name' => ''], $ents ? ['entities_id' => $ents] : null,
    ])]]) as $r) {
        (new Computer())->delete(['id' => $r['id']], true);
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

/** @return array<string,int> */
function iq_seed(): array
{
    $s = [];
    $s['eA'] = (new Entity())->add(['name' => 'IQT Birim A', 'entities_id' => 0]);
    $s['eB'] = (new Entity())->add(['name' => 'IQT Birim B', 'entities_id' => 0]);
    $s['stUse'] = (new State())->add(['name' => 'IQT Kullanımda', 'entities_id' => 0, 'is_recursive' => 1]);
    $s['stStore'] = (new State())->add(['name' => 'IQT Depoda', 'entities_id' => 0, 'is_recursive' => 1]);
    $s['loc'] = (new Location())->add(['name' => 'IQT Depo', 'entities_id' => 0, 'is_recursive' => 1]);
    $s['tech'] = (new User())->add(['name' => 'iqt_tech', 'realname' => 'Teknisyen', 'firstname' => 'Test', 'is_active' => 1, '_profiles_id' => 6, '_entities_id' => $s['eA'], '_is_recursive' => 1]);
    $s['old'] = (new User())->add(['name' => 'iqt_old', 'is_active' => 1, '_profiles_id' => 6, '_entities_id' => $s['eA'], '_is_recursive' => 1]);
    $s['ub'] = (new User())->add(['name' => 'iqt_b', 'is_active' => 1, '_profiles_id' => 4, '_entities_id' => $s['eB'], '_is_recursive' => 1]);
    (new User())->update(['id' => $s['old'], 'is_active' => 0]);
    $s['gDQ'] = (new Group())->add(['name' => 'IQT Veri Kalitesi', 'entities_id' => $s['eA'], 'is_recursive' => 1, 'is_assign' => 1]);
    $s['gNet'] = (new Group())->add(['name' => 'IQT Ağ Ekibi', 'entities_id' => $s['eA'], 'is_recursive' => 1, 'is_assign' => 1]);
    $s['c42'] = (new Computer())->add(['name' => 'IQT LT-042', 'entities_id' => $s['eA'], 'states_id' => $s['stUse'], 'users_id' => $s['old'], 'locations_id' => 0]);
    $s['c43'] = (new Computer())->add(['name' => 'IQT LT-043', 'entities_id' => $s['eA'], 'states_id' => $s['stUse'], 'users_id' => $s['tech'], 'locations_id' => $s['loc'], '_groups_id_tech' => [$s['gNet']]]);
    $s['c44'] = (new Computer())->add(['name' => 'IQT LT-044', 'entities_id' => $s['eA'], 'states_id' => $s['stStore'], 'locations_id' => 0]);
    $s['cB'] = (new Computer())->add(['name' => 'IQT LT-B01', 'entities_id' => $s['eB'], 'states_id' => $s['stUse'], 'locations_id' => 0]);
    return $s;
}

function queue(): array
{
    return AuditLog::as('cron:iqqueue', static fn() => JobQueue::process(60));
}

/** Sayfa çalıştırıcı (ayrı süreç). @return array{code:int,body:string,redirect:string,msg:array,error:string} */
function page(string $rel, string $method = 'GET', array $get = [], array $post = [], int $uid = 2): array
{
    $in = tempnam('/tmp', 'iqp');
    file_put_contents($in, json_encode(['rel' => $rel, 'method' => $method, 'get' => $get, 'post' => $post, 'uid' => $uid]));
    $out = [];
    exec('php ' . escapeshellarg(__DIR__ . '/iq_page.php') . ' ' . escapeshellarg($in) . ' 2>&1', $out);
    @unlink($in);
    $raw = implode("\n", $out);
    $r = ['code' => 0, 'body' => $raw, 'redirect' => '', 'msg' => [], 'error' => ''];
    if (preg_match('/@@CODE=(\d+)@@/', $raw, $m)) {
        $r['code'] = (int) $m[1];
    }
    if (preg_match('/@@REDIRECT=(.*?)@@/', $raw, $m)) {
        $r['redirect'] = $m[1];
    }
    if (preg_match('/@@MSG=(.*?)@@/s', $raw, $m)) {
        $r['msg'] = json_decode($m[1], true) ?: [];
    }
    if (preg_match('/@@ERROR=(.*?)@@/s', $raw, $m)) {
        $r['error'] = $m[1];
    }
    return $r;
}

function msgs(array $p): string
{
    $o = [];
    foreach ($p['msg'] as $list) {
        foreach ((array) $list as $m) {
            $o[] = strip_tags(html_entity_decode((string) $m));
        }
    }
    return implode(' | ', $o);
}
