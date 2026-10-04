<?php

namespace GlpiPlugin\Inventoryquality;

use Session;

/**
 * Düzeltmenin güvenli uygulanması (tasarım madde 16–18):
 *
 *   claimOnce → reloadAssetAndPolicy → checkEntityRightsAndRequiredApprovals → validateAllowedFieldsTypesAndReferences
 *   → compareTargetFieldsWithSnapshot → applyWithVerifiedAdapterAndAudit → enqueueVerificationAndNotifications
 *   → APPLIED (doğrulama bekliyor)
 *
 * - Tek işlem: düzeltme yalnız "approved" durumundan koşullu güncellemeyle sahiplenilir; tekrar çalışan iş aynı
 *   değişikliği ikinci kez uygulamaz.
 * - Çakışma: hedef alan(lar) ya da birim anlık görüntüden farklıysa ya da onay politikası artık geçerli değilse
 *   CONFLICT; yazma yapılmaz, eski onay yeni değerlere taşınmaz. İlgisiz bir alanın değişmesi çakışma değildir.
 * - Kontrol ile yazma arasındaki yarış, aynı işlem içinde varlık satırının kilitlenmesiyle (SELECT … FOR UPDATE)
 *   önlenir. Varlık doğrudan SQL ile değil GLPI nesne güncellemesiyle (CommonDBTM::update) değiştirilir; çekirdek
 *   alanların yazımı ve denetim kaydı aynı veritabanı işleminde: hata olursa ikisi de geri alınır, düzeltme
 *   "uygulandı" işaretlenmez.
 * - Yazma başarılı olsa bile bulgu hemen çözülmez: kayıt yeniden kontrol kuyruğuna alınır.
 */
final class CorrectionProcessor
{
    /** Test kancası: denetim kaydından sonra yapay hata (T09). */
    public static bool $failAfterAudit = false;

    public static function process(int $corrId): string
    {
        global $DB;
        $T = CorrectionService::TABLE;
        // claimOnce
        $DB->update($T, ['status' => CorrectionService::PROCESSING, 'attempts' => new \Glpi\DBAL\QueryExpression('`attempts` + 1'), 'date_mod' => Db::now()],
            ['id' => $corrId, 'status' => CorrectionService::APPROVED]);
        if ($DB->affectedRows() !== 1) {
            return (string) (Db::row($T, ['id' => $corrId])['status'] ?? '');
        }
        $c = Db::row($T, ['id' => $corrId]);
        $changes = Db::decode($c['changes']);
        $snap = Db::decode($c['snapshot']);
        $policy = Db::decode($c['policy']);
        $itemtype = (string) $c['itemtype'];
        $id = (int) $c['items_id'];
        $inTx = false; // GLPI 11 DBmysql işlem durumunu dışa açmaz (iç içe işlemde savepoint kullanır)
        try {
            // reloadAssetAndPolicy + checkEntityRightsAndRequiredApprovals
            $rule = Db::row(Rule::getTable(), ['id' => (int) $c['rules_id']]);
            if (!$rule || (int) $rule['is_active'] !== 1 || $rule['suspended_reason'] !== '') {
                throw new CorrectionConflict(__('Kural artık etkin değil.', 'inventoryquality'));
            }
            $cur = Rule::version((int) $rule['ruleversions_id']);
            $curPolicy = $cur['def']['policy']['approval'] ?? ['level' => 'none'];
            if (self::rank($curPolicy['level'] ?? 'none') > self::rank($policy['level'] ?? 'none')) {
                throw new CorrectionConflict(__('Onay politikası değişti; bu öneri artık geçerli politikayı karşılamıyor.', 'inventoryquality'));
            }
            foreach (array_keys((array) ($policy['steps'] ?? [])) as $step) {
                if (countElementsInTable(CorrectionService::APPROVALS, ['corrections_id' => $corrId, 'step' => (int) $step, 'status' => 'approved']) === 0
                    || countElementsInTable(CorrectionService::APPROVALS, ['corrections_id' => $corrId, 'step' => (int) $step, 'status' => ['waiting', 'pending', 'rejected']]) > 0) {
                    throw new \RuntimeException(__('Gerekli onaylar tamamlanmamış.', 'inventoryquality'));
                }
            }
            // validateAllowedFieldsTypesAndReferences
            $cols = [];
            foreach ($changes as $key => $ch) {
                $d = Catalog::get($itemtype, (string) $key);
                if (!$d || !$d['writable'] || $d['column'] === '' || $d['datatype'] !== ($ch['datatype'] ?? '')) {
                    throw new CorrectionConflict(sprintf(__('Alan artık düzeltilebilir değil ya da tipi değişti: %s', 'inventoryquality'), $key));
                }
                $norm = CorrectionService::normalizeValue($d, $ch['new']);
                $cols[(string) $key] = ['col' => $d['column'], 'new' => $norm['raw'], 'old' => $ch['old']];
            }
            // compareTargetFieldsWithSnapshot + applyWithVerifiedAdapterAndAudit (tek işlem, satır kilidi)
            $table = getTableForItemType($itemtype);
            $DB->beginTransaction();
            $inTx = true;
            $sel = 'SELECT `entities_id`, `is_deleted`';
            foreach ($cols as $x) {
                $sel .= ', ' . $DB::quoteName($x['col']);
            }
            $row = $DB->doQuery($sel . ' FROM ' . $DB::quoteName($table) . ' WHERE `id` = ' . (int) $id . ' FOR UPDATE')->fetch_assoc();
            if (!$row || (int) $row['is_deleted'] === 1) {
                throw new CorrectionConflict(__('Varlık bulunamadı ya da çöp kutusunda.', 'inventoryquality'));
            }
            if ((int) $row['entities_id'] !== (int) ($snap['entities_id'] ?? -1)) {
                throw new CorrectionConflict(__('Varlık başka bir kurum birimine taşındı.', 'inventoryquality'));
            }
            foreach ($cols as $key => $x) {
                if ((string) ($row[$x['col']] ?? '') !== (string) ($snap['fields'][$key] ?? '')) {
                    throw new CorrectionConflict(sprintf(__('Hedef alan onay sürecinde değişti (%s); güncel değerle yeni öneri gerekir.', 'inventoryquality'), Catalog::get($itemtype, $key)['label'] ?? $key));
                }
            }
            $item = new $itemtype();
            if (!$item->getFromDB($id)) {
                throw new CorrectionConflict(__('Varlık bulunamadı.', 'inventoryquality'));
            }
            $input = ['id' => $id];
            foreach ($cols as $x) {
                $input[$x['col']] = $x['new'];
            }
            if (!$item->update($input)) {
                throw new \RuntimeException(__('GLPI varlığı güncellemedi.', 'inventoryquality'));
            }
            $after = $DB->doQuery(str_replace(' FOR UPDATE', '', $sel . ' FROM ' . $DB::quoteName($table) . ' WHERE `id` = ' . (int) $id))->fetch_assoc();
            foreach ($cols as $key => $x) {
                if ((string) ($after[$x['col']] ?? '') !== (string) $x['new']) {
                    throw new \RuntimeException(sprintf(__('Yazılan değer doğrulanamadı (%s).', 'inventoryquality'), $key));
                }
            }
            AuditLog::write('correction_apply', CorrectionService::class, $corrId, (int) $c['entities_id'],
                array_map(static fn($x) => $x['old'], $cols), array_map(static fn($x) => $x['new'], $cols), (string) $c['reason']);
            if (self::$failAfterAudit) {
                throw new \RuntimeException('Yapay hata (test): denetim kaydından sonra.');
            }
            $DB->update($T, ['status' => CorrectionService::APPLIED, 'applied_at' => Db::now(), 'users_id_applier' => (int) Session::getLoginUserID(false),
                'result_message' => null, 'date_mod' => Db::now()], ['id' => $corrId]);
            $DB->commit();
            $inTx = false;
        } catch (CorrectionConflict $e) {
            if ($inTx) {
                $DB->rollBack();
            }
            return self::finish($c, CorrectionService::CONFLICT, $e->getMessage());
        } catch (\Throwable $e) {
            if ($inTx) {
                $DB->rollBack();
            }
            return self::finish($c, CorrectionService::FAILED, $e->getMessage());
        }
        // enqueueVerificationAndNotifications
        FindingService::setStatus((int) $c['findings_id'], Finding::PENDING_VERIFICATION, __('Düzeltme uygulandı; güncel veri yeniden kontrol edilecek', 'inventoryquality'));
        JobQueue::enqueue('recheck', "recheck:$itemtype:$id", ['itemtype' => $itemtype, 'items_id' => $id]);
        $labels = [];
        foreach ($changes as $key => $ch) {
            $labels[] = (Catalog::get($itemtype, (string) $key)['label'] ?? $key) . ': ' . ($ch['old_label'] ?? '') . ' → ' . ($ch['new_label'] ?? '');
        }
        TicketBridge::note((int) $c['findings_id'], sprintf(__('Düzeltme #%d uygulandı (%s). Kayıt yeniden kontrol edilecek; bulgu yalnız kural sağlanırsa çözülür.', 'inventoryquality'), $corrId, implode('; ', $labels)));
        return CorrectionService::APPLIED;
    }

    private static function finish(array $c, string $status, string $message): string
    {
        global $DB;
        $DB->update(CorrectionService::TABLE, ['status' => $status, 'result_message' => mb_substr($message, 0, 2000), 'date_mod' => Db::now()], ['id' => (int) $c['id']]);
        AuditLog::write('correction_' . $status, CorrectionService::class, (int) $c['id'], (int) $c['entities_id'], null, ['status' => $status], $message);
        FindingService::setStatus((int) $c['findings_id'], Finding::OPEN, $status === CorrectionService::CONFLICT
            ? sprintf(__('Düzeltme uygulanmadı (çakışma): %s', 'inventoryquality'), $message)
            : sprintf(__('Düzeltme uygulanamadı: %s', 'inventoryquality'), $message), [Finding::PENDING_APPROVAL, Finding::REVIEW, Finding::OPEN, Finding::IN_PROGRESS]);
        TicketBridge::note((int) $c['findings_id'], sprintf(__('Düzeltme #%d uygulanmadı: %s', 'inventoryquality'), (int) $c['id'], $message));
        return $status;
    }

    private static function rank(string $level): int
    {
        return ['none' => 0, 'one' => 1, 'two' => 2][$level] ?? 0;
    }
}
