<?php

namespace GlpiPlugin\Inventoryquality;

/**
 * Açıklanabilir puanlama — iki ölçüm birlikte gösterilir:
 *   Kalite puanı          = 100 × PASS ağırlığı / (PASS + FAIL ağırlığı)
 *   Değerlendirme kapsamı = 100 × (PASS + FAIL ağırlığı) / uygulanabilir kontrollerin toplam ağırlığı
 *                           (uygulanabilir = PASS + FAIL + UNKNOWN; NOT_APPLICABLE paydaya girmez)
 * Değerlendirilen ağırlık 0 → "Hesaplanamadı"; uygulanabilir kontrol yok → "Kapsam yok"; ikisi de %100 yapılmaz.
 * Kurum puanı ağırlıkların toplamından hesaplanır (varlık yüzdelerinin basit ortalaması değil). İstisna altındaki
 * FAIL, PASS sayılmaz; istisna sayısı ayrıca gösterilir.
 */
final class QualityCalculator
{
    /**
     * @return array{pass:int,fail:int,unknown:int,applicable:int,score:?float,coverage:?float}
     */
    public static function fromWeights(int $pass, int $fail, int $unknown): array
    {
        $evaluated = $pass + $fail;
        $applicable = $evaluated + $unknown;
        return [
            'pass' => $pass, 'fail' => $fail, 'unknown' => $unknown, 'applicable' => $applicable,
            'score'    => $evaluated > 0 ? round(100 * $pass / $evaluated, 1) : null,
            'coverage' => $applicable > 0 ? round(100 * $evaluated / $applicable, 1) : null,
        ];
    }

    /**
     * Güncel değerlendirmelerden puan + kapsam. Birim kısıtı: verilmezse oturumun etkin birimleri.
     * @param array<string,mixed> $where ek ölçüt (evaluations kolonları)
     * @param list<int>|null $entities
     * @return array<string,mixed>
     */
    public static function compute(array $where = [], ?array $entities = null): array
    {
        global $DB;
        $T = Evaluation::TABLE;
        $crit = $where + ['result' => ['<>', RuleEvaluator::NA]];
        $crit = array_merge($crit, self::entityCrit($T, $entities));
        $w = ['PASS' => 0, 'FAIL' => 0, 'UNKNOWN' => 0];
        $n = ['PASS' => 0, 'FAIL' => 0, 'UNKNOWN' => 0];
        foreach ($DB->request(['SELECT' => ['result', 'SUM' => 'weight AS w', 'COUNT' => 'id AS n'], 'FROM' => $T, 'WHERE' => $crit, 'GROUPBY' => 'result']) as $r) {
            if (isset($w[$r['result']])) {
                $w[$r['result']] = (int) $r['w'];
                $n[$r['result']] = (int) $r['n'];
            }
        }
        $res = self::fromWeights($w['PASS'], $w['FAIL'], $w['UNKNOWN']);
        $res['counts'] = $n;
        $res['low_coverage'] = $res['coverage'] !== null && $res['coverage'] < Config::get('low_coverage_pct');
        $last = $DB->request(['SELECT' => ['MAX' => 'evaluated_at AS t'], 'FROM' => $T, 'WHERE' => $crit])->current();
        $res['last_evaluated'] = $last['t'] ?? null;
        return $res;
    }

    /**
     * Kural bazında puan ve açık bulgu sayısı.
     * @return list<array<string,mixed>>
     */
    public static function byRule(?array $entities = null): array
    {
        global $DB;
        $T = Evaluation::TABLE;
        $rows = [];
        $crit = array_merge(['result' => ['<>', RuleEvaluator::NA]], self::entityCrit($T, $entities));
        foreach ($DB->request(['SELECT' => ['rules_id', 'result', 'SUM' => 'weight AS w', 'COUNT' => 'id AS n'], 'FROM' => $T, 'WHERE' => $crit, 'GROUPBY' => ['rules_id', 'result']]) as $r) {
            $rid = (int) $r['rules_id'];
            $rows[$rid]['w'][$r['result']] = (int) $r['w'];
            $rows[$rid]['n'][$r['result']] = (int) $r['n'];
        }
        $fcrit = array_merge(['status' => Finding::ACTIVE], self::entityCrit(Finding::getTable(), $entities));
        foreach ($DB->request(['SELECT' => ['rules_id', 'COUNT' => 'id AS n'], 'FROM' => Finding::getTable(), 'WHERE' => $fcrit, 'GROUPBY' => 'rules_id']) as $r) {
            $rows[(int) $r['rules_id']]['open'] = (int) $r['n'];
        }
        $out = [];
        foreach ($rows as $rid => $d) {
            $rule = Db::row(Rule::getTable(), ['id' => $rid]);
            if (!$rule) {
                continue;
            }
            $s = self::fromWeights((int) ($d['w']['PASS'] ?? 0), (int) ($d['w']['FAIL'] ?? 0), (int) ($d['w']['UNKNOWN'] ?? 0));
            $v = Rule::version((int) $rule['ruleversions_id']);
            $out[] = $s + ['rule' => $rule, 'version' => (int) ($v['version'] ?? 0), 'n' => $d['n'] ?? [], 'open' => (int) ($d['open'] ?? 0)];
        }
        usort($out, static fn($a, $b) => strcmp((string) $a['rule']['code'], (string) $b['rule']['code']));
        return $out;
    }

    /** @return array<string,int> */
    public static function findingCounts(?array $entities = null): array
    {
        global $DB;
        $T = Finding::getTable();
        $ent = self::entityCrit($T, $entities);
        $c = array_fill_keys(array_keys(Finding::statuses()), 0);
        foreach ($DB->request(['SELECT' => ['status', 'COUNT' => 'id AS n'], 'FROM' => $T, 'WHERE' => $ent ?: [], 'GROUPBY' => 'status']) as $r) {
            $c[(string) $r['status']] = (int) $r['n'];
        }
        $c['active'] = array_sum(array_intersect_key($c, array_flip(Finding::ACTIVE)));
        $c['overdue'] = (int) $DB->request(['SELECT' => ['COUNT' => 'id AS n'], 'FROM' => $T, 'WHERE' => array_merge(['status' => Finding::ACTIVE, 'due_date' => ['<', Db::now()]], $ent)])->current()['n'];
        $c['waiting'] = (int) $DB->request(['SELECT' => ['COUNT' => 'id AS n'], 'FROM' => $T, 'WHERE' => array_merge(['status' => Finding::ACTIVE, 'assign_state' => 'waiting'], $ent)])->current()['n'];
        $c['reopened'] = (int) $DB->request(['SELECT' => ['COUNT' => 'id AS n'], 'FROM' => $T, 'WHERE' => array_merge(['cycle' => ['>', 1]], $ent)])->current()['n'];
        return $c;
    }

    /**
     * Açık bulguların yaş dağılımı (gün): 0–7, 8–30, 31–90, 90+.
     * @return array<string,int>
     */
    public static function ageBuckets(?array $entities = null): array
    {
        global $DB;
        $b = ['0-7' => 0, '8-30' => 0, '31-90' => 0, '90+' => 0];
        $now = Db::time();
        foreach ($DB->request(['SELECT' => ['first_seen'], 'FROM' => Finding::getTable(), 'WHERE' => array_merge(['status' => Finding::ACTIVE], self::entityCrit(Finding::getTable(), $entities))]) as $r) {
            $d = ($now - (int) strtotime((string) $r['first_seen'])) / 86400;
            $k = $d <= 7 ? '0-7' : ($d <= 30 ? '8-30' : ($d <= 90 ? '31-90' : '90+'));
            $b[$k]++;
        }
        return $b;
    }

    /** @return array<string,mixed> */
    private static function entityCrit(string $table, ?array $entities): array
    {
        if ($entities !== null) {
            return [$table . '.entities_id' => $entities ?: [-1]];
        }
        return getEntitiesRestrictCriteria($table, 'entities_id', '', false);
    }

    /** Türkçe yüzde biçimi: "%70", "%83,3"; null için açıklama. */
    public static function pct(?float $v, string $nullText): string
    {
        if ($v === null) {
            return $nullText;
        }
        $s = number_format($v, 1, ',', '.');
        return '%' . (str_ends_with($s, ',0') ? substr($s, 0, -2) : $s);
    }
}
