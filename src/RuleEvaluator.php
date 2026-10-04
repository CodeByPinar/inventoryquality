<?php

namespace GlpiPlugin\Inventoryquality;

/**
 * Kural değerlendirici — SAF: verilen normalize veri ve kural tanımından sonuç üretir; veritabanına ve varlığa
 * dokunmaz.
 *
 * Sonuçlar:
 *   PASS            koşul sağlandı
 *   FAIL            koşul sağlanmadı (bulgu açar / günceller)
 *   UNKNOWN         gerekli alan okunamadı — teknik hata verinin uygun olduğu anlamına GELMEZ, bulgu da çözülmez
 *   NOT_APPLICABLE  önkoşul sağlanmadı (ör. durum "Kullanımda" değil) — puana girmez
 *
 * Boşluk kuralı veri tipine göredir: yalnız boşluklardan oluşan metin boştur; "0" metni, sayısal 0 ve false
 * geçerli değerdir; açılır listede 0 "seçilmemiş" demektir; boş çoklu seçim ayrı (boş liste) değerlendirilir.
 */
final class RuleEvaluator
{
    public const PASS    = 'PASS';
    public const FAIL    = 'FAIL';
    public const UNKNOWN = 'UNKNOWN';
    public const NA      = 'NOT_APPLICABLE';

    public const RESULTS = [self::PASS, self::FAIL, self::UNKNOWN, self::NA];

    /**
     * @param array<string,mixed> $def  normalize kural tanımı (precondition?, assert)
     * @param array<string,mixed> $data alan anahtarı → normalize değer (AssetAdapter)
     * @return array{result:string,reason:string,observed:array<string,string>,error:string}
     */
    public static function evaluate(array $def, array $data, ?int $now = null): array
    {
        $now ??= time();
        $observed = [];
        foreach (self::keys($def) as $k) {
            $observed[$k] = self::summarize($data[$k] ?? null);
        }
        $pre = $def['precondition'] ?? null;
        if (is_array($pre) && $pre) {
            $t = self::test($pre, $data, $now);
            if ($t === null) {
                return ['result' => self::UNKNOWN, 'reason' => 'precondition_unreadable', 'observed' => $observed, 'error' => self::errorOf($data[$pre['field']] ?? null)];
            }
            if ($t === false) {
                return ['result' => self::NA, 'reason' => 'precondition_false', 'observed' => $observed, 'error' => ''];
            }
        }
        $as = $def['assert'] ?? null;
        if (!is_array($as) || !$as) {
            return ['result' => self::UNKNOWN, 'reason' => 'definition_invalid', 'observed' => $observed, 'error' => 'definition_invalid'];
        }
        $t = self::test($as, $data, $now);
        if ($t === null) {
            return ['result' => self::UNKNOWN, 'reason' => 'field_unreadable', 'observed' => $observed, 'error' => self::errorOf($data[$as['field']] ?? null)];
        }
        return ['result' => $t ? self::PASS : self::FAIL, 'reason' => $t ? 'passed' : 'assert_failed', 'observed' => $observed, 'error' => ''];
    }

    /** @return list<string> tanımda geçen alan anahtarları */
    public static function keys(array $def): array
    {
        $k = [];
        foreach (['precondition', 'assert'] as $p) {
            if (!empty($def[$p]['field'])) {
                $k[] = (string) $def[$p]['field'];
            }
        }
        return array_values(array_unique($k));
    }

    /**
     * @param array<string,mixed> $cond ['field','op','values'?,'days'?]
     * @return bool|null null = okunamadı
     */
    public static function test(array $cond, array $data, int $now): ?bool
    {
        $v = $data[$cond['field'] ?? ''] ?? null;
        if (!is_array($v) || empty($v['ok'])) {
            return null;
        }
        $op = (string) ($cond['op'] ?? '');
        $values = array_values((array) ($cond['values'] ?? []));
        $empty = self::isEmpty($v);
        switch ($op) {
            case 'empty':
                return $empty;
            case 'not_empty':
                return !$empty;
            case 'eq':
                return !$empty && $values && self::same($v, $values[0]);
            case 'neq':
                return $empty || !$values || !self::same($v, $values[0]);
            case 'in':
                if ($empty) {
                    return false;
                }
                foreach ($values as $x) {
                    if (self::same($v, $x)) {
                        return true;
                    }
                }
                return false;
            case 'not_in':
                if ($empty) {
                    return true;
                }
                foreach ($values as $x) {
                    if (self::same($v, $x)) {
                        return false;
                    }
                }
                return true;
            case 'ref_exists':
                return !$empty && !empty($v['ref']['exists']);
            case 'ref_active':
                return !$empty && !empty($v['ref']['exists']) && ($v['ref']['active'] ?? null) === true;
            case 'older_than_days':
            case 'within_days':
                if ($empty) {
                    return false; // tarihi olmayan kayıt "yakın zamanda görüldü" sayılmaz
                }
                $ts = strtotime((string) $v['value']);
                if ($ts === false) {
                    return null;
                }
                $age = ($now - $ts) / 86400;
                $days = (float) ($cond['days'] ?? 0);
                return $op === 'older_than_days' ? $age > $days : $age <= $days;
        }
        return null;
    }

    public static function isEmpty(array $v): bool
    {
        $x = $v['value'] ?? null;
        return match ($v['type'] ?? '') {
            'fk', 'user' => (int) $x <= 0,
            'multi'      => !is_array($x) || count($x) === 0,
            'bool', 'int' => $x === null,
            'date'       => $x === null || trim((string) $x) === '' || str_starts_with((string) $x, '0000-00-00'),
            default      => $x === null || trim((string) $x) === '',
        };
    }

    private static function same(array $v, mixed $x): bool
    {
        return match ($v['type'] ?? '') {
            'fk', 'user', 'int' => (int) $v['value'] === (int) $x,
            'bool'              => (bool) $v['value'] === (bool) (int) $x,
            default             => trim((string) $v['value']) === trim((string) $x),
        };
    }

    private static function errorOf(mixed $v): string
    {
        return is_array($v) && empty($v['ok']) ? (string) ($v['error'] ?? 'unreadable') : 'unreadable';
    }

    /** Mevcut durumun gerekli ve maskelenmiş özeti (destek kaydı ve bulgu ekranında). */
    public static function summarize(mixed $v): string
    {
        if (!is_array($v)) {
            return __('(okunamadı)', 'inventoryquality');
        }
        if (empty($v['ok'])) {
            return sprintf(__('(okunamadı: %s)', 'inventoryquality'), (string) ($v['error'] ?? ''));
        }
        if (self::isEmpty($v)) {
            return __('(boş)', 'inventoryquality');
        }
        switch ($v['type']) {
            case 'fk':
            case 'user':
                $ref = $v['ref'] ?? [];
                if (empty($ref['exists'])) {
                    return sprintf(__('#%d (kayıt bulunamadı)', 'inventoryquality'), (int) $v['value']);
                }
                $s = (string) ($ref['label'] ?? ('#' . (int) $v['value']));
                if (($ref['active'] ?? null) === false) {
                    $s .= ' ' . __('(pasif)', 'inventoryquality');
                }
                return $s;
            case 'bool':
                return $v['value'] ? __('Evet', 'inventoryquality') : __('Hayır', 'inventoryquality');
            case 'multi':
                return sprintf(_n('%d kayıt', '%d kayıt', count($v['value']), 'inventoryquality'), count($v['value']));
            case 'text':
                return __('(dolu)', 'inventoryquality');
            default:
                $s = trim((string) $v['value']);
                return mb_strlen($s) > 60 ? mb_substr($s, 0, 57) . '…' : $s;
        }
    }
}
