<?php

namespace GlpiPlugin\Inventoryquality;

use InvalidArgumentException;

/**
 * Düzeltme kanıt eki: tür (içerikten, finfo), boyut ve erişim kontrolü. Dosya GLPI'nin eklenti belge dizininde
 * rastgele adla saklanır; yalnız düzeltmenin kurum birimine erişimi olan kullanıcıya, "attachment" olarak sunulur.
 */
final class Evidence
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** @var array<string,string> MIME → uzantı */
    public const TYPES = [
        'application/pdf' => 'pdf',
        'image/png'       => 'png',
        'image/jpeg'      => 'jpg',
        'text/plain'      => 'txt',
    ];

    public static function dir(): string
    {
        return GLPI_PLUGIN_DOC_DIR . '/inventoryquality/evidence';
    }

    /**
     * @param array<string,mixed> $file $_FILES girdisi
     * @param bool $trusted test: is_uploaded_file denetimini atla
     * @return array{file:string,name:string,mime:string,size:int}|null dosya yoksa null
     */
    public static function store(array $file, bool $trusted = false): ?array
    {
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE || ($file['tmp_name'] ?? '') === '') {
            return null;
        }
        if ($err !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(__('Kanıt dosyası yüklenemedi.', 'inventoryquality'));
        }
        $tmp = (string) $file['tmp_name'];
        if (!$trusted && !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException(__('Kanıt dosyası yüklenemedi.', 'inventoryquality'));
        }
        $size = (int) filesize($tmp);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new InvalidArgumentException(sprintf(__('Kanıt dosyası en fazla %d MB olabilir.', 'inventoryquality'), self::MAX_BYTES / 1048576));
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::TYPES[$mime])) {
            throw new InvalidArgumentException(__('Kanıt dosyası PDF, PNG, JPG ya da düz metin olmalı.', 'inventoryquality'));
        }
        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Kanıt dizini oluşturulamadı.');
        }
        $stored = bin2hex(random_bytes(16)) . '.' . self::TYPES[$mime];
        if (!($trusted ? copy($tmp, "$dir/$stored") : move_uploaded_file($tmp, "$dir/$stored"))) {
            throw new \RuntimeException('Kanıt dosyası kaydedilemedi.');
        }
        $name = preg_replace('/[^\p{L}\p{N} ._()-]+/u', '_', basename((string) ($file['name'] ?? 'kanit'))) ?: 'kanit';
        return ['file' => $stored, 'name' => mb_substr($name, 0, 200), 'mime' => $mime, 'size' => $size];
    }

    public static function path(string $stored): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}\.(pdf|png|jpg|txt)$/', $stored)) {
            return null;
        }
        $p = self::dir() . '/' . $stored;
        return is_file($p) ? $p : null;
    }

    /** Dosyayı indirme olarak gönderir (çağıran erişimi denetlemiş olmalı). */
    public static function send(array $corr): void
    {
        $p = self::path((string) $corr['evidence_file']);
        if ($p === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        $name = (string) $corr['evidence_name'];
        header('Content-Type: ' . (isset(self::TYPES[$corr['evidence_mime']]) ? $corr['evidence_mime'] : 'application/octet-stream'));
        header("Content-Disposition: attachment; filename=\"" . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . "\"; filename*=UTF-8''" . rawurlencode($name));
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($p));
        header('Cache-Control: private, no-store');
        readfile($p);
    }

    public static function purgeAll(): void
    {
        foreach ((array) glob(self::dir() . '/*') as $f) {
            if (is_file((string) $f)) {
                @unlink((string) $f);
            }
        }
        @rmdir(self::dir());
    }
}
