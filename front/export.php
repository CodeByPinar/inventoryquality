<?php

/**
 * Bulgular — güvenli CSV dışa aktarma ("Rapor dışa aktar" yetkisi; kurum birimi kısıtlı; formül hücreleri korumalı).
 */

use GlpiPlugin\Inventoryquality\AuditLog;
use GlpiPlugin\Inventoryquality\Export;
use GlpiPlugin\Inventoryquality\Finding;
use GlpiPlugin\Inventoryquality\Rights;

include('../../../inc/includes.php');

Session::checkRight(Rights::NAME, Rights::EXPORT);

$scope = (string) ($_GET['scope'] ?? 'active');
$statuses = $scope === 'all' ? [] : Finding::ACTIVE;
$csv = Export::findingsCsv($statuses);
AuditLog::write('export_csv', Finding::class, 0, (int) ($_SESSION['glpiactive_entity'] ?? 0), null, ['scope' => $scope, 'bytes' => strlen($csv)]);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="veri-kalitesi-bulgular-' . date('Ymd-His') . '.csv"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $csv;
