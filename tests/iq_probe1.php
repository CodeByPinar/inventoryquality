<?php
// Aşama 1 — teknik doğrulama yoklaması (yalnız AYRI test veritabanında; yazdığı kayıtları sonda siler).
require __DIR__ . '/iq_boot.php';
global $DB, $CFG_GLPI;
iq_login(2);

echo "== Sürümler\n";
echo 'GLPI ' . GLPI_VERSION . ' · PHP ' . PHP_VERSION . ' · DB ' . $DB->doQuery('SELECT VERSION() v')->fetch_assoc()['v'] . PHP_EOL;

echo "== glpi_computers kolonları\n";
echo implode(', ', array_keys($DB->listFields('glpi_computers'))) . PHP_EOL;

echo "== Grup ilişkileri\n";
echo 'Group_Item: ' . (class_exists('Group_Item') ? 'var' : 'yok') . PHP_EOL;
if ($DB->tableExists('glpi_groups_items')) {
    echo 'glpi_groups_items: ' . implode(', ', array_keys($DB->listFields('glpi_groups_items'))) . PHP_EOL;
}
echo 'Computer assignableItem trait: ' . (in_array('Glpi\\Features\\AssignableItem', class_uses('Computer') ?: [], true) ? 'evet' : 'hayır') . PHP_EOL;

echo "== Ajan / son görülme\n";
foreach (['glpi_agents'] as $t) {
    if ($DB->tableExists($t)) {
        echo "$t: " . implode(', ', array_keys($DB->listFields($t))) . PHP_EOL;
    }
}

echo "== Durumlar / kullanıcı alanları\n";
echo 'glpi_users: ' . implode(', ', array_intersect(array_keys($DB->listFields('glpi_users')), ['is_active', 'is_deleted', 'begin_date', 'end_date', 'users_id_supervisor', 'entities_id'])) . PHP_EOL;
echo 'glpi_states: ' . implode(', ', array_keys($DB->listFields('glpi_states'))) . PHP_EOL;

echo "== CronTask / Profile API\n";
echo 'CronTask::register: ' . (method_exists('CronTask', 'register') ? (new ReflectionMethod('CronTask', 'register'))->getNumberOfParameters() . ' param' : 'yok') . PHP_EOL;
echo 'ProfileRight::addProfileRights: ' . (method_exists('ProfileRight', 'addProfileRights') ? 'var' : 'yok') . PHP_EOL;

echo "== Bilet + varlık bağlantısı + takip + çözüm (test kaydı, sonda silinir)\n";
$c = new Computer();
$cid = $c->add(['name' => 'IQ-PROBE-1', 'entities_id' => 0]);
$g = new Group();
$gid = $g->add(['name' => 'IQ probe grup', 'entities_id' => 0, 'is_recursive' => 1, 'is_assign' => 1]);
$t = new Ticket();
$tid = $t->add([
    'name' => 'Envanter veri kalitesi - IQ-PROBE-1', 'content' => 'yoklama', 'entities_id' => 0,
    'type' => Ticket::DEMAND_TYPE, '_groups_id_assign' => $gid,
    'items_id' => ['Computer' => [$cid]],
]);
$t->getFromDB($tid);
$links = countElementsInTable('glpi_items_tickets', ['tickets_id' => $tid, 'itemtype' => 'Computer', 'items_id' => $cid]);
$gas = countElementsInTable('glpi_groups_tickets', ['tickets_id' => $tid, 'groups_id' => $gid, 'type' => CommonITILActor::ASSIGN]);
echo "ticket=$tid durum={$t->fields['status']} item_ticket=$links atanmis_grup=$gas" . PHP_EOL;
$f = new ITILFollowup();
$fid = $f->add(['itemtype' => 'Ticket', 'items_id' => $tid, 'content' => 'IQ takip notu', 'is_private' => 0]);
echo "takip=$fid" . PHP_EOL;
$s = new ITILSolution();
$sid = $s->add(['itemtype' => 'Ticket', 'items_id' => $tid, 'content' => 'Doğrulandı: kural sağlandı.']);
$t->getFromDB($tid);
echo "cozum=$sid durum_sonra={$t->fields['status']} (SOLVED=" . CommonITILObject::SOLVED . ")" . PHP_EOL;

// temizlik
$t->delete(['id' => $tid], true);
$c->delete(['id' => $cid], true);
$g->delete(['id' => $gid], true);
echo "temizlendi\n";
