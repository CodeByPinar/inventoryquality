<?php
// Gerçek oturumlu tarayıcı testi için KALICI test verisi (yalnız AYRI test kurulumu). Temizlemek için: php iq_browser_seed.php clean
// Onaylayan test hesabı: iqt_ap1 — parola IQ_AP1_PASSWORD ortam değişkeninden okunur (depoda parola tutulmaz).
require __DIR__ . '/iq_boot.php';
require __DIR__ . '/iq_seed.php';

use GlpiPlugin\Inventoryquality\EntityConfig;

define('IQ_BROWSER_AP1_PASSWORD', (string) (getenv('IQ_AP1_PASSWORD') ?: bin2hex(random_bytes(8))));

iq_login(2);
iq_cleanup_glpi();
iq_wipe();
if (($argv[1] ?? '') === 'clean') {
    echo "temizlendi\n";
    exit(0);
}
$s = iq_seed();
$ap1 = (new User())->add(['name' => 'iqt_ap1', 'firstname' => 'Onay', 'realname' => 'Sorumlusu', 'is_active' => 1,
    'password' => IQ_BROWSER_AP1_PASSWORD, 'password2' => IQ_BROWSER_AP1_PASSWORD, '_profiles_id' => 4, '_entities_id' => 0, '_is_recursive' => 1]);
$gA1 = (new Group())->add(['name' => 'IQT Onay 1', 'entities_id' => 0, 'is_recursive' => 1]);
(new Group_User())->add(['groups_id' => $gA1, 'users_id' => $ap1]);
(new Group_User())->add(['groups_id' => $gA1, 'users_id' => 2]);
EntityConfig::save($s['eA'], ['groups_id_quality' => $s['gDQ'], 'ticket_mode' => 'grouped', 'due_days' => 10]);
EntityConfig::save($s['eB'], ['groups_id_quality' => 0, 'ticket_mode' => 'off', 'due_days' => 14]);
echo json_encode($s + ['ap1' => $ap1, 'gA1' => $gA1]) . "\n";
