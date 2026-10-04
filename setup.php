<?php

/**
 * inventoryquality — Envanter Veri Kalitesi ve Düzeltme Takibi (GLPI 11).
 *
 * Envanter kayıtlarını kurumun tanımladığı kalite kurallarına göre kontrol eder; her sorun için izlenebilir bir
 * bulgu açar, düzeltme işini sorumluya atar ve bulguyu YALNIZ güncel veride kural yeniden sağlandığında çözer.
 *
 * Yazar: Pınar Topuz. Çekirdek dosyalarına dokunmaz; GLPI'nin nesne, yetki, olay (hook) ve otomatik işlem
 * mekanizmalarını kullanır.
 */

use GlpiPlugin\Inventoryquality\AssetAdapter;
use GlpiPlugin\Inventoryquality\AssetTab;
use GlpiPlugin\Inventoryquality\EventHooks;
use GlpiPlugin\Inventoryquality\Menu;
use GlpiPlugin\Inventoryquality\Profile;
use GlpiPlugin\Inventoryquality\Rights;

define('PLUGIN_INVENTORYQUALITY_VERSION', '0.2.1');
define('PLUGIN_INVENTORYQUALITY_MIN_GLPI', '11.0.0');
define('PLUGIN_INVENTORYQUALITY_MAX_GLPI', '11.0.99');

function plugin_init_inventoryquality(): void
{
    /** @var array $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['inventoryquality'] = true;

    if (!(new Plugin())->isActivated('inventoryquality')) {
        return;
    }

    Plugin::registerClass(Profile::class, ['addtabon' => 'Profile']);
    $PLUGIN_HOOKS['item_add']['inventoryquality']['Profile'] = [Profile::class, 'addNewProfile'];

    $types = AssetAdapter::supportedTypes();
    Plugin::registerClass(AssetTab::class, ['addtabon' => $types]);

    // Olaylar: yalnız ilgili kayıt yeniden kontrol kuyruğuna alınır (kaydederken tam tarama yapılmaz).
    foreach (['item_add', 'item_update', 'item_delete', 'item_restore', 'item_purge'] as $hook) {
        foreach ($types as $t) {
            $PLUGIN_HOOKS[$hook]['inventoryquality'][$t] = [EventHooks::class, 'onAsset'];
        }
        $PLUGIN_HOOKS[$hook]['inventoryquality']['Group_Item'] = [EventHooks::class, 'onGroupItem'];
    }
    $PLUGIN_HOOKS['item_update']['inventoryquality']['User'] = [EventHooks::class, 'onUser'];
    $PLUGIN_HOOKS['item_update']['inventoryquality']['Ticket'] = [EventHooks::class, 'onTicket'];
    $PLUGIN_HOOKS['item_delete']['inventoryquality']['Ticket'] = [EventHooks::class, 'onTicket'];
    $PLUGIN_HOOKS['item_purge']['inventoryquality']['Ticket'] = [EventHooks::class, 'onTicket'];

    if (Rights::has(Rights::VIEW)) {
        $PLUGIN_HOOKS['menu_toadd']['inventoryquality'] = ['tools' => Menu::class];
    }
    if (Rights::has(Rights::CONFIG)) {
        $PLUGIN_HOOKS['config_page']['inventoryquality'] = 'front/config.php';
    }
}

/**
 * @return array<string,mixed>
 */
function plugin_version_inventoryquality(): array
{
    return [
        'name'         => 'Envanter Veri Kalitesi',
        'version'      => PLUGIN_INVENTORYQUALITY_VERSION,
        'author'       => 'Pınar Topuz',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_INVENTORYQUALITY_MIN_GLPI,
                'max' => PLUGIN_INVENTORYQUALITY_MAX_GLPI,
            ],
            'php' => ['min' => '8.2'],
        ],
    ];
}

function plugin_inventoryquality_check_prerequisites(): bool
{
    return true;
}

function plugin_inventoryquality_check_config($verbose = false): bool
{
    return true;
}
