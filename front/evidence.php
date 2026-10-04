<?php

/**
 * Düzeltme kanıt eki indirme — yalnız düzeltmenin kurum birimine erişimi olan "Görüntüle" yetkili kullanıcı.
 */

use GlpiPlugin\Inventoryquality\CorrectionService;
use GlpiPlugin\Inventoryquality\Evidence;
use GlpiPlugin\Inventoryquality\Rights;

include('../../../inc/includes.php');

Session::checkRight(Rights::NAME, Rights::VIEW);
try {
    $c = CorrectionService::load((int) ($_GET['id'] ?? 0));
} catch (InvalidArgumentException $e) {
    throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
}
Evidence::send($c);
