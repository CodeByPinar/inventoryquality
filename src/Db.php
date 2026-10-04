<?php

namespace GlpiPlugin\Inventoryquality;

/**
 * Küçük veritabanı yardımcıları: benzersizlik kısıtına dayanan güvenli ekleme / güncelleme.
 * Değerler GLPI'nin sorgu oluşturucusuyla tırnaklanır (buildInsert / quoteValue); serbest SQL yoktur.
 */
final class Db
{
    public static function now(): string
    {
        return date('Y-m-d H:i:s', self::time());
    }

    /** Test edilebilir saat (testlerde sabitlenebilir). */
    public static ?int $fixedTime = null;

    public static function time(): int
    {
        return self::$fixedTime ?? time();
    }

    /**
     * INSERT IGNORE — benzersiz anahtar çakışırsa satır eklenmez.
     * @param array<string,mixed> $params
     * @return int eklenen satırın kimliği; çakışmada 0
     */
    public static function insertIgnore(string $table, array $params): int
    {
        global $DB;
        $sql = preg_replace('/^INSERT INTO /', 'INSERT IGNORE INTO ', $DB->buildInsert($table, $params), 1);
        $DB->doQuery($sql);
        return $DB->affectedRows() > 0 ? (int) $DB->insertId() : 0;
    }

    /**
     * INSERT … ON DUPLICATE KEY UPDATE (atomik upsert).
     * @param array<string,mixed> $params
     * @param list<string> $update güncellenecek kolonlar
     */
    public static function upsert(string $table, array $params, array $update): void
    {
        global $DB;
        $sql = $DB->buildInsert($table, $params);
        $set = [];
        foreach ($update as $col) {
            $q = $DB::quoteName($col);
            $set[] = "$q = VALUES($q)";
        }
        $DB->doQuery($sql . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $set));
    }

    /** @return array<string,mixed>|null */
    public static function row(string $table, array $where): ?array
    {
        global $DB;
        $r = $DB->request(['FROM' => $table, 'WHERE' => $where, 'LIMIT' => 1])->current();
        return $r ?: null;
    }

    public static function json(mixed $v): string
    {
        return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<mixed> */
    public static function decode(?string $s): array
    {
        $d = json_decode((string) $s, true);
        return is_array($d) ? $d : [];
    }
}
