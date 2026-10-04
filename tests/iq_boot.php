<?php
// inventoryquality — AYRI GLPI 11 test kurulumu (~/iq/glpi, veritabanı glpi11iq) için önyükleme.
// Şirket GLPI'ına (/var/www/glpi, veritabanı glpi) dokunmaz.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING & ~E_USER_DEPRECATED);
const IQ_ROOT = '/home/glpi/iq/glpi';
chdir(IQ_ROOT);
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
require IQ_ROOT . '/vendor/autoload.php';
(new \Glpi\Kernel\Kernel('production'))->boot();

global $DB;
if (($DB->dbdefault ?? '') !== 'glpi11iq') {
    fwrite(STDERR, "DURDU: test veritabanı değil ({$DB->dbdefault})\n");
    exit(1);
}

/** Oturumu verilen kullanıcıyla açar (GLPI testlerindeki gibi Auth taklidi). */
function iq_login(int $uid): void
{
    $u = new User();
    if (!$u->getFromDB($uid)) {
        throw new RuntimeException("kullanıcı yok: $uid");
    }
    $auth = new Auth();
    $auth->auth_succeded = true;
    $auth->user = $u;
    Session::init($auth);
}

$IQ_FAIL = 0;
function iq_ok(bool $c, string $m): void
{
    global $IQ_FAIL;
    echo ($c ? '  OK   ' : '  FAIL ') . $m . PHP_EOL;
    if (!$c) {
        $IQ_FAIL++;
    }
}
function iq_done(): void
{
    global $IQ_FAIL;
    echo $IQ_FAIL ? "BAŞARISIZ: $IQ_FAIL\n" : "TÜMÜ GEÇTİ\n";
}
