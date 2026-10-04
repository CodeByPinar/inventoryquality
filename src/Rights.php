<?php

namespace GlpiPlugin\Inventoryquality;

use Session;

/**
 * Profil yetkileri — tek hak alanı (plugin_inventoryquality), işlem başına ayrı bit.
 * Bir işlem yetkisi diğerlerini otomatik vermez. VIEW = GLPI'nin READ biti (CommonDBTM::canView ile uyumlu).
 */
final class Rights
{
    public const NAME = 'plugin_inventoryquality';

    public const VIEW      = 1;    // görüntüle
    public const RULES     = 2;    // kural yönet
    public const SCAN      = 4;    // tarama başlat / yeniden kontrol
    public const ASSIGN    = 8;    // iş ata
    public const PROPOSE   = 16;   // düzeltme öner
    public const APPLY     = 32;   // düzeltme uygula
    public const APPROVE   = 64;   // onayla
    public const EXCEPTION = 128;  // istisna ver
    public const EXPORT    = 256;  // rapor dışa aktar
    public const CONFIG    = 512;  // ayar yönet

    public const ALL = 1023;

    /** @return array<int,string> */
    public static function labels(): array
    {
        return [
            self::VIEW      => __('Görüntüle', 'inventoryquality'),
            self::RULES     => __('Kural yönet', 'inventoryquality'),
            self::SCAN      => __('Tarama başlat / yeniden kontrol', 'inventoryquality'),
            self::ASSIGN    => __('İş ata', 'inventoryquality'),
            self::PROPOSE   => __('Düzeltme öner', 'inventoryquality'),
            self::APPLY     => __('Düzeltme uygula', 'inventoryquality'),
            self::APPROVE   => __('Onayla', 'inventoryquality'),
            self::EXCEPTION => __('İstisna ver', 'inventoryquality'),
            self::EXPORT    => __('Rapor dışa aktar', 'inventoryquality'),
            self::CONFIG    => __('Ayar yönet', 'inventoryquality'),
        ];
    }

    public static function has(int $bit): bool
    {
        return (bool) Session::haveRight(self::NAME, $bit);
    }
}
