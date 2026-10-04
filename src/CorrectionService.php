<?php

namespace GlpiPlugin\Inventoryquality;

use InvalidArgumentException;
use Session;

/**
 * Düzeltme önerisi ve onay akışı.
 *
 *  - Öneri yalnız bu tip için izin verilmiş (katalogda yazılabilir) alanlarda, veri tipine uygun değerle verilir;
 *    gerekçe zorunlu, kanıt eki isteğe bağlı. Öneri anında hedef alanların mevcut değerleri, varlığın birimi ve kural
 *    sürümü ANLIK GÖRÜNTÜ olarak saklanır.
 *  - Onay politikası kuraldan gelir; öneren kişi gevşetemez. Onaylayanlar talep açılırken etkin politikadan belirlenir.
 *    İlk onay bitmeden ikinci adım açılmaz. "Gruptan bir yetkili yeterli" (any) ile "belirlenmiş herkes" (all)
 *    açıkça ayrıdır. Kendi talebini onaylama varsayılan olarak kapalıdır.
 *  - Ret: öneri reddedilir, envanter değişmez, kalite bulgusu açık kalır. Onaylayan bulunamaması ya da pasifleşmesi
 *    işi "İnceleme gerekli"ye taşır; onay otomatik verilmez. Yetkili yeniden atama geçmişe yazılır.
 *  - Onaylar tamamlanınca (ya da onaysız politikada yetkili kişi uygulayınca) CorrectionProcessor uygular. Bulgu yine
 *    ancak yeniden kontrol PASS verince çözülür — onay, verinin doğru olduğunun kanıtı değildir.
 */
final class CorrectionService
{
    public const TABLE = Install::P . 'corrections';
    public const APPROVALS = Install::P . 'approvals';

    public const PENDING_APPROVAL = 'pending_approval';
    public const APPROVED         = 'approved';
    public const PROCESSING       = 'processing';
    public const APPLIED          = 'applied';
    public const REJECTED         = 'rejected';
    public const CONFLICT         = 'conflict';
    public const FAILED           = 'failed';
    public const REVIEW           = 'review';
    public const CANCELLED        = 'cancelled';

    public const OPEN = [self::PENDING_APPROVAL, self::APPROVED, self::REVIEW, self::FAILED];

    /** @return array<string,string> */
    public static function statuses(): array
    {
        return [
            self::PENDING_APPROVAL => __('Onay bekliyor', 'inventoryquality'),
            self::APPROVED         => __('Uygulanmayı bekliyor', 'inventoryquality'),
            self::PROCESSING       => __('Uygulanıyor', 'inventoryquality'),
            self::APPLIED          => __('Uygulandı (doğrulama bekliyor)', 'inventoryquality'),
            self::REJECTED         => __('Reddedildi', 'inventoryquality'),
            self::CONFLICT         => __('Çakışma (yeni öneri gerekir)', 'inventoryquality'),
            self::FAILED           => __('Uygulanamadı', 'inventoryquality'),
            self::REVIEW           => __('İnceleme gerekli', 'inventoryquality'),
            self::CANCELLED        => __('İptal edildi', 'inventoryquality'),
        ];
    }

    public static function statusLabel(string $s): string
    {
        return self::statuses()[$s] ?? $s;
    }

    /**
     * Bulgu için önerilebilecek alanlar (katalogda yazılabilir çekirdek alan).
     * @return array<string,array<string,mixed>>
     */
    public static function targetsFor(array $finding, array $def): array
    {
        $tpl = (string) ($def['template'] ?? '');
        if (!RuleTemplates::allowsCorrection($tpl)) {
            return [];
        }
        $keys = $tpl === 'responsible' ? ['core:users_id_tech'] : [(string) ($def['target'] ?? '')];
        $out = [];
        foreach ($keys as $k) {
            $d = Catalog::get((string) $finding['itemtype'], $k);
            if ($d && $d['writable'] && $d['column'] !== '') {
                $out[$k] = $d;
            }
        }
        return $out;
    }

    /**
     * Önerilen değeri veri tipine göre doğrular.
     * @return array{raw:int|string,label:string}
     */
    public static function normalizeValue(array $fieldDef, mixed $value): array
    {
        switch ($fieldDef['datatype']) {
            case 'fk':
            case 'user':
                $id = (int) $value;
                $info = $id > 0 ? (AssetAdapter::refInfo($fieldDef['ref_itemtype'], [$id])[$id] ?? null) : null;
                if (!$info || !$info['exists'] || ($fieldDef['datatype'] === 'user' && $info['active'] !== true)) {
                    throw new InvalidArgumentException($fieldDef['datatype'] === 'user'
                        ? __('Önerilen kullanıcı bulunamadı ya da aktif değil.', 'inventoryquality')
                        : __('Önerilen değer bulunamadı; listeden geçerli bir kayıt seçin.', 'inventoryquality'));
                }
                return ['raw' => $id, 'label' => (string) $info['label']];
            case 'string':
            case 'text':
                $s = trim((string) $value);
                $max = $fieldDef['datatype'] === 'string' ? 255 : 65535;
                if ($s === '' || mb_strlen($s) > $max) {
                    throw new InvalidArgumentException(sprintf(__('Önerilen değer boş olamaz ve en fazla %d karakter olabilir.', 'inventoryquality'), $max));
                }
                return ['raw' => $s, 'label' => mb_strlen($s) > 60 ? mb_substr($s, 0, 57) . '…' : $s];
        }
        throw new InvalidArgumentException(__('Bu alan için düzeltme önerilemez.', 'inventoryquality'));
    }

    /**
     * Düzeltme önerir. Onaysız politikada ve kullanıcının uygulama yetkisi varsa hemen uygular.
     * @param array<string,mixed>|null $evidence Evidence::store çıktısı
     * @return array{id:int,status:string}
     */
    public static function propose(int $findingsId, string $field, mixed $value, string $reason, ?array $evidence = null): array
    {
        global $DB;
        if (!Rights::has(Rights::PROPOSE)) {
            throw new InvalidArgumentException(__('Düzeltme önerme yetkiniz yok.', 'inventoryquality'));
        }
        $f = FindingService::loadForAction($findingsId);
        if (!in_array($f['status'], Finding::ACTIVE, true)) {
            throw new InvalidArgumentException(__('Yalnız aktif bulgu için düzeltme önerilir.', 'inventoryquality'));
        }
        $rule = Db::row(Rule::getTable(), ['id' => (int) $f['rules_id']]);
        $v = $rule ? Rule::version((int) $rule['ruleversions_id']) : null;
        if (!$v) {
            throw new InvalidArgumentException(__('Kural bulunamadı.', 'inventoryquality'));
        }
        $targets = self::targetsFor($f, $v['def']);
        if (!isset($targets[$field])) {
            throw new InvalidArgumentException(__('Bu bulgu için bu alana düzeltme önerilemez.', 'inventoryquality'));
        }
        if (countElementsInTable(self::TABLE, ['findings_id' => $findingsId, 'status' => [self::PENDING_APPROVAL, self::APPROVED, self::PROCESSING, self::REVIEW]]) > 0) {
            throw new InvalidArgumentException(__('Bu bulgu için sonuçlanmamış bir düzeltme önerisi var.', 'inventoryquality'));
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new InvalidArgumentException(__('Düzeltme gerekçesi zorunludur.', 'inventoryquality'));
        }
        $new = self::normalizeValue($targets[$field], $value);
        $cur = AssetAdapter::readOne((string) $f['itemtype'], (int) $f['items_id'], [$field]);
        if ($cur === null || empty($cur[$field]['ok'])) {
            throw new InvalidArgumentException(__('Varlığın güncel değeri okunamadı.', 'inventoryquality'));
        }
        $oldRaw = $cur[$field]['value'];
        if ((string) $oldRaw === (string) $new['raw']) {
            throw new InvalidArgumentException(__('Önerilen değer mevcut değerle aynı.', 'inventoryquality'));
        }
        $policy = $v['def']['policy']['approval'] ?? ['level' => 'none', 'steps' => [], 'allow_self' => false];
        if (!in_array($policy['level'] ?? 'none', ['one', 'two'], true) || empty($policy['steps'])) {
            $policy = ['level' => 'none', 'steps' => [], 'allow_self' => (bool) ($policy['allow_self'] ?? false)];
        }
        $uid = (int) Session::getLoginUserID();
        $now = Db::now();
        $changes = [$field => ['old' => $oldRaw, 'new' => $new['raw'], 'old_label' => RuleEvaluator::summarize($cur[$field]), 'new_label' => $new['label'], 'datatype' => $targets[$field]['datatype']]];
        $DB->insert(self::TABLE, [
            'op_key' => bin2hex(random_bytes(32)), 'findings_id' => $findingsId, 'entities_id' => (int) $f['entities_id'],
            'itemtype' => (string) $f['itemtype'], 'items_id' => (int) $f['items_id'], 'rules_id' => (int) $f['rules_id'],
            'ruleversions_id' => (int) $v['id'], 'changes' => Db::json($changes),
            'snapshot' => Db::json(['fields' => [$field => $oldRaw], 'entities_id' => (int) $cur['_meta']['entities_id'], 'ruleversions_id' => (int) $v['id'], 'read_at' => $now]),
            'policy' => Db::json($policy), 'reason' => mb_substr($reason, 0, 4000),
            'evidence_file' => $evidence['file'] ?? '', 'evidence_name' => $evidence['name'] ?? '', 'evidence_mime' => $evidence['mime'] ?? '', 'evidence_size' => (int) ($evidence['size'] ?? 0),
            'status' => ($policy['level'] ?? 'none') === 'none' ? self::APPROVED : self::PENDING_APPROVAL,
            'users_id' => $uid, 'date_creation' => $now, 'date_mod' => $now,
        ]);
        $id = (int) $DB->insertId();
        AuditLog::write('correction_propose', self::class, $id, (int) $f['entities_id'], ['finding' => $findingsId], ['changes' => $changes, 'policy' => $policy['level'] ?? 'none'], $reason);

        if (($policy['level'] ?? 'none') !== 'none') {
            $ok = self::createApprovals($id, $policy, $uid);
            if (!$ok) {
                $DB->update(self::TABLE, ['status' => self::REVIEW, 'result_message' => __('Onaylayan bulunamadı (grup boş, kişi pasif ya da yalnız öneren var).', 'inventoryquality'), 'date_mod' => Db::now()], ['id' => $id]);
                FindingService::setStatus($findingsId, Finding::REVIEW, __('Düzeltme için onaylayan bulunamadı', 'inventoryquality'));
                AuditLog::write('correction_review', self::class, $id, (int) $f['entities_id'], null, null, 'no_approver');
                return ['id' => $id, 'status' => self::REVIEW];
            }
            FindingService::setStatus($findingsId, Finding::PENDING_APPROVAL, __('Düzeltme önerisi onay bekliyor', 'inventoryquality'));
            TicketBridge::note($findingsId, sprintf(__('Düzeltme önerildi (#%d): %s → %s. Onay bekliyor.', 'inventoryquality'), $id, $changes[$field]['old_label'], $new['label']));
            return ['id' => $id, 'status' => self::PENDING_APPROVAL];
        }
        if (self::canApply(Db::row(self::TABLE, ['id' => $id]))) {
            return ['id' => $id, 'status' => CorrectionProcessor::process($id)];
        }
        return ['id' => $id, 'status' => self::APPROVED];
    }

    /**
     * Onay satırlarını politikadan oluşturur: 1. adım "waiting", 2. adım "pending" (1. adım bitince açılır).
     * @return bool her adımda en az bir uygun onaylayan var mı
     */
    private static function createApprovals(int $corrId, array $policy, int $requester): bool
    {
        global $DB;
        $rows = [];
        foreach ((array) ($policy['steps'] ?? []) as $step => $def) {
            $r = self::resolveStep((array) $def, $requester, (bool) ($policy['allow_self'] ?? false));
            if (!$r) {
                return false;
            }
            $rows[(int) $step] = $r;
        }
        $now = Db::now();
        foreach ($rows as $step => $list) {
            foreach ($list as $t) {
                $DB->insert(self::APPROVALS, [
                    'corrections_id' => $corrId, 'step' => $step, 'target_type' => $t['type'], 'target_id' => $t['id'], 'mode' => $t['mode'],
                    'status' => $step === 1 ? 'waiting' : 'pending', 'date_creation' => $now,
                ]);
            }
        }
        return true;
    }

    /**
     * Bir adımın onaylayanları. any: grup (en az bir uygun aktif üye) ve/veya kişi — biri yeterli.
     * all: grubun aktif üyeleri + kişi — her biri onaylamalı. Öneren, allow_self yoksa hariç.
     * @return list<array{type:string,id:int,mode:string}>
     */
    public static function resolveStep(array $def, int $requester, bool $allowSelf): array
    {
        global $DB;
        $mode = ($def['mode'] ?? 'any') === 'all' ? 'all' : 'any';
        $out = [];
        $u = (int) ($def['users_id'] ?? 0);
        $g = (int) ($def['groups_id'] ?? 0);
        $members = [];
        if ($g > 0 && Db::row('glpi_groups', ['id' => $g])) {
            foreach ($DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_groups_users', 'WHERE' => ['groups_id' => $g]]) as $m) {
                $mid = (int) $m['users_id'];
                if (($allowSelf || $mid !== $requester) && (AssetAdapter::refInfo('User', [$mid])[$mid]['active'] ?? false)) {
                    $members[] = $mid;
                }
            }
        }
        $userOk = $u > 0 && ($allowSelf || $u !== $requester) && (AssetAdapter::refInfo('User', [$u])[$u]['active'] ?? false);
        if ($mode === 'any') {
            if ($members) {
                $out[] = ['type' => 'Group', 'id' => $g, 'mode' => 'any'];
            }
            if ($userOk) {
                $out[] = ['type' => 'User', 'id' => $u, 'mode' => 'any'];
            }
            return $out;
        }
        $ids = $members;
        if ($userOk) {
            $ids[] = $u;
        }
        foreach (array_values(array_unique($ids)) as $id) {
            $out[] = ['type' => 'User', 'id' => $id, 'mode' => 'all'];
        }
        // "Belirlenmiş herkes": grupta tanımlı ama pasif / öneren olduğu için dışarıda kalan biri varsa adım geçersiz değil;
        // tanımlı kişi pasifse (userOk false ama u > 0) adım eksik kalır → İnceleme gerekli.
        if ($u > 0 && !$userOk && !($u === $requester && !$allowSelf)) {
            return [];
        }
        return $out;
    }

    /** Kullanıcının bu düzeltmede karar verebileceği bekleyen onay satırı. @return array<string,mixed>|null */
    public static function decisionRow(array $corr, int $uid): ?array
    {
        global $DB;
        if ($corr['status'] !== self::PENDING_APPROVAL || $uid <= 0 || !Rights::has(Rights::APPROVE)) {
            return null;
        }
        $policy = Db::decode($corr['policy']);
        if ((int) $corr['users_id'] === $uid && empty($policy['allow_self'])) {
            return null;
        }
        $step = self::currentStep((int) $corr['id']);
        foreach ($DB->request(['FROM' => self::APPROVALS, 'WHERE' => ['corrections_id' => (int) $corr['id'], 'step' => $step, 'status' => 'waiting']]) as $r) {
            if ($r['target_type'] === 'User' && (int) $r['target_id'] === $uid) {
                return $r;
            }
            if ($r['target_type'] === 'Group' && countElementsInTable('glpi_groups_users', ['groups_id' => (int) $r['target_id'], 'users_id' => $uid]) > 0) {
                return $r;
            }
        }
        return null;
    }

    public static function currentStep(int $corrId): int
    {
        global $DB;
        $r = $DB->request(['SELECT' => ['MIN' => 'step AS s'], 'FROM' => self::APPROVALS, 'WHERE' => ['corrections_id' => $corrId, 'status' => 'waiting']])->current();
        return (int) ($r['s'] ?? 0);
    }

    /** Onay / ret kararı. @return string düzeltmenin yeni durumu */
    public static function decide(int $corrId, bool $approve, string $comment = ''): string
    {
        global $DB;
        $corr = self::load($corrId);
        $uid = (int) Session::getLoginUserID();
        $row = self::decisionRow($corr, $uid);
        if ($row === null) {
            throw new InvalidArgumentException(__('Bu düzeltmede bekleyen bir onay kararınız yok.', 'inventoryquality'));
        }
        $comment = trim($comment);
        $now = Db::now();
        if (!$approve) {
            if (mb_strlen($comment) < 3) {
                throw new InvalidArgumentException(__('Ret gerekçesi zorunludur.', 'inventoryquality'));
            }
            $DB->update(self::APPROVALS, ['status' => 'rejected', 'users_id' => $uid, 'comment' => $comment, 'decision_date' => $now], ['id' => (int) $row['id']]);
            $DB->update(self::APPROVALS, ['status' => 'void'], ['corrections_id' => $corrId, 'status' => ['waiting', 'pending']]);
            $DB->update(self::TABLE, ['status' => self::REJECTED, 'result_message' => mb_substr($comment, 0, 2000), 'date_mod' => $now], ['id' => $corrId]);
            FindingService::setStatus((int) $corr['findings_id'], Finding::OPEN, sprintf(__('Düzeltme reddedildi: %s', 'inventoryquality'), $comment), [Finding::PENDING_APPROVAL, Finding::REVIEW]);
            AuditLog::write('correction_reject', self::class, $corrId, (int) $corr['entities_id'], ['step' => (int) $row['step']], ['status' => self::REJECTED], $comment);
            TicketBridge::note((int) $corr['findings_id'], sprintf(__('Düzeltme önerisi #%d reddedildi; envanter değişmedi, bulgu açık: %s', 'inventoryquality'), $corrId, $comment));
            return self::REJECTED;
        }
        $DB->update(self::APPROVALS, ['status' => 'approved', 'users_id' => $uid, 'comment' => $comment, 'decision_date' => $now], ['id' => (int) $row['id']]);
        AuditLog::write('correction_approve', self::class, $corrId, (int) $corr['entities_id'], ['step' => (int) $row['step']], ['status' => 'approved'], $comment);
        $step = (int) $row['step'];
        if ($row['mode'] === 'any') {
            $DB->update(self::APPROVALS, ['status' => 'void'], ['corrections_id' => $corrId, 'step' => $step, 'status' => 'waiting']);
        }
        if (countElementsInTable(self::APPROVALS, ['corrections_id' => $corrId, 'step' => $step, 'status' => 'waiting']) > 0) {
            return self::PENDING_APPROVAL; // "belirlenmiş herkes": diğer onaylar bekleniyor
        }
        $next = $DB->request(['SELECT' => ['MIN' => 'step AS s'], 'FROM' => self::APPROVALS, 'WHERE' => ['corrections_id' => $corrId, 'status' => 'pending']])->current();
        if (!empty($next['s'])) {
            $DB->update(self::APPROVALS, ['status' => 'waiting'], ['corrections_id' => $corrId, 'step' => (int) $next['s'], 'status' => 'pending']);
            return self::PENDING_APPROVAL; // sıralı: 2. adım şimdi açıldı
        }
        $DB->update(self::TABLE, ['status' => self::APPROVED, 'date_mod' => $now], ['id' => $corrId, 'status' => self::PENDING_APPROVAL]);
        return (string) AuditLog::as('service:correction', static fn() => CorrectionProcessor::process($corrId));
    }

    /** Uygulama: onaysız politikada ya da başarısız denemeyi yeniden çalıştırmak için ("Uygula" / "Yeniden dene"). */
    public static function apply(int $corrId): string
    {
        global $DB;
        $corr = self::load($corrId);
        if (!self::canApply($corr)) {
            throw new InvalidArgumentException(__('Bu düzeltmeyi uygulama yetkiniz yok (eklentide "Düzeltme uygula" ve varlıkta güncelleme yetkisi gerekir).', 'inventoryquality'));
        }
        if ($corr['status'] === self::FAILED) {
            $DB->update(self::TABLE, ['status' => self::APPROVED, 'date_mod' => Db::now()], ['id' => $corrId, 'status' => self::FAILED]);
        } elseif ($corr['status'] !== self::APPROVED) {
            throw new InvalidArgumentException(__('Düzeltme uygulanmaya hazır değil.', 'inventoryquality'));
        }
        return CorrectionProcessor::process($corrId);
    }

    public static function canApply(?array $corr): bool
    {
        if (!$corr || !in_array($corr['status'], [self::APPROVED, self::FAILED], true) || !Rights::has(Rights::APPLY)) {
            return false;
        }
        $it = (string) $corr['itemtype'];
        if (!class_exists($it)) {
            return false;
        }
        $item = new $it();
        return $item->getFromDB((int) $corr['items_id']) && $item->canUpdateItem() && $it::canUpdate();
    }

    /** Onaylayanı yeniden ata (İş ata yetkisi): adımın bekleyen satırları geçersiz olur, yeni satırlar açılır. */
    public static function reassign(int $corrId, int $step, int $groupsId, int $usersId, string $mode): void
    {
        global $DB;
        if (!Rights::has(Rights::ASSIGN)) {
            throw new InvalidArgumentException(__('İş atama yetkiniz yok.', 'inventoryquality'));
        }
        $corr = self::load($corrId);
        if (!in_array($corr['status'], [self::PENDING_APPROVAL, self::REVIEW], true)) {
            throw new InvalidArgumentException(__('Yalnız onay bekleyen ya da incelemedeki düzeltmede onaylayan değiştirilir.', 'inventoryquality'));
        }
        $policy = Db::decode($corr['policy']);
        if (!isset($policy['steps'][$step])) {
            throw new InvalidArgumentException(__('Geçersiz onay adımı.', 'inventoryquality'));
        }
        $rows = self::resolveStep(['groups_id' => $groupsId, 'users_id' => $usersId, 'mode' => $mode], (int) $corr['users_id'], (bool) ($policy['allow_self'] ?? false));
        if (!$rows) {
            throw new InvalidArgumentException(__('Seçilen grupta / kişide uygun aktif onaylayan yok.', 'inventoryquality'));
        }
        $before = iterator_to_array($DB->request(['FROM' => self::APPROVALS, 'WHERE' => ['corrections_id' => $corrId, 'step' => $step, 'status' => ['waiting', 'pending']]]), false);
        $wasOpen = (bool) array_filter($before, static fn($r) => $r['status'] === 'waiting') || $step === 1 || countElementsInTable(self::APPROVALS, ['corrections_id' => $corrId, 'step' => $step - 1, 'status' => 'approved']) > 0;
        $DB->update(self::APPROVALS, ['status' => 'void'], ['corrections_id' => $corrId, 'step' => $step, 'status' => ['waiting', 'pending']]);
        foreach ($rows as $t) {
            $DB->insert(self::APPROVALS, ['corrections_id' => $corrId, 'step' => $step, 'target_type' => $t['type'], 'target_id' => $t['id'], 'mode' => $t['mode'],
                'status' => $wasOpen ? 'waiting' : 'pending', 'date_creation' => Db::now()]);
        }
        $DB->update(self::TABLE, ['status' => self::PENDING_APPROVAL, 'result_message' => null, 'date_mod' => Db::now()], ['id' => $corrId]);
        FindingService::setStatus((int) $corr['findings_id'], Finding::PENDING_APPROVAL, __('Düzeltme önerisi onay bekliyor', 'inventoryquality'), [Finding::REVIEW, Finding::PENDING_APPROVAL, Finding::OPEN, Finding::IN_PROGRESS]);
        AuditLog::write('approval_reassign', self::class, $corrId, (int) $corr['entities_id'],
            ['step' => $step, 'targets' => array_map(static fn($r) => $r['target_type'] . ':' . $r['target_id'], $before)],
            ['step' => $step, 'targets' => array_map(static fn($r) => $r['type'] . ':' . $r['id'], $rows), 'mode' => $mode]);
    }

    public static function cancel(int $corrId, string $reason = ''): void
    {
        global $DB;
        $corr = self::load($corrId);
        $uid = (int) Session::getLoginUserID();
        if ((int) $corr['users_id'] !== $uid && !Rights::has(Rights::ASSIGN)) {
            throw new InvalidArgumentException(__('Yalnız öneren ya da iş atama yetkilisi iptal edebilir.', 'inventoryquality'));
        }
        if (!in_array($corr['status'], self::OPEN, true)) {
            throw new InvalidArgumentException(__('Bu düzeltme iptal edilemez.', 'inventoryquality'));
        }
        self::finishOpen($corr, $reason !== '' ? $reason : __('Öneren / yetkili iptal etti', 'inventoryquality'));
        FindingService::setStatus((int) $corr['findings_id'], Finding::OPEN, __('Düzeltme önerisi iptal edildi', 'inventoryquality'), [Finding::PENDING_APPROVAL, Finding::REVIEW]);
    }

    /** Bulgu kapanınca (PASS / kapsam dışı) sonuçlanmamış öneriler iptal olur. */
    public static function voidOpenFor(int $findingsId, string $reason): void
    {
        global $DB;
        foreach (iterator_to_array($DB->request(['FROM' => self::TABLE, 'WHERE' => ['findings_id' => $findingsId, 'status' => self::OPEN]]), false) as $c) {
            self::finishOpen($c, sprintf(__('Bulgu kapandı: %s', 'inventoryquality'), $reason));
        }
    }

    private static function finishOpen(array $corr, string $reason): void
    {
        global $DB;
        $DB->update(self::TABLE, ['status' => self::CANCELLED, 'result_message' => mb_substr($reason, 0, 2000), 'date_mod' => Db::now()], ['id' => (int) $corr['id']]);
        $DB->update(self::APPROVALS, ['status' => 'void'], ['corrections_id' => (int) $corr['id'], 'status' => ['waiting', 'pending']]);
        AuditLog::write('correction_cancel', self::class, (int) $corr['id'], (int) $corr['entities_id'], ['status' => $corr['status']], ['status' => self::CANCELLED], $reason);
    }

    /**
     * Saatlik: bekleyen adımda onaylayanın pasifleşmesi / grubun boşalması → İnceleme gerekli (onay otomatik verilmez).
     * @return int incelemeye alınan sayı
     */
    public static function checkApprovers(): int
    {
        global $DB;
        $n = 0;
        foreach (iterator_to_array($DB->request(['FROM' => self::TABLE, 'WHERE' => ['status' => self::PENDING_APPROVAL]]), false) as $c) {
            $policy = Db::decode($c['policy']);
            $step = self::currentStep((int) $c['id']);
            $rows = iterator_to_array($DB->request(['FROM' => self::APPROVALS, 'WHERE' => ['corrections_id' => (int) $c['id'], 'step' => $step, 'status' => 'waiting']]), false);
            $bad = !$rows;
            foreach ($rows as $r) {
                if ($r['target_type'] === 'User') {
                    $bad = $bad || !(AssetAdapter::refInfo('User', [(int) $r['target_id']])[(int) $r['target_id']]['active'] ?? false);
                } elseif (!self::resolveStep(['groups_id' => (int) $r['target_id'], 'mode' => 'any'], (int) $c['users_id'], (bool) ($policy['allow_self'] ?? false))) {
                    $bad = true;
                }
            }
            if ($bad) {
                $DB->update(self::TABLE, ['status' => self::REVIEW, 'result_message' => __('Onaylayan pasifleşti ya da grupta uygun onaylayan kalmadı; yeniden atama gerekir.', 'inventoryquality'), 'date_mod' => Db::now()], ['id' => (int) $c['id']]);
                FindingService::setStatus((int) $c['findings_id'], Finding::REVIEW, __('Düzeltmenin onaylayanı bulunamıyor', 'inventoryquality'));
                AuditLog::write('correction_review', self::class, (int) $c['id'], (int) $c['entities_id'], null, null, 'approver_inactive');
                $n++;
            }
        }
        return $n;
    }

    /** @return array<string,mixed> */
    public static function load(int $corrId): array
    {
        $c = Db::row(self::TABLE, ['id' => $corrId]);
        if (!$c || !Session::haveAccessToEntity((int) $c['entities_id'])) {
            throw new InvalidArgumentException(__('Düzeltme bulunamadı.', 'inventoryquality'));
        }
        return $c;
    }

    /** @return list<array<string,mixed>> */
    public static function approvals(int $corrId): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::APPROVALS, 'WHERE' => ['corrections_id' => $corrId], 'ORDER' => ['step', 'id']]), false);
    }

    /** @return list<array<string,mixed>> kullanıcının karar verebileceği düzeltmeler (birim kısıtlı) */
    public static function waitingFor(int $uid): array
    {
        global $DB;
        $out = [];
        $crit = array_merge(['status' => self::PENDING_APPROVAL], getEntitiesRestrictCriteria(self::TABLE, 'entities_id', '', false));
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => $crit, 'ORDER' => 'id DESC', 'LIMIT' => 500]) as $c) {
            if (self::decisionRow($c, $uid) !== null) {
                $out[] = $c;
            }
        }
        return $out;
    }
}
