<?php

namespace GlpiPlugin\Inventoryquality;

use CommonGLPI;

/**
 * Menü: Araçlar → Envanter Veri Kalitesi (Genel Bakış, Bulgular, Kurallar, İşler, Ayarlar).
 * canView()/canCreate() çekirdek CommonGLPI imzasıyla uyumlu `: bool` dönüş tipi taşır.
 */
class Menu extends CommonGLPI
{
    public static $rightname = Rights::NAME;

    public static function getTypeName($nb = 0)
    {
        return __('Envanter Veri Kalitesi', 'inventoryquality');
    }

    public static function getMenuName()
    {
        return self::getTypeName();
    }

    public static function getIcon()
    {
        return 'ti ti-checklist';
    }

    public static function canView(): bool
    {
        return Rights::has(Rights::VIEW);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function base(): string
    {
        global $CFG_GLPI;
        return ($CFG_GLPI['root_doc'] ?? '') . '/plugins/inventoryquality/front';
    }

    /** @return array<string,mixed>|false */
    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }
        $b = self::base();
        $menu = [
            'title' => self::getMenuName(),
            'page'  => $b . '/index.php',
            'icon'  => self::getIcon(),
            'links' => [
                'search' => $b . '/finding.php',
            ],
            'options' => [
                'finding' => ['title' => __('Bulgular', 'inventoryquality'), 'page' => $b . '/finding.php', 'icon' => 'ti ti-alert-triangle'],
                'correction' => ['title' => __('Düzeltmeler', 'inventoryquality'), 'page' => $b . '/correction.php', 'icon' => 'ti ti-pencil-check'],
                'exception'  => ['title' => __('İstisnalar', 'inventoryquality'), 'page' => $b . '/exception.php', 'icon' => 'ti ti-clock-pause'],
                'rule'    => ['title' => __('Kurallar', 'inventoryquality'), 'page' => $b . '/rule.php', 'icon' => 'ti ti-list-check'],
                'jobs'    => ['title' => __('İşler', 'inventoryquality'), 'page' => $b . '/jobs.php', 'icon' => 'ti ti-clock-play'],
            ],
        ];
        if (Rights::has(Rights::RULES)) {
            $menu['options']['rule']['links']['add'] = $b . '/rule.php?new=1';
        }
        return $menu;
    }
}
