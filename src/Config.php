<?php

namespace GlpiPlugin\Inventoryquality;

/**
 * Genel ayarlar (glpi_configs, bağlam plugin:inventoryquality).
 */
final class Config
{
    public const CTX = 'plugin:inventoryquality';

    /** @var array<string,int> */
    public const DEFAULTS = [
        'batch_size'       => 200,  // tarama partisi (kimlik tabanlı sayfalama)
        'scan_budget_sec'  => 240,  // bir çalışmada tarama zaman bütçesi
        'queue_budget_sec' => 120,  // olay kuyruğu zaman bütçesi
        'job_max_attempts' => 5,
        'preview_limit'    => 50,   // kural önizlemesinde örnek kayıt sayısı
        'low_coverage_pct' => 80,   // bu kapsamın altında puan "sağlıklı" etiketiyle sunulmaz
    ];

    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public static function get(string $key): int
    {
        if (self::$cache === null) {
            self::$cache = \Config::getConfigurationValues(self::CTX);
        }
        if (isset(self::$cache[$key]) && is_numeric(self::$cache[$key])) {
            return (int) self::$cache[$key];
        }
        return self::DEFAULTS[$key] ?? 0;
    }

    /** @param array<string,int> $values */
    public static function set(array $values): void
    {
        $clean = [];
        foreach ($values as $k => $v) {
            if (array_key_exists($k, self::DEFAULTS)) {
                $clean[$k] = (string) max(1, (int) $v);
            }
        }
        if ($clean) {
            \Config::setConfigurationValues(self::CTX, $clean);
        }
        self::$cache = null;
    }

    public static function installDefaults(): void
    {
        $have = \Config::getConfigurationValues(self::CTX);
        $add = [];
        foreach (self::DEFAULTS as $k => $v) {
            if (!array_key_exists($k, $have)) {
                $add[$k] = (string) $v;
            }
        }
        if ($add) {
            \Config::setConfigurationValues(self::CTX, $add);
        }
        self::$cache = null;
    }

    public static function uninstall(): void
    {
        $cfg = new \Config();
        $cfg->deleteConfigurationValues(self::CTX, array_keys(\Config::getConfigurationValues(self::CTX)));
        self::$cache = null;
    }
}
