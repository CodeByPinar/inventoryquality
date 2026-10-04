<?php

namespace GlpiPlugin\Inventoryquality;

use Glpi\DBAL\QueryExpression;

/**
 * Tarama: kapsamı kimlik tabanlı sayfalamayla küçük partilere böler (varsayılan 200), kaldığı yeri saklar,
 * zaman bütçesiyle çalışır ve bir sonraki çalışmada devam eder. Aynı taramayı iki işçi aynı anda yürütmez (kilit).
 *
 * Tam tarama kaçırılmış olayları da yakalar. Tarama TAMAMLANINCA, bu taramada kapsamda bulunmayan varlık ×
 * kural değerlendirmeleri kaldırılır ve aktif bulguları gerekçeyle "Kapsam dışı" olur. Yarıda kalan taramada
 * değerlendirilmeyen bulgular topluca kapatılmaz.
 */
final class ScanRunner
{
    public const TABLE = Install::P . 'scanruns';
    public const STALE_HOURS = 6;

    /** Atama için her zaman okunan alanlar. */
    private const ASSIGN_KEYS = ['core:users_id_tech', 'rel:groups_tech'];

    /**
     * Yeni tarama başlatır (aynı türde/kural kümesinde çalışan varsa onu döndürür).
     * @param list<int>|null $ruleIds null = tüm etkin kurallar
     */
    public static function start(string $kind, ?array $ruleIds, string $actor): int
    {
        global $DB;
        self::markStale();
        $rules = Rule::activeRules(null, $ruleIds);
        $frozen = array_map(static fn($r) => ['rules_id' => (int) $r['id'], 'ruleversions_id' => (int) $r['v']['id']], $rules);
        $sig = Db::json(array_column($frozen, 'rules_id'));
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['status' => 'running', 'kind' => $kind]]) as $r) {
            if (Db::json(array_column(Db::decode($r['rules']), 'rules_id')) === $sig) {
                return (int) $r['id'];
            }
        }
        $now = Db::now();
        $DB->insert(self::TABLE, [
            'kind' => $kind, 'status' => $frozen ? 'running' : 'completed', 'rules' => Db::json($frozen),
            'cursor_itemtype' => '', 'cursor_id' => 0, 'actor' => $actor, 'users_id' => $actor === 'user' ? (int) \Session::getLoginUserID(false) : 0,
            'started_at' => $now, 'heartbeat_at' => $now, 'finished_at' => $frozen ? null : $now,
            'message' => $frozen ? '' : __('Etkin kural yok', 'inventoryquality'),
        ]);
        return (int) $DB->insertId();
    }

    /** Uzun süredir ilerlemeyen taramaları "Kısmi" işaretler (ör. eklenti devre dışıyken yarım kalan). */
    public static function markStale(): void
    {
        global $DB;
        $DB->update(self::TABLE, [
            'status' => 'partial', 'finished_at' => Db::now(),
            'message' => __('Tarama yarıda kaldı; değerlendirilmeyen bulgular kapatılmadı.', 'inventoryquality'),
        ], ['status' => 'running', 'heartbeat_at' => ['<', date('Y-m-d H:i:s', Db::time() - self::STALE_HOURS * 3600)]]);
    }

    /**
     * Çalışan taramaları bütçe içinde ilerletir.
     * @return array{slices:int,completed:int}
     */
    public static function tick(int $budgetSec): array
    {
        global $DB;
        self::markStale();
        $deadline = Db::time() + $budgetSec;
        $slices = 0;
        $completed = 0;
        foreach (iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => self::TABLE, 'WHERE' => ['status' => 'running'], 'ORDER' => 'id']), false) as $r) {
            if (Db::time() >= $deadline) {
                break;
            }
            $st = self::runSlice((int) $r['id'], $deadline);
            if ($st !== null) {
                $slices++;
                if ($st === 'completed') {
                    $completed++;
                }
            }
        }
        return ['slices' => $slices, 'completed' => $completed];
    }

    /** @return string|null yeni durum; kilit alınamazsa null */
    public static function runSlice(int $scanId, int $deadline): ?string
    {
        global $DB;
        $token = bin2hex(random_bytes(8));
        $now = Db::now();
        $DB->update(self::TABLE, ['locked_by' => $token, 'locked_until' => date('Y-m-d H:i:s', $deadline + 120)], [
            'id' => $scanId, 'status' => 'running',
            'OR' => [['locked_until' => null], ['locked_until' => ['<', $now]]],
        ]);
        if ($DB->affectedRows() !== 1) {
            return null;
        }
        $run = Db::row(self::TABLE, ['id' => $scanId]);
        try {
            $frozenIds = array_map('intval', array_column(Db::decode($run['rules']), 'rules_id'));
            $rules = Rule::activeRules(null, $frozenIds);
            $byType = [];
            foreach ($rules as $r) {
                $byType[$r['itemtype']][] = $r;
            }
            $types = array_values(array_filter(AssetAdapter::supportedTypes(), static fn($t) => isset($byType[$t])));
            $type = (string) $run['cursor_itemtype'];
            $cursor = (int) $run['cursor_id'];
            if ($type === '' || !in_array($type, $types, true)) {
                $type = $types[0] ?? '';
                $cursor = 0;
            }
            $batch = Config::get('batch_size');
            $cnt = ['PASS' => 0, 'FAIL' => 0, 'UNKNOWN' => 0, 'NOT_APPLICABLE' => 0, 'errors' => 0, 'items' => 0];
            $done = $type === '';
            while (!$done && Db::time() < $deadline) {
                $trs = $byType[$type];
                $entities = array_values(array_unique(array_merge(...array_map(static fn($r) => $r['entities'], $trs))));
                $ids = AssetAdapter::listIds($type, $entities, $cursor, $batch);
                if (!$ids) {
                    $i = array_search($type, $types, true);
                    if ($i === false || !isset($types[$i + 1])) {
                        $done = true;
                        break;
                    }
                    $type = $types[$i + 1];
                    $cursor = 0;
                    continue;
                }
                $c = self::evaluateItems($type, $ids, $trs, $scanId);
                foreach ($c as $k => $v) {
                    $cnt[$k] += $v;
                }
                $cursor = (int) end($ids);
                $DB->update(self::TABLE, ['cursor_itemtype' => $type, 'cursor_id' => $cursor, 'heartbeat_at' => Db::now()], ['id' => $scanId]);
            }
            $DB->update(self::TABLE, [
                'items_seen' => new QueryExpression('`items_seen` + ' . (int) $cnt['items']),
                'n_pass'     => new QueryExpression('`n_pass` + ' . (int) $cnt['PASS']),
                'n_fail'     => new QueryExpression('`n_fail` + ' . (int) $cnt['FAIL']),
                'n_unknown'  => new QueryExpression('`n_unknown` + ' . (int) $cnt['UNKNOWN']),
                'n_na'       => new QueryExpression('`n_na` + ' . (int) $cnt['NOT_APPLICABLE']),
                'n_errors'   => new QueryExpression('`n_errors` + ' . (int) $cnt['errors']),
                'heartbeat_at' => Db::now(),
            ], ['id' => $scanId]);
            if ($done) {
                self::reconcile($scanId, $frozenIds);
                $DB->update(self::TABLE, ['status' => 'completed', 'finished_at' => Db::now(), 'locked_by' => '', 'locked_until' => null], ['id' => $scanId]);
                return 'completed';
            }
            $DB->update(self::TABLE, ['locked_by' => '', 'locked_until' => null], ['id' => $scanId]);
            return 'running';
        } catch (\Throwable $e) {
            $DB->update(self::TABLE, [
                'status' => 'failed', 'finished_at' => Db::now(), 'locked_by' => '', 'locked_until' => null,
                'message' => mb_substr($e->getMessage(), 0, 2000),
            ], ['id' => $scanId]);
            return 'failed';
        }
    }

    /**
     * Bir parti varlığı verilen kurallarla değerlendirir.
     * @param list<int> $ids
     * @param list<array<string,mixed>> $rules aynı varlık tipindeki kurallar
     * @return array<string,int>
     */
    public static function evaluateItems(string $itemtype, array $ids, array $rules, int $scanId): array
    {
        global $DB;
        $cnt = ['PASS' => 0, 'FAIL' => 0, 'UNKNOWN' => 0, 'NOT_APPLICABLE' => 0, 'errors' => 0, 'items' => 0];
        $keys = self::ASSIGN_KEYS;
        foreach ($rules as $r) {
            $keys = array_merge($keys, RuleEvaluator::keys($r['v']['def']));
        }
        $data = AssetAdapter::readMany($itemtype, $ids, $keys);
        $had = [];
        foreach ($DB->request(['SELECT' => ['items_id', 'rules_id'], 'FROM' => Evaluation::TABLE, 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $ids, 'rules_id' => array_map(static fn($r) => (int) $r['id'], $rules)]]) as $e) {
            $had[(int) $e['items_id']][(int) $e['rules_id']] = true;
        }
        $now = Db::time();
        foreach ($ids as $id) {
            $asset = $data[$id] ?? null;
            if ($asset === null || $asset['_meta']['is_deleted'] || $asset['_meta']['is_template']) {
                continue; // tam tarama sonunda / olay kuyruğunda ele alınır
            }
            $cnt['items']++;
            $eid = (int) $asset['_meta']['entities_id'];
            $touched = self::closeOtherEntities($itemtype, $id, $eid) > 0;
            foreach ($rules as $r) {
                if (!self::inScope($r, $asset)) {
                    if (isset($had[$id][(int) $r['id']])) {
                        Evaluation::remove($itemtype, $id, (int) $r['id']);
                        $touched = FindingService::outOfScope($itemtype, $id, (int) $r['id'], __('Varlık kural kapsamından çıktı', 'inventoryquality')) > 0 || $touched;
                    }
                    continue;
                }
                try {
                    $res = RuleEvaluator::evaluate($r['v']['def'], $asset, $now);
                    Evaluation::store($itemtype, $id, $eid, $r, $res, $scanId);
                    $t = FindingService::apply($itemtype, $id, $eid, $r, $res, $asset);
                    $cnt[$res['result']]++;
                    if ($res['result'] === RuleEvaluator::UNKNOWN) {
                        $cnt['errors']++;
                    }
                    if ($t['transition'] !== 'none' && $t['transition'] !== 'seen') {
                        $touched = true;
                    }
                    if ($t['finding_id'] > 0 && $res['result'] === RuleEvaluator::FAIL) {
                        $touched = true; // destek kaydı yoksa oluşturulsun
                    }
                } catch (\Throwable $e) {
                    $cnt['errors']++;
                }
            }
            if ($touched) {
                JobQueue::enqueue('ticket_sync', "ticket_sync:$itemtype:$id", ['itemtype' => $itemtype, 'items_id' => $id]);
            }
        }
        return $cnt;
    }

    /** @param array<string,mixed> $rule */
    public static function inScope(array $rule, array $asset): bool
    {
        if (!in_array((int) $asset['_meta']['entities_id'], $rule['entities'], true)) {
            return false;
        }
        $states = array_map('intval', (array) ($rule['v']['def']['scope']['states'] ?? []));
        return !$states || in_array((int) $asset['_meta']['states_id'], $states, true);
    }

    /** Varlık başka birime taşındıysa eski birimdeki aktif bulgular kapsam dışına alınır (çözülmüş sayılmaz). */
    private static function closeOtherEntities(string $itemtype, int $id, int $eid): int
    {
        global $DB;
        $n = 0;
        foreach (iterator_to_array($DB->request(['SELECT' => ['rules_id'], 'FROM' => Finding::getTable(), 'WHERE' => [
            'itemtype' => $itemtype, 'items_id' => $id, 'entities_id' => ['<>', $eid], 'status' => array_merge(Finding::ACTIVE, [Finding::EXCEPTION]),
        ]]), false) as $f) {
            $n += FindingService::outOfScope($itemtype, $id, (int) $f['rules_id'], __('Varlık başka bir kurum birimine taşındı', 'inventoryquality'));
        }
        return $n;
    }

    /** Tamamlanan taramadan sonra: bu taramada kapsamda bulunmayan değerlendirme ve bulguları kapsam dışına al. */
    private static function reconcile(int $scanId, array $ruleIds): void
    {
        global $DB;
        if (!$ruleIds) {
            return;
        }
        $reason = __('Varlık son tam taramada kapsamda bulunamadı (silinmiş, şablon ya da kapsam dışı)', 'inventoryquality');
        $touched = [];
        $startedAt = (int) strtotime((string) (Db::row(self::TABLE, ['id' => $scanId])['started_at'] ?? ''));
        foreach (iterator_to_array($DB->request(['FROM' => Evaluation::TABLE, 'WHERE' => ['rules_id' => $ruleIds, 'scanruns_id' => ['<>', $scanId]]]), false) as $e) {
            // Tarama sürerken olay kuyruğu bu satırı yenilediyse (scanruns_id = 0, tarama başladıktan sonra) dokunma.
            if ((int) $e['scanruns_id'] === 0 && (int) strtotime((string) $e['evaluated_at']) >= $startedAt) {
                continue;
            }
            Evaluation::remove((string) $e['itemtype'], (int) $e['items_id'], (int) $e['rules_id']);
            if (FindingService::outOfScope((string) $e['itemtype'], (int) $e['items_id'], (int) $e['rules_id'], $reason) > 0) {
                $touched[$e['itemtype'] . ':' . $e['items_id']] = [(string) $e['itemtype'], (int) $e['items_id']];
            }
        }
        // Değerlendirmesi hiç kalmamış aktif bulgular.
        foreach (iterator_to_array($DB->request([
            'SELECT'    => [Finding::getTable() . '.itemtype', Finding::getTable() . '.items_id', Finding::getTable() . '.rules_id'],
            'FROM'      => Finding::getTable(),
            'LEFT JOIN' => [Evaluation::TABLE => ['ON' => [Evaluation::TABLE => 'items_id', Finding::getTable() => 'items_id', [
                'AND' => [Evaluation::TABLE . '.itemtype' => new QueryExpression($DB::quoteName(Finding::getTable() . '.itemtype')),
                          Evaluation::TABLE . '.rules_id' => new QueryExpression($DB::quoteName(Finding::getTable() . '.rules_id'))],
            ]]]],
            'WHERE'     => [Finding::getTable() . '.rules_id' => $ruleIds, Finding::getTable() . '.status' => array_merge(Finding::ACTIVE, [Finding::EXCEPTION]), Evaluation::TABLE . '.id' => null],
        ]), false) as $f) {
            if (FindingService::outOfScope((string) $f['itemtype'], (int) $f['items_id'], (int) $f['rules_id'], $reason) > 0) {
                $touched[$f['itemtype'] . ':' . $f['items_id']] = [(string) $f['itemtype'], (int) $f['items_id']];
            }
        }
        foreach ($touched as [$it, $id]) {
            JobQueue::enqueue('ticket_sync', "ticket_sync:$it:$id", ['itemtype' => $it, 'items_id' => $id]);
        }
    }

    /**
     * Tek varlığı tüm etkin kurallarla hemen yeniden değerlendirir (olay kuyruğu, "Yeniden kontrol et", "Düzelttim").
     * Varlık silinmiş / çöp kutusunda / şablonsa değerlendirmeleri kaldırılır, aktif bulgular kapsam dışına alınır.
     * @return array<int,string> rules_id → sonuç
     */
    public static function recheckItem(string $itemtype, int $id): array
    {
        if (!AssetAdapter::isSupported($itemtype)) {
            return [];
        }
        $rules = Rule::activeRules($itemtype);
        $keys = self::ASSIGN_KEYS;
        foreach ($rules as $r) {
            $keys = array_merge($keys, RuleEvaluator::keys($r['v']['def']));
        }
        $asset = AssetAdapter::readOne($itemtype, $id, $keys);
        if ($asset === null || $asset['_meta']['is_deleted'] || $asset['_meta']['is_template']) {
            Evaluation::remove($itemtype, $id);
            FindingService::outOfScope($itemtype, $id, null, $asset === null
                ? __('Varlık silindi', 'inventoryquality') : __('Varlık çöp kutusunda ya da şablon', 'inventoryquality'));
            return [];
        }
        $out = [];
        $before = Evaluation::forItem($itemtype, $id);
        self::evaluateItems($itemtype, [$id], $rules, 0);
        foreach (Evaluation::forItem($itemtype, $id) as $rid => $e) {
            $out[$rid] = (string) $e['result'];
        }
        // Artık etkin olmayan kuralların eski satırları.
        foreach (array_diff_key($before, array_flip(array_map(static fn($r) => (int) $r['id'], $rules))) as $rid => $_) {
            Evaluation::remove($itemtype, $id, (int) $rid);
        }
        return $out;
    }

    /**
     * Kural önizlemesi: kapsamdaki örnek kayıtlar üzerinde beklenen etki. Bulgu, değerlendirme ya da destek kaydı
     * ÜRETMEZ.
     * @param array<string,mixed> $rule ['itemtype','entities','v' => ['def']]
     * @return array{counts:array<string,int>,sample:list<array<string,mixed>>,scanned:int}
     */
    public static function preview(array $rule, int $limit): array
    {
        $it = (string) $rule['itemtype'];
        $ids = AssetAdapter::listIds($it, $rule['entities'], 0, $limit);
        $data = AssetAdapter::readMany($it, $ids, RuleEvaluator::keys($rule['v']['def']));
        $counts = ['PASS' => 0, 'FAIL' => 0, 'UNKNOWN' => 0, 'NOT_APPLICABLE' => 0, 'OUT' => 0];
        $sample = [];
        $now = Db::time();
        foreach ($ids as $id) {
            $a = $data[$id] ?? null;
            if (!$a) {
                continue;
            }
            if (!self::inScope($rule, $a)) {
                $counts['OUT']++;
                continue;
            }
            $res = RuleEvaluator::evaluate($rule['v']['def'], $a, $now);
            $counts[$res['result']]++;
            if (count($sample) < 25 && $res['result'] !== RuleEvaluator::PASS) {
                $sample[] = ['items_id' => $id, 'name' => $a['_meta']['name'], 'result' => $res['result'], 'observed' => $res['observed']];
            }
        }
        return ['counts' => $counts, 'sample' => $sample, 'scanned' => count($ids)];
    }

    /** @return list<array<string,mixed>> */
    public static function recent(int $limit = 20): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::TABLE, 'ORDER' => 'id DESC', 'LIMIT' => $limit]), false);
    }

    /** @return array<string,mixed>|null */
    public static function lastCompletedFull(): ?array
    {
        global $DB;
        $r = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['kind' => 'full', 'status' => 'completed'], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current();
        return $r ?: null;
    }
}
