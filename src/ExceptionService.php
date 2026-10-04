<?php

namespace GlpiPlugin\Inventoryquality;

use InvalidArgumentException;
use Session;

/**
 * Süreli istisna. Gerekçe, onaylayan (istisnayı veren yetkili), kapsam, bitiş tarihi zorunlu; telafi edici işlem
 * varsa yazılır. İstisna bir yaşam döngüsü durumudur: altındaki FAIL sonucunu PASS yapmaz, puanı değiştirmez,
 * sorun çözülmüş sayılmaz. Süre dolunca (saatlik görev) kayıt yeniden kontrol edilir: FAIL → Açık, PASS → Çözüldü,
 * UNKNOWN → İnceleme gerekli. İstisna kendiliğinden başarıya çevrilmez.
 */
final class ExceptionService
{
    public const TABLE = Install::P . 'exceptions';
    public const MAX_DAYS = 365;

    public static function grant(int $findingsId, string $reason, string $endDate, string $compensating = ''): int
    {
        global $DB;
        if (!Rights::has(Rights::EXCEPTION)) {
            throw new InvalidArgumentException(__('İstisna verme yetkiniz yok.', 'inventoryquality'));
        }
        $f = FindingService::loadForAction($findingsId);
        if (!in_array($f['status'], Finding::ACTIVE, true) || $f['last_result'] !== RuleEvaluator::FAIL) {
            throw new InvalidArgumentException(__('İstisna yalnız uygunsuz (FAIL) ve aktif bulguya verilir.', 'inventoryquality'));
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new InvalidArgumentException(__('İstisna gerekçesi zorunludur.', 'inventoryquality'));
        }
        $ts = strtotime($endDate . (strlen(trim($endDate)) === 10 ? ' 23:59:59' : ''));
        if ($ts === false || $ts <= Db::time() || $ts > Db::time() + self::MAX_DAYS * 86400) {
            throw new InvalidArgumentException(sprintf(__('Bitiş tarihi gelecekte ve en fazla %d gün sonra olmalı.', 'inventoryquality'), self::MAX_DAYS));
        }
        if (countElementsInTable(self::TABLE, ['findings_id' => $findingsId, 'status' => 'active']) > 0) {
            throw new InvalidArgumentException(__('Bu bulgunun zaten geçerli bir istisnası var.', 'inventoryquality'));
        }
        $DB->insert(self::TABLE, [
            'findings_id' => $findingsId, 'entities_id' => (int) $f['entities_id'], 'scope' => 'finding',
            'reason' => mb_substr($reason, 0, 4000), 'compensating' => mb_substr(trim($compensating), 0, 4000),
            'end_date' => date('Y-m-d H:i:s', $ts), 'status' => 'active', 'users_id' => (int) Session::getLoginUserID(), 'date_creation' => Db::now(),
        ]);
        $id = (int) $DB->insertId();
        FindingService::setStatus($findingsId, Finding::EXCEPTION, sprintf(__('İstisna (%s tarihine kadar)', 'inventoryquality'), date('Y-m-d', $ts)));
        AuditLog::write('exception_grant', Finding::class, $findingsId, (int) $f['entities_id'], ['status' => $f['status']],
            ['status' => Finding::EXCEPTION, 'end_date' => date('Y-m-d H:i:s', $ts), 'compensating' => $compensating], $reason);
        JobQueue::enqueue('ticket_sync', 'ticket_sync:' . $f['itemtype'] . ':' . $f['items_id'], ['itemtype' => $f['itemtype'], 'items_id' => (int) $f['items_id']]);
        return $id;
    }

    public static function revoke(int $id, string $reason): void
    {
        if (!Rights::has(Rights::EXCEPTION)) {
            throw new InvalidArgumentException(__('İstisna verme yetkiniz yok.', 'inventoryquality'));
        }
        $e = Db::row(self::TABLE, ['id' => $id]);
        if (!$e || $e['status'] !== 'active' || !Session::haveAccessToEntity((int) $e['entities_id'])) {
            throw new InvalidArgumentException(__('Geçerli istisna bulunamadı.', 'inventoryquality'));
        }
        self::finish($e, 'revoked', trim($reason) !== '' ? trim($reason) : __('İstisna geri alındı', 'inventoryquality'), (int) Session::getLoginUserID());
    }

    /** Saatlik: süresi dolan istisnalar. @return int işlenen sayı */
    public static function expireDue(): int
    {
        global $DB;
        $n = 0;
        foreach (iterator_to_array($DB->request(['FROM' => self::TABLE, 'WHERE' => ['status' => 'active', 'end_date' => ['<', Db::now()]]]), false) as $e) {
            self::finish($e, 'expired', __('İstisna süresi doldu', 'inventoryquality'), 0);
            $n++;
        }
        return $n;
    }

    /** Bulgu istisna durumundan PASS / kapsam dışı ile çıktığında geçerli istisnayı kapatır (yeniden kontrol yok). */
    public static function endFor(int $findingsId, string $reason): void
    {
        global $DB;
        $DB->update(self::TABLE, ['status' => 'ended', 'end_reason' => mb_substr($reason, 0, 255), 'date_end' => Db::now()], ['findings_id' => $findingsId, 'status' => 'active']);
    }

    /** @param array<string,mixed> $e */
    private static function finish(array $e, string $status, string $reason, int $uid): void
    {
        global $DB;
        $DB->update(self::TABLE, ['status' => $status, 'end_reason' => mb_substr($reason, 0, 255), 'users_id_end' => $uid, 'date_end' => Db::now()], ['id' => (int) $e['id'], 'status' => 'active']);
        if ($DB->affectedRows() !== 1) {
            return;
        }
        $f = Db::row(Finding::getTable(), ['id' => (int) $e['findings_id']]);
        AuditLog::write('exception_' . $status, Finding::class, (int) $e['findings_id'], (int) $e['entities_id'], ['exception' => (int) $e['id']], ['status' => $status], $reason);
        if (!$f || $f['status'] !== Finding::EXCEPTION) {
            return;
        }
        // Yeniden kontrol: FAIL → Açık, PASS → Çözüldü, UNKNOWN → İnceleme gerekli.
        FindingService::setStatus((int) $f['id'], Finding::OPEN, $reason);
        ScanRunner::recheckItem((string) $f['itemtype'], (int) $f['items_id']);
        JobQueue::enqueue('ticket_sync', 'ticket_sync:' . $f['itemtype'] . ':' . $f['items_id'], ['itemtype' => $f['itemtype'], 'items_id' => (int) $f['items_id']]);
    }

    /** @return array<string,mixed>|null */
    public static function activeFor(int $findingsId): ?array
    {
        return Db::row(self::TABLE, ['findings_id' => $findingsId, 'status' => 'active']);
    }

    public static function statusLabel(string $s): string
    {
        return match ($s) {
            'active'  => __('Geçerli', 'inventoryquality'),
            'expired' => __('Süresi doldu', 'inventoryquality'),
            'revoked' => __('Geri alındı', 'inventoryquality'),
            'ended'   => __('Sona erdi (kural sağlandı / kapsam dışı)', 'inventoryquality'),
            default   => $s,
        };
    }
}
