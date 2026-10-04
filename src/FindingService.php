<?php

namespace GlpiPlugin\Inventoryquality;

use InvalidArgumentException;
use Session;

/**
 * Bulgu yaşam döngüsü: tekilleştirme, dönemler, durum geçişleri.
 *
 * Kapanış ilkesi: "düzelttim" beyanı, onay ya da destek kaydının kapatılması bulguyu ÇÖZMEZ. Çözüm yalnız güncel
 * veride ilgili kuralın PASS vermesiyle olur. UNKNOWN bulguyu çözmez ("İnceleme gerekli"); önkoşulun / kapsamın
 * kalkması bulguyu "Kapsam dışı" yapar (çözüm başarısına eklenmez). Çözülmüş kayıtta kural yeniden FAIL verirse
 * yeni dönem açılır; önceki dönem korunur.
 */
final class FindingService
{
    public const CYCLES = Install::P . 'cycles';

    /**
     * Bir varlık × kural değerlendirmesinin bulguya etkisi.
     * @param array<string,mixed> $rule  Rule::activeRules satırı
     * @param array<string,mixed> $res   RuleEvaluator::evaluate sonucu
     * @param array<string,mixed> $asset AssetAdapter verisi (atama için)
     * @return array{finding_id:int,transition:string}
     */
    public static function apply(string $itemtype, int $itemsId, int $entitiesId, array $rule, array $res, array $asset): array
    {
        global $DB;
        $T = Finding::getTable();
        $def = $rule['v']['def'];
        $target = (string) ($def['target'] ?? '');
        $key = Finding::key($entitiesId, $itemtype, $itemsId, (int) $rule['id'], $target);
        $now = Db::now();
        $detail = Db::json(['reason' => $res['reason'], 'observed' => $res['observed'], 'error' => $res['error']]);
        $f = Db::row($T, ['finding_key' => $key]);

        switch ($res['result']) {
            case RuleEvaluator::FAIL:
                if (!$f) {
                    $a = AssignmentResolver::resolve($entitiesId, $def['policy'] ?? [], $asset);
                    $id = Db::insertIgnore($T, [
                        'finding_key' => $key, 'entities_id' => $entitiesId, 'itemtype' => $itemtype, 'items_id' => $itemsId,
                        'rules_id' => (int) $rule['id'], 'target' => $target, 'status' => Finding::OPEN,
                        'severity' => (int) $rule['v']['severity'], 'cycle' => 1, 'first_seen' => $now, 'last_seen' => $now,
                        'seen_count' => 1, 'last_result' => RuleEvaluator::FAIL, 'last_check' => $now,
                        'ruleversions_id' => (int) $rule['v']['id'], 'detail' => $detail,
                        'users_id_assign' => $a['users_id'], 'groups_id_assign' => $a['groups_id'],
                        'assign_state' => $a['state'], 'assign_reason' => $a['reason'],
                        'due_date' => self::due($entitiesId, $def), 'status_reason' => '',
                        'date_creation' => $now, 'date_mod' => $now,
                    ]);
                    if ($id > 0) {
                        self::openCycle($id, 1, (int) $rule['v']['id']);
                        AuditLog::write('finding_open', Finding::class, $id, $entitiesId, null, ['rule' => $rule['code'], 'assign' => $a]);
                        return ['finding_id' => $id, 'transition' => 'open'];
                    }
                    $f = Db::row($T, ['finding_key' => $key]); // eşzamanlı ikinci işçi: aynı bulguyu koru
                    if (!$f) {
                        return ['finding_id' => 0, 'transition' => 'none'];
                    }
                }
                $id = (int) $f['id'];
                $upd = [
                    'last_seen' => $now, 'seen_count' => (int) $f['seen_count'] + 1, 'last_result' => RuleEvaluator::FAIL,
                    'last_check' => $now, 'detail' => $detail, 'ruleversions_id' => (int) $rule['v']['id'],
                    'severity' => (int) $rule['v']['severity'], 'date_mod' => $now,
                ];
                if (in_array($f['status'], Finding::ACTIVE, true)) {
                    $transition = 'seen';
                    if (in_array($f['status'], [Finding::PENDING_VERIFICATION, Finding::REVIEW], true)) {
                        $upd['status'] = Finding::OPEN;
                        $upd['status_reason'] = $f['status'] === Finding::PENDING_VERIFICATION
                            ? __('Doğrulama: kural güncel veride hâlâ sağlanmıyor', 'inventoryquality') : '';
                        $transition = 'reverify_fail';
                    }
                    if ($f['assign_state'] === 'waiting') {
                        $a = AssignmentResolver::resolve($entitiesId, $def['policy'] ?? [], $asset);
                        if ($a['state'] === 'assigned') {
                            $upd += ['users_id_assign' => $a['users_id'], 'groups_id_assign' => $a['groups_id'], 'assign_state' => 'assigned', 'assign_reason' => $a['reason']];
                        }
                    }
                    $DB->update($T, $upd, ['id' => $id]);
                    if ($transition !== 'seen') {
                        AuditLog::write('finding_' . $transition, Finding::class, $id, $entitiesId, ['status' => $f['status']], ['status' => $upd['status']]);
                    }
                    return ['finding_id' => $id, 'transition' => $transition];
                }
                if ($f['status'] === Finding::EXCEPTION) {
                    $DB->update($T, $upd, ['id' => $id]);
                    return ['finding_id' => $id, 'transition' => 'seen'];
                }
                // Çözülmüş / kapsam dışı → yeni dönem.
                $cycle = (int) $f['cycle'] + 1;
                $a = AssignmentResolver::resolve($entitiesId, $def['policy'] ?? [], $asset);
                $DB->update($T, $upd + [
                    'status' => Finding::OPEN, 'cycle' => $cycle, 'status_reason' => '',
                    'users_id_assign' => $a['users_id'], 'groups_id_assign' => $a['groups_id'], 'assign_state' => $a['state'], 'assign_reason' => $a['reason'],
                    'due_date' => self::due($entitiesId, $def),
                ], ['id' => $id]);
                self::openCycle($id, $cycle, (int) $rule['v']['id']);
                AuditLog::write('finding_reopen', Finding::class, $id, $entitiesId, ['status' => $f['status'], 'cycle' => $cycle - 1], ['status' => Finding::OPEN, 'cycle' => $cycle]);
                return ['finding_id' => $id, 'transition' => 'reopen'];

            case RuleEvaluator::PASS:
                if (!$f) {
                    return ['finding_id' => 0, 'transition' => 'none'];
                }
                if (in_array($f['status'], Finding::ACTIVE, true) || $f['status'] === Finding::EXCEPTION) {
                    self::close($f, Finding::RESOLVED, __('Doğrulandı: kural güncel veride sağlandı', 'inventoryquality'), (int) $rule['v']['id'], $detail, RuleEvaluator::PASS);
                    return ['finding_id' => (int) $f['id'], 'transition' => 'resolve'];
                }
                $DB->update($T, ['last_result' => RuleEvaluator::PASS, 'last_check' => $now, 'date_mod' => $now], ['id' => (int) $f['id']]);
                return ['finding_id' => (int) $f['id'], 'transition' => 'none'];

            case RuleEvaluator::UNKNOWN:
                if (!$f) {
                    return ['finding_id' => 0, 'transition' => 'none'];
                }
                $upd = ['last_result' => RuleEvaluator::UNKNOWN, 'last_check' => $now, 'detail' => $detail, 'date_mod' => $now];
                if (in_array($f['status'], Finding::ACTIVE, true) && $f['status'] !== Finding::REVIEW) {
                    $upd['status'] = Finding::REVIEW;
                    $upd['status_reason'] = sprintf(__('Veri okunamadı (%s); bulgu çözülmedi', 'inventoryquality'), (string) $res['error']);
                    $DB->update(Finding::getTable(), $upd, ['id' => (int) $f['id']]);
                    AuditLog::write('finding_review', Finding::class, (int) $f['id'], $entitiesId, ['status' => $f['status']], ['status' => Finding::REVIEW], (string) $res['error']);
                    return ['finding_id' => (int) $f['id'], 'transition' => 'review'];
                }
                $DB->update(Finding::getTable(), $upd, ['id' => (int) $f['id']]);
                return ['finding_id' => (int) $f['id'], 'transition' => 'none'];

            case RuleEvaluator::NA:
                if ($f && (in_array($f['status'], Finding::ACTIVE, true) || $f['status'] === Finding::EXCEPTION)) {
                    self::close($f, Finding::OUT_OF_SCOPE, __('Önkoşul artık sağlanmıyor (kural bu kayda uygulanmıyor)', 'inventoryquality'), (int) $rule['v']['id'], $detail, RuleEvaluator::NA);
                    return ['finding_id' => (int) $f['id'], 'transition' => 'out_of_scope'];
                }
                return ['finding_id' => $f ? (int) $f['id'] : 0, 'transition' => 'none'];
        }
        return ['finding_id' => 0, 'transition' => 'none'];
    }

    /**
     * Varlık (ya da varlık × kural) kapsamdan çıktı: aktif bulgular gerekçeyle "Kapsam dışı" olur. Çözülmüş sayılmaz.
     * @return int kapatılan bulgu sayısı
     */
    public static function outOfScope(string $itemtype, int $itemsId, ?int $rulesId, string $reason): int
    {
        global $DB;
        $w = ['itemtype' => $itemtype, 'items_id' => $itemsId, 'status' => array_merge(Finding::ACTIVE, [Finding::EXCEPTION])];
        if ($rulesId !== null) {
            $w['rules_id'] = $rulesId;
        }
        $n = 0;
        foreach (iterator_to_array($DB->request(['FROM' => Finding::getTable(), 'WHERE' => $w]), false) as $f) {
            self::close($f, Finding::OUT_OF_SCOPE, $reason, 0, null, '');
            $n++;
        }
        return $n;
    }

    public static function closeForRule(int $rulesId, string $reason): int
    {
        global $DB;
        $n = 0;
        $assets = [];
        foreach (iterator_to_array($DB->request(['FROM' => Finding::getTable(), 'WHERE' => ['rules_id' => $rulesId, 'status' => array_merge(Finding::ACTIVE, [Finding::EXCEPTION])]]), false) as $f) {
            self::close($f, Finding::OUT_OF_SCOPE, $reason, 0, null, '');
            $assets[$f['itemtype'] . ':' . $f['items_id']] = [$f['itemtype'], (int) $f['items_id']];
            $n++;
        }
        foreach ($assets as [$it, $id]) {
            JobQueue::enqueue('ticket_sync', "ticket_sync:$it:$id", ['itemtype' => $it, 'items_id' => $id]);
        }
        return $n;
    }

    /** @param array<string,mixed> $f bulgu satırı */
    private static function close(array $f, string $status, string $reason, int $versionId, ?string $detail, string $result): void
    {
        global $DB;
        $now = Db::now();
        $upd = ['status' => $status, 'status_reason' => mb_substr($reason, 0, 255), 'last_check' => $now, 'date_mod' => $now];
        if ($result !== '') {
            $upd['last_result'] = $result;
        }
        if ($detail !== null) {
            $upd['detail'] = $detail;
        }
        $DB->update(Finding::getTable(), $upd, ['id' => (int) $f['id']]);
        if ($f['status'] === Finding::EXCEPTION) {
            ExceptionService::endFor((int) $f['id'], $reason);
        }
        // Bulgu kapandı: bekleyen düzeltme önerileri geçersiz (güncel veriyle yeni öneri gerekir).
        CorrectionService::voidOpenFor((int) $f['id'], $reason);
        $DB->update(self::CYCLES, [
            'closed_at' => $now, 'close_status' => $status, 'close_reason' => mb_substr($reason, 0, 255),
            'closed_ruleversions_id' => $versionId,
        ], ['findings_id' => (int) $f['id'], 'cycle' => (int) $f['cycle'], 'closed_at' => null]);
        AuditLog::write($status === Finding::RESOLVED ? 'finding_resolve' : 'finding_' . $status, Finding::class, (int) $f['id'], (int) $f['entities_id'], ['status' => $f['status']], ['status' => $status], $reason);
    }

    /**
     * Durum geçişi (düzeltme, onay, istisna akışları). Kapanış (Çözüldü / Kapsam dışı) için kullanılmaz.
     * @param list<string>|null $onlyFrom yalnız bu durumlardaysa değiştir
     */
    public static function setStatus(int $findingsId, string $status, string $reason, ?array $onlyFrom = null): bool
    {
        global $DB;
        $f = Db::row(Finding::getTable(), ['id' => $findingsId]);
        if (!$f || ($onlyFrom !== null && !in_array($f['status'], $onlyFrom, true)) || $f['status'] === $status && $f['status_reason'] === $reason) {
            return false;
        }
        $DB->update(Finding::getTable(), ['status' => $status, 'status_reason' => mb_substr($reason, 0, 255), 'date_mod' => Db::now()], ['id' => $findingsId]);
        if ($f['status'] !== $status) {
            AuditLog::write('finding_' . $status, Finding::class, $findingsId, (int) $f['entities_id'], ['status' => $f['status']], ['status' => $status], $reason);
        }
        return true;
    }

    private static function openCycle(int $findingsId, int $cycle, int $versionId): void
    {
        Db::insertIgnore(self::CYCLES, ['findings_id' => $findingsId, 'cycle' => $cycle, 'opened_at' => Db::now(), 'opened_ruleversions_id' => $versionId]);
    }

    private static function due(int $entitiesId, array $def): string
    {
        $days = (int) ($def['policy']['due_days'] ?? 0);
        if ($days <= 0) {
            $days = (int) EntityConfig::effective($entitiesId)['due_days'];
        }
        return date('Y-m-d H:i:s', Db::time() + $days * 86400);
    }

    // ------------------------------------------------------------------ kullanıcı işlemleri

    /** Sorumlu işi üstlenir → İşlemde. */
    public static function take(int $id): void
    {
        global $DB;
        $f = self::loadForAction($id);
        $uid = (int) Session::getLoginUserID();
        if (!self::isAssignee($f, $uid) && !Rights::has(Rights::ASSIGN)) {
            throw new InvalidArgumentException(__('Bu bulgu size ya da grubunuza atanmamış.', 'inventoryquality'));
        }
        if (!in_array($f['status'], [Finding::OPEN, Finding::REVIEW], true)) {
            throw new InvalidArgumentException(__('Yalnız açık bulgu üstlenilebilir.', 'inventoryquality'));
        }
        $upd = ['status' => Finding::IN_PROGRESS, 'status_reason' => '', 'date_mod' => Db::now()];
        if ((int) $f['users_id_assign'] === 0 && AssignmentResolver::validUser($uid, (int) $f['entities_id'])) {
            $upd += ['users_id_assign' => $uid, 'assign_state' => 'assigned', 'assign_reason' => 'taken'];
        }
        $DB->update(Finding::getTable(), $upd, ['id' => $id]);
        AuditLog::write('finding_take', Finding::class, $id, (int) $f['entities_id'], ['status' => $f['status']], ['status' => Finding::IN_PROGRESS]);
    }

    /** Elle atama (İş ata yetkisi). Aktiflik, grup uygunluğu ve birim yetkisi doğrulanır. */
    public static function assign(int $id, int $usersId, int $groupsId): void
    {
        global $DB;
        $f = self::loadForAction($id);
        if (!Rights::has(Rights::ASSIGN)) {
            throw new InvalidArgumentException(__('İş atama yetkiniz yok.', 'inventoryquality'));
        }
        $eid = (int) $f['entities_id'];
        if ($usersId > 0 && !AssignmentResolver::validUser($usersId, $eid)) {
            throw new InvalidArgumentException(__('Seçilen kullanıcı aktif değil ya da bu birimde yetkili değil.', 'inventoryquality'));
        }
        if ($groupsId > 0 && !AssignmentResolver::validGroup($groupsId, $eid)) {
            throw new InvalidArgumentException(__('Seçilen grup atanabilir değil ya da bu birimde görünmüyor.', 'inventoryquality'));
        }
        $state = ($usersId > 0 || $groupsId > 0) ? 'assigned' : 'waiting';
        $DB->update(Finding::getTable(), [
            'users_id_assign' => $usersId, 'groups_id_assign' => $groupsId, 'assign_state' => $state,
            'assign_reason' => 'manual', 'date_mod' => Db::now(),
        ], ['id' => $id]);
        AuditLog::write('finding_assign', Finding::class, $id, $eid,
            ['users_id' => (int) $f['users_id_assign'], 'groups_id' => (int) $f['groups_id_assign']], ['users_id' => $usersId, 'groups_id' => $groupsId]);
        JobQueue::enqueue('ticket_sync', 'ticket_sync:' . $f['itemtype'] . ':' . $f['items_id'], ['itemtype' => $f['itemtype'], 'items_id' => (int) $f['items_id']]);
    }

    /** "Düzelttim": Doğrulama bekliyor → çağıran hemen yeniden kontrol eder; bulgu yalnız PASS ile çözülür. */
    public static function markFixed(int $id, string $note = ''): void
    {
        global $DB;
        $f = self::loadForAction($id);
        $uid = (int) Session::getLoginUserID();
        if (!self::isAssignee($f, $uid) && !Rights::has(Rights::SCAN)) {
            throw new InvalidArgumentException(__('Bu bulgu size ya da grubunuza atanmamış.', 'inventoryquality'));
        }
        if (!in_array($f['status'], Finding::ACTIVE, true)) {
            throw new InvalidArgumentException(__('Bulgu aktif değil.', 'inventoryquality'));
        }
        $DB->update(Finding::getTable(), ['status' => Finding::PENDING_VERIFICATION, 'status_reason' => mb_substr($note, 0, 255), 'date_mod' => Db::now()], ['id' => $id]);
        AuditLog::write('finding_mark_fixed', Finding::class, $id, (int) $f['entities_id'], ['status' => $f['status']], ['status' => Finding::PENDING_VERIFICATION], $note);
    }

    /** @return array<string,mixed> */
    public static function loadForAction(int $id): array
    {
        $f = Db::row(Finding::getTable(), ['id' => $id]);
        if (!$f || !Session::haveAccessToEntity((int) $f['entities_id'])) {
            throw new InvalidArgumentException(__('Bulgu bulunamadı.', 'inventoryquality'));
        }
        return $f;
    }

    public static function isAssignee(array $f, int $uid): bool
    {
        if ($uid <= 0) {
            return false;
        }
        if ((int) $f['users_id_assign'] === $uid) {
            return true;
        }
        $g = (int) $f['groups_id_assign'];
        return $g > 0 && countElementsInTable('glpi_groups_users', ['groups_id' => $g, 'users_id' => $uid]) > 0;
    }
}
