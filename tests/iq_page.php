<?php
// Eklenti sayfasını ayrı süreçte, oturum açık kullanıcıyla çalıştırır (AYRI test kurulumu).
// Kullanım: php iq_page.php <json>   json: {rel, method, get, post, uid}
// Çıktı: sayfa HTML'i + @@CODE=…@@, @@REDIRECT=…@@, @@MSG=json@@, @@ERROR=…@@ işaretleri.
$in = json_decode((string) file_get_contents($argv[1]), true);
$_SERVER['REQUEST_URI'] = '/plugins/inventoryquality/' . $in['rel'];
require __DIR__ . '/iq_boot.php';
iq_login((int) ($in['uid'] ?? 2));
$_GET = $in['get'] ?? [];
$_POST = $in['post'] ?? [];
$_REQUEST = array_merge($_GET, $_POST);
$_SERVER['REQUEST_METHOD'] = $in['method'] ?? 'GET';
$_SERVER['PHP_SELF'] = '/plugins/inventoryquality/' . $in['rel'];

$file = IQ_ROOT . '/plugins/inventoryquality/' . $in['rel'];
$tmp = dirname($file) . '/_cli_' . getmypid() . '.php';
file_put_contents($tmp, str_replace("include('../../../inc/includes.php');", '', (string) file_get_contents($file)));
chdir(dirname($file));
$code = 200;
$GLOBALS['IQ_DONE'] = false;
register_shutdown_function(static function () use ($tmp): void {
    @unlink($tmp);
    if (!$GLOBALS['IQ_DONE']) {
        $e = error_get_last();
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        echo "\n@@EXIT=" . ($e ? $e['message'] . ' @ ' . basename($e['file']) . ':' . $e['line'] : 'exit() çağrıldı') . "@@\n@@CODE=299@@\n";
        echo '@@MSG=' . json_encode($_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [], JSON_UNESCAPED_UNICODE) . "@@\n";
    }
});
ob_start();
try {
    // GLPI 11 de eski tip sayfaları bir metot kapsamında include eder; değişkenler çalıştırıcıyla çakışmaz.
    (static function (string $__f): void {
        include $__f;
    })($tmp);
} catch (\Glpi\Exception\RedirectException $e) {
    $code = 302;
    echo "\n@@REDIRECT=" . $e->getResponse()->getTargetUrl() . "@@\n";
} catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
    $code = $e->getStatusCode();
} catch (\Throwable $e) {
    $code = 500;
    echo "\n@@ERROR=" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "@@\n";
} finally {
    @unlink($tmp);
}
$html = (string) ob_get_clean();
$GLOBALS['IQ_DONE'] = true;
echo $html;
echo "\n@@CODE=$code@@\n";
echo '@@MSG=' . json_encode($_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [], JSON_UNESCAPED_UNICODE) . "@@\n";
