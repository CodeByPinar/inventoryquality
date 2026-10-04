<?php

namespace GlpiPlugin\Inventoryquality;

use CronTask;
use DBConnection;

/**
 * Şema ve kurulum. Tablo öneki glpi_plugin_inventoryquality_. Kalite bilgisi GLPI çekirdek tablolarına
 * eklenmez; varlık bağlantısı daima itemtype + items_id + entities_id ile tutulur.
 */
final class Install
{
    public const P = 'glpi_plugin_inventoryquality_';

    /** @return array<string,string> tablo adı → CREATE gövdesi */
    public static function tables(): array
    {
        $s = DBConnection::getDefaultPrimaryKeySignOption();
        $P = self::P;
        return [
            // Kararlı kural kimliği; yayındaki sürüm ruleversions_id. Tanım (kapsam, koşul, politika) sürümdedir.
            $P . 'rules' => "(
                `id` INT {$s} NOT NULL AUTO_INCREMENT,
                `code` VARCHAR(32) NOT NULL,
                `name` VARCHAR(255) NOT NULL DEFAULT '',
                `entities_id` INT {$s} NOT NULL DEFAULT 0,
                `is_recursive` TINYINT NOT NULL DEFAULT 1,
                `itemtype` VARCHAR(100) NOT NULL DEFAULT 'Computer',
                `template` VARCHAR(32) NOT NULL DEFAULT '',
                `ruleversions_id` INT {$s} NOT NULL DEFAULT 0,
                `is_active` TINYINT NOT NULL DEFAULT 0,
                `suspended_reason` VARCHAR(255) NOT NULL DEFAULT '',
                `comment` TEXT,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `code` (`code`),
                KEY `entities_id` (`entities_id`),
                KEY `itemtype_active` (`itemtype`, `is_active`)
            )",
            $P . 'ruleversions' => "(
                `id` INT {$s} NOT NULL AUTO_INCREMENT,
                `rules_id` INT {$s} NOT NULL DEFAULT 0,
                `version` INT {$s} NOT NULL DEFAULT 1,
                `definition` LONGTEXT,
                `severity` TINYINT NOT NULL DEFAULT 2,
                `weight` INT {$s} NOT NULL DEFAULT 1,
                `users_id` INT {$s} NOT NULL DEFAULT 0,
                `comment` TEXT,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `rule_version` (`rules_id`, `version`)
            )",
            // Alan kataloğu: varlık tipi + adaptör + kararlı alan anahtarı; etiket değişse de anahtar korunur.
            $P . 'fieldmaps' => "(
                `id` INT {$s} NOT NULL AUTO_INCREMENT,
                `itemtype` VARCHAR(100) NOT NULL DEFAULT '',
                `adapter` VARCHAR(32) NOT NULL DEFAULT 'core',
                `field_key` VARCHAR(100) NOT NULL DEFAULT '',
                `datatype` VARCHAR(16) NOT NULL DEFAULT 'string',
                `ref_table` VARCHAR(100) NOT NULL DEFAULT '',
                `label` VARCHAR(255) NOT NULL DEFAULT '',
                `capabilities` VARCHAR(8) NOT NULL DEFAULT 'r',
                `operations` TEXT,
                `glpi_version` VARCHAR(32) NOT NULL DEFAULT '',
                `is_valid` TINYINT NOT NULL DEFAULT 1,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `field` (`itemtype`, `adapter`, `field_key`)
            )",
            $P . 'scanruns' => "(
                `id` INT {$s} NOT NULL AUTO_INCREMENT,
                `kind` VARCHAR(16) NOT NULL DEFAULT 'full',
                `status` VARCHAR(16) NOT NULL DEFAULT 'running',
                `rules` TEXT,
                `cursor_itemtype` VARCHAR(100) NOT NULL DEFAULT '',
                `cursor_id` INT {$s} NOT NULL DEFAULT 0,
                `items_seen` INT {$s} NOT NULL DEFAULT 0,
                `n_pass` INT {$s} NOT NULL DEFAULT 0,
                `n_fail` INT {$s} NOT NULL DEFAULT 0,
                `n_unknown` INT {$s} NOT NULL DEFAULT 0,
                `n_na` INT {$s} NOT NULL DEFAULT 0,
                `n_errors` INT {$s} NOT NULL DEFAULT 0,
                `message` TEXT,
                `actor` VARCHAR(64) NOT NULL DEFAULT '',
                `users_id` INT {$s} NOT NULL DEFAULT 0,
                `locked_by` VARCHAR(40) NOT NULL DEFAULT '',
                `locked_until` TIMESTAMP NULL DEFAULT NULL,
                `started_at` TIMESTAMP NULL DEFAULT NULL,
                `finished_at` TIMESTAMP NULL DEFAULT NULL,
                `heartbeat_at` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `status` (`status`),
                KEY `started_at` (`started_at`)
            )",
            // Güncel değerlendirme: varlık × kural başına TEK satır (son sonuç). Puan ve kapsam buradan hesaplanır.
            $P . 'evaluations' => "(
                `id` INT {$s} NOT NULL AUTO_INCREMENT,
                `itemtype` VARCHAR(100) NOT NULL DEFAULT '',
                `items_id` INT {$s} NOT NULL DEFAULT 0,
                `entities_id` INT {$s} NOT NULL DEFAULT 0,
                `rules_id` INT {$s} NOT NULL DEFAULT 0,
                `ruleversions_id` INT {$s} NOT NULL DEFAULT 0,
                `result` VARCHAR(16) NOT NULL DEFAULT '',
                `weight` INT {$s} NOT NULL DEFAULT 1,
                `detail` TEXT,
                `error_code` VARCHAR(64) NOT NULL DEFAULT '',
                `scanruns_id` INT {$s} NOT NULL DEFAULT 0,
                `evaluated_at` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `item_rule` (`itemtype`, `items_id`, `rules_id`),
                KEY `entities_id` (`entities_id`),
                KEY `rule_result` (`rules_id`, `result`),
                KEY `scanruns_id` (`scanruns_id`)
            )",
            // Bulgu: kararlı anahtar (entity + tip + varlık + kural + hedef) başına tek güncel kayıt.
            $P . 'findings' => "(
                `id` INT {$s} NOT NULL AUTO_INCREMENT,
                `finding_key` CHAR(64) NOT NULL,
                `entities_id` INT {$s} NOT NULL DEFAULT 0,
                `itemtype` VARCHAR(100) NOT NULL DEFAULT '',
                `items_id` INT {$s} NOT NULL DEFAULT 0,
                `rules_id` INT {$s} NOT NULL DEFAULT 0,
                `target` VARCHAR(100) NOT NULL DEFAULT '',
                `status` VARCHAR(32) NOT NULL DEFAULT 'open',
                `severity` TINYINT NOT NULL DEFAULT 2,
                `cycle` INT {$s} NOT NULL DEFAULT 1,
                `first_seen` TIMESTAMP NULL DEFAULT NULL,
                `last_seen` TIMESTAMP NULL DEFAULT NULL,
                `seen_count` INT {$s} NOT NULL DEFAULT 1,
                `last_result` VARCHAR(16) NOT NULL DEFAULT '',
                `last_check` TIMESTAMP NULL DEFAULT NULL,
                `ruleversions_id` INT {$s} NOT NULL DEFAULT 0,
                `detail` TEXT,
                `users_id_assign` INT {$s} NOT NULL DEFAULT 0,
                `groups_id_assign` INT {$s} NOT NULL DEFAULT 0,
                `assign_state` VARCHAR(16) NOT NULL DEFAULT 'waiting',
                `assign_reason` VARCHAR(32) NOT NULL DEFAULT '',
                `due_date` TIMESTAMP NULL DEFAULT NULL,
                `status_reason` VARCHAR(255) NOT NULL DEFAULT '',
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `finding_key` (`finding_key`),
                KEY `entity_status` (`entities_id`, `status`),
                KEY `item` (`itemtype`, `items_id`),
                KEY `status_due` (`status`, `due_date`),
                KEY `rules_id` (`rules_id`)
            )",
            // Her açılma–kapanma dönemi (tekrar açılmada yeni dönem; eski dönem korunur).
            $P . 'cycles' => "(
                `id` INT {$s} NOT NULL AUTO_INCREMENT,
                `findings_id` INT {$s} NOT NULL DEFAULT 0,
                `cycle` INT {$s} NOT NULL DEFAULT 1,
                `opened_at` TIMESTAMP NULL DEFAULT NULL,
                `closed_at` TIMESTAMP NULL DEFAULT NULL,
                `close_status` VARCHAR(32) NOT NULL DEFAULT '',
                `close_reason` VARCHAR(255) NOT NULL DEFAULT '',
                `opened_ruleversions_id` INT {$s} NOT NULL DEFAULT 0,
                `closed_ruleversions_id` INT {$s} NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `finding_cycle` (`findings_id`, `cycle`)
            )",
            $P . 'ticketlinks' => "(
                `id` INT {$s} NOT NULL AUTO_INCREMENT,
                `findings_id` INT {$s} NOT NULL DEFAULT 0,
                `tickets_id` INT {$s} NOT NULL DEFAULT 0,
                `cycle` INT {$s} NOT NULL DEFAULT 1,
                `group_key` CHAR(64) NOT NULL DEFAULT '',
                `is_open` TINYINT NOT NULL DEFAULT 1,
                `close_note` VARCHAR(255) NOT NULL DEFAULT '',
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_closed` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `link` (`findings_id`, `tickets_id`, `cycle`),
                KEY `tickets_id` (`tickets_id`),
                KEY `group_open` (`group_key`, `is_open`)
            )",
            // Dayanıklı iş kuyruğu: tekil anahtar, kilit, deneme sayısı, sonraki deneme.
            $P . 'jobs' => "(
                `id` INT {$s} NOT NULL AUTO_INCREMENT,
                `job_key` VARCHAR(190) NOT NULL,
                `kind` VARCHAR(32) NOT NULL DEFAULT '',
                `payload` TEXT,
                `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
                `dirty` TINYINT NOT NULL DEFAULT 0,
                `attempts` INT {$s} NOT NULL DEFAULT 0,
                `next_run_at` TIMESTAMP NULL DEFAULT NULL,
                `locked_by` VARCHAR(40) NOT NULL DEFAULT '',
                `locked_at` TIMESTAMP NULL DEFAULT NULL,
                `last_error` TEXT,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `job_key` (`job_key`),
                KEY `status_next` (`status`, `next_run_at`)
            )",
            // Denetim izi: işlemi yapan kullanıcı ile servis kimliği ayrı tutulur; arayüzden düzenlenemez.
            $P . 'auditlogs' => "(
                `id` INT {$s} NOT NULL AUTO_INCREMENT,
                `users_id` INT {$s} NOT NULL DEFAULT 0,
                `actor` VARCHAR(64) NOT NULL DEFAULT '',
                `itemtype` VARCHAR(100) NOT NULL DEFAULT '',
                `items_id` INT {$s} NOT NULL DEFAULT 0,
                `entities_id` INT {$s} NOT NULL DEFAULT 0,
                `action` VARCHAR(64) NOT NULL DEFAULT '',
                `before` TEXT,
                `after` TEXT,
                `reason` TEXT,
                `date` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `object` (`itemtype`, `items_id`),
                KEY `date` (`date`)
            )",
            // 0.2.0 — Düzeltme önerisi: hedef alan(lar), anlık görüntü, politika sürümü, talep sahibi, tekil işlem anahtarı.
            $P . 'corrections' => "(
                `id` INT {$s} NOT NULL AUTO_INCREMENT,
                `op_key` CHAR(64) NOT NULL,
                `findings_id` INT {$s} NOT NULL DEFAULT 0,
                `entities_id` INT {$s} NOT NULL DEFAULT 0,
                `itemtype` VARCHAR(100) NOT NULL DEFAULT '',
                `items_id` INT {$s} NOT NULL DEFAULT 0,
                `rules_id` INT {$s} NOT NULL DEFAULT 0,
                `ruleversions_id` INT {$s} NOT NULL DEFAULT 0,
                `changes` TEXT,
                `snapshot` TEXT,
                `policy` TEXT,
                `reason` TEXT,
                `evidence_file` VARCHAR(64) NOT NULL DEFAULT '',
                `evidence_name` VARCHAR(255) NOT NULL DEFAULT '',
                `evidence_mime` VARCHAR(100) NOT NULL DEFAULT '',
                `evidence_size` INT {$s} NOT NULL DEFAULT 0,
                `status` VARCHAR(32) NOT NULL DEFAULT 'pending_approval',
                `result_message` TEXT,
                `attempts` INT {$s} NOT NULL DEFAULT 0,
                `users_id` INT {$s} NOT NULL DEFAULT 0,
                `users_id_applier` INT {$s} NOT NULL DEFAULT 0,
                `applied_at` TIMESTAMP NULL DEFAULT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `op_key` (`op_key`),
                KEY `findings_id` (`findings_id`),
                KEY `entity_status` (`entities_id`, `status`),
                KEY `item` (`itemtype`, `items_id`)
            )",
            // Onay adımları: adım (1/2), hedef (kişi / grup), karar türü (any = gruptan biri, all = belirlenmiş herkes).
            $P . 'approvals' => "(
                `id` INT {$s} NOT NULL AUTO_INCREMENT,
                `corrections_id` INT {$s} NOT NULL DEFAULT 0,
                `step` TINYINT NOT NULL DEFAULT 1,
                `target_type` VARCHAR(16) NOT NULL DEFAULT 'User',
                `target_id` INT {$s} NOT NULL DEFAULT 0,
                `mode` VARCHAR(8) NOT NULL DEFAULT 'any',
                `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
                `users_id` INT {$s} NOT NULL DEFAULT 0,
                `comment` TEXT,
                `decision_date` TIMESTAMP NULL DEFAULT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `correction_step` (`corrections_id`, `step`),
                KEY `target` (`target_type`, `target_id`, `status`)
            )",
            // Süreli istisna: gerekçe, onaylayan, kapsam, bitiş, telafi edici işlem. Altındaki FAIL'i PASS yapmaz.
            $P . 'exceptions' => "(
                `id` INT {$s} NOT NULL AUTO_INCREMENT,
                `findings_id` INT {$s} NOT NULL DEFAULT 0,
                `entities_id` INT {$s} NOT NULL DEFAULT 0,
                `scope` VARCHAR(16) NOT NULL DEFAULT 'finding',
                `reason` TEXT,
                `compensating` TEXT,
                `end_date` TIMESTAMP NULL DEFAULT NULL,
                `status` VARCHAR(16) NOT NULL DEFAULT 'active',
                `users_id` INT {$s} NOT NULL DEFAULT 0,
                `users_id_end` INT {$s} NOT NULL DEFAULT 0,
                `end_reason` VARCHAR(255) NOT NULL DEFAULT '',
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_end` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `findings_id` (`findings_id`),
                KEY `status_end` (`status`, `end_date`),
                KEY `entities_id` (`entities_id`)
            )",
            // İş sahibi teyidi: kim, hangi alanları, hangi değerlerle, ne zaman teyit etti (otomatik envanterden ayrı).
            $P . 'attestations' => "(
                `id` INT {$s} NOT NULL AUTO_INCREMENT,
                `itemtype` VARCHAR(100) NOT NULL DEFAULT '',
                `items_id` INT {$s} NOT NULL DEFAULT 0,
                `entities_id` INT {$s} NOT NULL DEFAULT 0,
                `fields` TEXT,
                `field_values` TEXT,
                `comment` TEXT,
                `users_id` INT {$s} NOT NULL DEFAULT 0,
                `date` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `item_date` (`itemtype`, `items_id`, `date`)
            )",
            // Kurum birimi ayarları (alt birim, en yakın üst birimin ayarını devralır).
            $P . 'entityconfigs' => "(
                `id` INT {$s} NOT NULL AUTO_INCREMENT,
                `entities_id` INT {$s} NOT NULL DEFAULT 0,
                `groups_id_quality` INT {$s} NOT NULL DEFAULT 0,
                `ticket_mode` VARCHAR(16) NOT NULL DEFAULT 'off',
                `users_id_requester` INT {$s} NOT NULL DEFAULT 0,
                `due_days` INT {$s} NOT NULL DEFAULT 14,
                `closed_ticket_policy` VARCHAR(16) NOT NULL DEFAULT 'new_ticket',
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `entities_id` (`entities_id`)
            )",
        ];
    }

    public static function install(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        foreach (self::tables() as $name => $def) {
            if (!$DB->tableExists($name)) {
                $DB->doQuery("CREATE TABLE `{$name}` {$def} ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
            }
        }
        Config::installDefaults();
        Profile::install();
        Cron::register();
        Catalog::sync();
        self::defaultDisplayPreferences();
        self::clearTemplateCache();
    }

    /**
     * Listelerin varsayılan sütunları (GLPI yeni bir tip için yalnız kimlik + birim gösterir). Yalnız hiç genel tercih
     * yoksa eklenir; yöneticinin düzenlediği sütunlara dokunulmaz.
     */
    public static function defaultDisplayPreferences(): void
    {
        global $DB;
        $defaults = [
            Finding::class => [2, 4, 5, 6, 7, 9, 11, 12],   // varlık, kural kodu, kural, durum, önem, sorumlu grup, hedef tarih, son kontrol
            Rule::class    => [3, 5, 4, 6, 7, 19],          // kod, şablon, varlık tipi, etkin, askı nedeni, son değişiklik
        ];
        foreach ($defaults as $itemtype => $nums) {
            if (countElementsInTable('glpi_displaypreferences', ['itemtype' => $itemtype, 'users_id' => 0]) > 0) {
                continue;
            }
            foreach ($nums as $rank => $num) {
                $DB->insert('glpi_displaypreferences', ['itemtype' => $itemtype, 'num' => $num, 'rank' => $rank + 1, 'users_id' => 0, 'interface' => 'central']);
            }
        }
    }

    /**
     * GLPI üretim modunda derlenmiş Twig şablonlarını yeniden yüklemez ve eklenti güncellemesinde temizlemez;
     * güncellemeden sonra eski ekranlar görünmesin diye derlenmiş şablonlar silinir (`cache:clear` ile aynı dosyalar;
     * GLPI gerektiğinde yeniden derler).
     */
    public static function clearTemplateCache(): void
    {
        try {
            $dir = \Glpi\Kernel\Kernel::getCacheRootDir() . '/templates';
            if (!is_dir($dir)) {
                return;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    @unlink($file->getPathname());
                }
            }
        } catch (\Throwable $e) {
            // Yazma izni yoksa sessiz geç: README'deki "cache:clear" adımı yine geçerli.
        }
    }

    public static function uninstall(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        foreach (array_keys(self::tables()) as $name) {
            if ($DB->tableExists($name)) {
                $DB->doQuery("DROP TABLE `{$name}`");
            }
        }
        Config::uninstall();
        Profile::uninstall();
        Evidence::purgeAll();
        $DB->delete('glpi_displaypreferences', ['itemtype' => [Finding::class, Rule::class]]);
        CronTask::unregister('inventoryquality');
    }
}
