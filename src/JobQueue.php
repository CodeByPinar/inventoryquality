<?php

namespace GlpiPlugin\Inventoryquality;

/**
 * Dayanıklı iş kuyruğu. Her işin tekil anahtarı vardır (ör. "recheck:Computer:42"): aynı varlığın art arda
 * olayları tek işte birleşir; çalışırken gelen olay işi "kirli" işaretler ve iş bitince yeniden kuyruğa girer.
 * Kilitli (running) iş 30 dakikada bitmezse sahipsiz sayılıp yeniden denenir. Başarısız iş sınırlı sayıda,
 * artan bekleme ile tekrar denenir; bir işin hatası başarılı bir değişikliği tekrar ettirmez.
 */
final class JobQueue
{
    public const TABLE = Install::P . 'jobs';
    public const STALE_SEC = 1800;

    /** @param array<string,mixed> $payload */
    public static function enqueue(string $kind, string $key, array $payload, int $delaySec = 0): void
    {
        global $DB;
        $now = Db::now();
        $next = date('Y-m-d H:i:s', Db::time() + max(0, $delaySec));
        $sql = $DB->buildInsert(self::TABLE, [
            'job_key' => mb_substr($key, 0, 190), 'kind' => $kind, 'payload' => Db::json($payload), 'status' => 'pending',
            'dirty' => 0, 'attempts' => 0, 'next_run_at' => $next, 'date_creation' => $now, 'date_mod' => $now,
        ]);
        // Sıra önemli: status en son güncellenir (önceki atamalar eski status değerini okur).
        $sql .= " ON DUPLICATE KEY UPDATE
            `dirty` = IF(`status` = 'running', 1, `dirty`),
            `attempts` = IF(`status` IN ('done','failed'), 0, `attempts`),
            `payload` = VALUES(`payload`),
            `kind` = VALUES(`kind`),
            `next_run_at` = IF(`status` IN ('done','failed'), VALUES(`next_run_at`), LEAST(COALESCE(`next_run_at`, VALUES(`next_run_at`)), VALUES(`next_run_at`))),
            `last_error` = IF(`status` IN ('done','failed'), NULL, `last_error`),
            `status` = IF(`status` IN ('done','failed'), 'pending', `status`),
            `date_mod` = VALUES(`date_mod`)";
        $DB->doQuery($sql);
    }

    /** @return list<array<string,mixed>> sahiplenilen işler */
    public static function claim(int $limit, string $token): array
    {
        global $DB;
        $now = Db::now();
        $DB->update(self::TABLE, ['status' => 'pending', 'locked_by' => '', 'locked_at' => null, 'date_mod' => $now], [
            'status' => 'running', 'locked_at' => ['<', date('Y-m-d H:i:s', Db::time() - self::STALE_SEC)],
        ]);
        $q = 'UPDATE ' . $DB::quoteName(self::TABLE)
            . " SET `status` = 'running', `locked_by` = " . $DB->quoteValue($token) . ', `locked_at` = ' . $DB->quoteValue($now)
            . ', `attempts` = `attempts` + 1, `date_mod` = ' . $DB->quoteValue($now)
            . " WHERE `status` = 'pending' AND (`next_run_at` IS NULL OR `next_run_at` <= " . $DB->quoteValue($now) . ')'
            . ' ORDER BY `next_run_at`, `id` LIMIT ' . max(1, $limit);
        $DB->doQuery($q);
        return iterator_to_array($DB->request(['FROM' => self::TABLE, 'WHERE' => ['locked_by' => $token, 'status' => 'running'], 'ORDER' => 'id']), false);
    }

    /** @param array<string,mixed> $job */
    public static function complete(array $job): void
    {
        global $DB;
        $now = Db::now();
        $cur = Db::row(self::TABLE, ['id' => (int) $job['id']]);
        if ($cur && (int) $cur['dirty'] === 1) {
            $DB->update(self::TABLE, ['status' => 'pending', 'dirty' => 0, 'attempts' => 0, 'locked_by' => '', 'locked_at' => null, 'next_run_at' => $now, 'date_mod' => $now], ['id' => (int) $job['id']]);
            return;
        }
        $DB->update(self::TABLE, ['status' => 'done', 'locked_by' => '', 'locked_at' => null, 'last_error' => null, 'date_mod' => $now], ['id' => (int) $job['id']]);
    }

    /** @param array<string,mixed> $job */
    public static function fail(array $job, string $error): void
    {
        global $DB;
        $attempts = (int) $job['attempts'];
        $max = Config::get('job_max_attempts');
        $now = Db::now();
        $upd = ['locked_by' => '', 'locked_at' => null, 'dirty' => 0, 'last_error' => mb_substr($error, 0, 2000), 'date_mod' => $now];
        if ($attempts >= $max) {
            $upd['status'] = 'failed';
        } else {
            $upd['status'] = 'pending';
            $upd['next_run_at'] = date('Y-m-d H:i:s', Db::time() + min(3600, 60 * (2 ** max(0, $attempts - 1))));
        }
        $DB->update(self::TABLE, $upd, ['id' => (int) $job['id']]);
    }

    public static function retry(int $id): void
    {
        global $DB;
        $DB->update(self::TABLE, ['status' => 'pending', 'attempts' => 0, 'next_run_at' => Db::now(), 'date_mod' => Db::now()], ['id' => $id, 'status' => 'failed']);
    }

    /** Biten işleri temizler (7 günden eski). */
    public static function purgeDone(): void
    {
        global $DB;
        $DB->delete(self::TABLE, ['status' => 'done', 'date_mod' => ['<', date('Y-m-d H:i:s', Db::time() - 7 * 86400)]]);
    }

    /** @return array<string,int> */
    public static function stats(): array
    {
        global $DB;
        $s = ['pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0];
        foreach ($DB->request(['SELECT' => ['status', 'COUNT' => 'id AS n'], 'FROM' => self::TABLE, 'GROUPBY' => 'status']) as $r) {
            $s[(string) $r['status']] = (int) $r['n'];
        }
        return $s;
    }

    /**
     * Kuyruğu bütçe içinde işler.
     * @return array{done:int,failed:int}
     */
    public static function process(int $budgetSec): array
    {
        $deadline = Db::time() + $budgetSec;
        $token = bin2hex(random_bytes(8));
        $done = 0;
        $failed = 0;
        while (Db::time() < $deadline) {
            $jobs = self::claim(20, $token);
            if (!$jobs) {
                break;
            }
            foreach ($jobs as $job) {
                try {
                    self::run($job);
                    self::complete($job);
                    $done++;
                } catch (\Throwable $e) {
                    self::fail($job, $e->getMessage());
                    $failed++;
                }
            }
        }
        return ['done' => $done, 'failed' => $failed];
    }

    /** @param array<string,mixed> $job */
    private static function run(array $job): void
    {
        $p = Db::decode($job['payload']);
        switch ($job['kind']) {
            case 'recheck':
                ScanRunner::recheckItem((string) $p['itemtype'], (int) $p['items_id']);
                TicketBridge::syncAsset((string) $p['itemtype'], (int) $p['items_id']);
                break;
            case 'ticket_sync':
                TicketBridge::syncAsset((string) $p['itemtype'], (int) $p['items_id']);
                break;
            case 'ticket_check':
                TicketBridge::checkTicket((int) $p['tickets_id']);
                break;
            case 'scan_rule':
                ScanRunner::start('rule', [(int) $p['rules_id']], 'queue');
                break;
            case 'user_change':
                EventHooks::enqueueAssetsOfUser((int) $p['users_id']);
                break;
            default:
                throw new \RuntimeException('Bilinmeyen iş türü: ' . $job['kind']);
        }
    }
}
