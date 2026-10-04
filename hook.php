<?php

/**
 * inventoryquality kurulum / güncelleme / kaldırma.
 *
 * Kurulum ve güncelleme veri korur (tablolar yalnız yoksa oluşturulur, kolonlar sürüm sürüm eklenir).
 * Devre dışı bırakma hiçbir kaydı silmez. KALDIRMA eklentinin TÜM tablolarını ve ayarlarını siler
 * (kurallar, bulgular, geçmiş, denetim kayıtları); bağlantılı GLPI destek kayıtları GLPI'de kalır.
 */

use GlpiPlugin\Inventoryquality\Install;

function plugin_inventoryquality_install(): bool
{
    Install::install();
    return true;
}

function plugin_inventoryquality_uninstall(): bool
{
    Install::uninstall();
    return true;
}
