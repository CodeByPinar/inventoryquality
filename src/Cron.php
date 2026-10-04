<?php

namespace GlpiPlugin\Inventoryquality;

use CommonGLPI;
use CronTask;

/**
 * Otomatik işlemler (GLPI CronTask). Önerilen başlangıç: olay kuyruğu 5 dakikada bir, tam tarama gece bir kez.
 * Kullanıcı oturumuna bağlı olmamaları için "harici" (sunucu cron'u) modda kaydedilir:
 *   * * * * * www-data /usr/bin/php /var/www/glpi/front/cron.php
 * Eklenti devre dışıyken GLPI bu görevleri çalıştırmaz; yeniden kurulum kopya görev oluşturmaz.
 */
class Cron extends CommonGLPI
{
    public const QUEUE  = 'iqqueue';
    public const SCAN   = 'iqscan';
    public const EXPIRE = 'iqexpire';

    public static function getTypeName($nb = 0)
    {
        return __('Envanter Veri Kalitesi', 'inventoryquality');
    }

    /** @return array<string,string> */
    public static function cronInfo($name): array
    {
        return match ($name) {
            self::QUEUE => ['description' => __('Veri kalitesi: olay kuyruğu, destek kaydı eşitleme ve süren taramalar', 'inventoryquality')],
            self::SCAN  => ['description' => __('Veri kalitesi: tam tarama', 'inventoryquality')],
            self::EXPIRE => ['description' => __('Veri kalitesi: süresi dolan istisnalar ve onaylayan denetimi', 'inventoryquality')],
            default     => [],
        };
    }

    public static function cronIqexpire(CronTask $task): int
    {
        return (int) AuditLog::as('cron:' . self::EXPIRE, static function () use ($task): int {
            $e = ExceptionService::expireDue();
            $r = CorrectionService::checkApprovers();
            $task->addVolume($e + $r);
            if ($e + $r > 0) {
                $task->log(sprintf('%d istisnanın süresi doldu (yeniden kontrol edildi); %d düzeltme incelemeye alındı.', $e, $r));
            }
            return ($e + $r) > 0 ? 1 : 0;
        });
    }

    public static function cronIqqueue(CronTask $task): int
    {
        return (int) AuditLog::as('cron:' . self::QUEUE, static function () use ($task): int {
            $budget = Config::get('queue_budget_sec');
            $start = time();
            $q = JobQueue::process($budget);
            $s = ScanRunner::tick(max(10, Config::get('scan_budget_sec') - (time() - $start)));
            JobQueue::purgeDone();
            $task->addVolume($q['done'] + $s['slices']);
            if ($q['failed'] > 0) {
                $task->log(sprintf('%d iş başarısız (yeniden denenecek).', $q['failed']));
            }
            return ($q['done'] + $q['failed'] + $s['slices']) > 0 ? 1 : 0;
        });
    }

    public static function cronIqscan(CronTask $task): int
    {
        return (int) AuditLog::as('cron:' . self::SCAN, static function () use ($task): int {
            $id = ScanRunner::start('full', null, 'cron:' . self::SCAN);
            $s = ScanRunner::tick(Config::get('scan_budget_sec'));
            $task->log(sprintf('Tarama #%d: %d parti işlendi%s.', $id, $s['slices'], $s['completed'] ? ', tamamlandı' : ' (kalan kısım olay kuyruğu görevinde sürer)'));
            $task->addVolume($s['slices']);
            return 1;
        });
    }

    public static function register(): void
    {
        CronTask::register(self::class, self::QUEUE, 5 * MINUTE_TIMESTAMP, [
            'mode'    => CronTask::MODE_EXTERNAL,
            'state'   => CronTask::STATE_WAITING,
            'comment' => __('Değişen kayıtları yeniden kontrol eder, destek kayıtlarını eşitler, süren taramaları ilerletir.', 'inventoryquality'),
        ]);
        CronTask::register(self::class, self::SCAN, DAY_TIMESTAMP, [
            'mode'    => CronTask::MODE_EXTERNAL,
            'state'   => CronTask::STATE_WAITING,
            'hourmin' => 1,
            'hourmax' => 5,
            'comment' => __('Tüm etkin kuralları kapsamdaki tüm kayıtlarda çalıştırır (kaçırılan olayları da yakalar).', 'inventoryquality'),
        ]);
        CronTask::register(self::class, self::EXPIRE, HOUR_TIMESTAMP, [
            'mode'    => CronTask::MODE_EXTERNAL,
            'state'   => CronTask::STATE_WAITING,
            'comment' => __('Süresi dolan istisnaları yeniden kontrol eder; onaylayanı pasifleşen düzeltmeleri incelemeye alır.', 'inventoryquality'),
        ]);
    }

    /** @return array<string,array<string,mixed>|null> */
    public static function info(): array
    {
        $out = [];
        foreach ([self::QUEUE, self::SCAN, self::EXPIRE] as $n) {
            $t = new CronTask();
            $out[$n] = $t->getFromDBbyName(self::class, $n) ? $t->fields : null;
        }
        return $out;
    }
}
