<?php

/**
 * İş sahibi teyidi (varlığın "Veri Kalitesi" sekmesinden). Teyit veriyi değiştirmez; kayıt yeniden kontrol edilir.
 */

use GlpiPlugin\Inventoryquality\AttestationService;
use GlpiPlugin\Inventoryquality\AuditLog;
use GlpiPlugin\Inventoryquality\JobQueue;
use GlpiPlugin\Inventoryquality\ScanRunner;
use GlpiPlugin\Inventoryquality\Ui;

include('../../../inc/includes.php');

$itemtype = (string) ($_POST['itemtype'] ?? '');
$id = (int) ($_POST['items_id'] ?? 0);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !class_exists($itemtype)) {
    throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
}
try {
    AttestationService::attest($itemtype, $id, (array) ($_POST['attest_fields'] ?? []), (string) ($_POST['attest_comment'] ?? ''));
    AuditLog::as('service:recheck', static fn() => ScanRunner::recheckItem($itemtype, $id));
    JobQueue::enqueue('ticket_sync', "ticket_sync:$itemtype:$id", ['itemtype' => $itemtype, 'items_id' => $id]);
    Ui::msg(__('Teyit kaydedildi; kayıt yeniden kontrol edildi.', 'inventoryquality'));
} catch (InvalidArgumentException $e) {
    Ui::msg($e->getMessage(), true);
}
Ui::back($itemtype::getFormURLWithID($id));
