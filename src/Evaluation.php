<?php

namespace GlpiPlugin\Inventoryquality;

/**
 * Güncel değerlendirme sonuçları: varlık × kural başına TEK satır (son sonuç, kural sürümü, ağırlık, zaman,
 * hata kodu). Kalite puanı ve değerlendirme kapsamı buradan hesaplanır. Önkoşulu sağlanmayan (NOT_APPLICABLE)
 * sonuçlar da gerekçesiyle saklanır ama puana girmez. Kapsamdan çıkan varlık × kural satırı silinir.
 */
final class Evaluation
{
    public const TABLE = Install::P . 'evaluations';

    /**
     * @param array<string,mixed> $rule Rule::activeRules satırı
     * @param array<string,mixed> $res  RuleEvaluator::evaluate sonucu
     */
    public static function store(string $itemtype, int $itemsId, int $entitiesId, array $rule, array $res, int $scanId): void
    {
        Db::upsert(self::TABLE, [
            'itemtype'        => $itemtype,
            'items_id'        => $itemsId,
            'entities_id'     => $entitiesId,
            'rules_id'        => (int) $rule['id'],
            'ruleversions_id' => (int) $rule['v']['id'],
            'result'          => $res['result'],
            'weight'          => (int) $rule['v']['weight'],
            'detail'          => Db::json(['reason' => $res['reason'], 'observed' => $res['observed']]),
            'error_code'      => (string) $res['error'],
            'scanruns_id'     => $scanId,
            'evaluated_at'    => Db::now(),
        ], ['entities_id', 'ruleversions_id', 'result', 'weight', 'detail', 'error_code', 'scanruns_id', 'evaluated_at']);
    }

    public static function remove(string $itemtype, int $itemsId, ?int $rulesId = null): void
    {
        global $DB;
        $w = ['itemtype' => $itemtype, 'items_id' => $itemsId];
        if ($rulesId !== null) {
            $w['rules_id'] = $rulesId;
        }
        $DB->delete(self::TABLE, $w);
    }

    /** @return array<int,array<string,mixed>> rules_id → satır */
    public static function forItem(string $itemtype, int $itemsId): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $itemsId]]) as $r) {
            $out[(int) $r['rules_id']] = $r;
        }
        return $out;
    }
}
