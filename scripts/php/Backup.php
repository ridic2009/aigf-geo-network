<?php
declare(strict_types=1);
namespace AiGf\Tools;

/**
 * Off-site backup of the editable sources.
 *
 * Git is the history and the second copy of everything text — but not of the
 * images, and not of a repository that becomes unreachable. This packs
 * `content/`, `config/`, `data/` and `static/` into one verifiable archive,
 * encrypts it and ships it to another host.
 *
 * Archive layout, in order:
 *
 *   MCMSBK1\n
 *   <json header>\n            salt, nonce and chunk size, in the clear
 *   [4-byte BE length][16-byte GCM tag][ciphertext]   repeated
 *   [4-byte zero length][16-byte tag]                 terminator
 *
 * Chunked AES-256-GCM rather than one big block: a workspace with images does
 * not fit in memory, and a per-chunk tag means a truncated or edited file is
 * rejected at the first damaged chunk instead of after a full read. The
 * terminator is authenticated too, so cutting the file short is detected.
 *
 * Keys come from .secrets/backup.key, which is generated on first use and is
 * NOT in the archive. An archive without its key is unrecoverable — that is
 * the point, and it is why create() prints the fingerprint every time.
 */
final class Backup
{
    private const MAGIC = 'MCMSBK1';
    private const CHUNK = 1048576;
    private const SOURCES = ['content', 'config', 'data', 'static'];

    /** Archives, restores and recovered trees; never part of a build. */
    public static function dir(): string
    {
        return getenv('MINICMS_BACKUP_DIR') ?: Network::root() . '/.backups';
    }

    public static function keyFile(): string { return Network::root() . '/.secrets/backup.key'; }

    /** The 32-byte master key, created on first use. */
    public static function key(): string
    {
        $env = getenv('MINICMS_BACKUP_KEY');
        if (is_string($env) && $env !== '') {
            $raw = base64_decode($env, true);
            if ($raw === false || strlen($raw) !== 32) { throw new \RuntimeException('MINICMS_BACKUP_KEY: нужен base64 от 32 байт.'); }
            return $raw;
        }
        $file = self::keyFile();
        if (is_file($file)) {
            $raw = base64_decode(trim((string) file_get_contents($file)), true);
            if ($raw === false || strlen($raw) !== 32) { throw new \RuntimeException('Файл ключа повреждён: ' . $file); }
            return $raw;
        }
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0700, true) && !is_dir(dirname($file))) {
            throw new \RuntimeException('Не удалось создать каталог .secrets.');
        }
        $raw = random_bytes(32);
        if (file_put_contents($file, base64_encode($raw) . "\n") === false) { throw new \RuntimeException('Не удалось записать ключ.'); }
        chmod($file, 0600);
        return $raw;
    }

    /** Short, safe identifier of the key, so two hosts can be compared without revealing it. */
    public static function fingerprint(): string
    {
        return substr(hash('sha256', 'minicms-backup-fingerprint' . self::key()), 0, 16);
    }

    /**
     * Builds an encrypted archive and returns its path plus a summary.
     *
     * @return array{file:string,bytes:int,files:int,sha256:string,fingerprint:string}
     */
    public static function create(?string $directory = null): array
    {
        if (!class_exists(\ZipArchive::class)) { throw new \RuntimeException('Нужно расширение PHP zip.'); }
        $directory ??= self::dir();
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) { throw new \RuntimeException('Не удалось создать каталог бэкапов.'); }

        $name = 'minicms-' . gmdate('Ymd-His') . '.mcbk';
        $plain = $directory . '/.' . $name . '.zip.tmp';
        $target = $directory . '/' . $name;

        $zip = new \ZipArchive();
        if ($zip->open($plain, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) { throw new \RuntimeException('Не удалось создать архив.'); }
        $manifest = ['created_at' => gmdate('c'), 'host' => gethostname() ?: '', 'sites' => Network::codes(false), 'files' => []];
        try {
            foreach (self::payload() as $relative => $absolute) {
                if (!$zip->addFile($absolute, $relative)) { throw new \RuntimeException('Не удалось добавить ' . $relative); }
                $manifest['files'][$relative] = hash_file('sha256', $absolute);
            }
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } finally {
            if (!$zip->close()) { @unlink($plain); throw new \RuntimeException('Не удалось закрыть архив.'); }
        }

        try {
            self::encrypt($plain, $target);
        } finally { @unlink($plain); }
        chmod($target, 0600);

        return [
            'file' => $target,
            'bytes' => (int) filesize($target),
            'files' => count($manifest['files']),
            'sha256' => hash_file('sha256', $target),
            'fingerprint' => self::fingerprint(),
        ];
    }

    /**
     * Decrypts and verifies an archive. Without $extract it only checks that
     * every byte is intact; with it, files land in a fresh recovery directory
     * and the live workspace is never touched.
     */
    public static function restore(string $file, bool $extract = false): array
    {
        $plain = self::dir() . '/.restore-' . bin2hex(random_bytes(6)) . '.zip';
        try {
            self::decrypt($file, $plain);
            $zip = new \ZipArchive();
            if ($zip->open($plain) !== true) { throw new \RuntimeException('Архив расшифрован, но не читается.'); }
            try {
                $raw = $zip->getFromName('manifest.json');
                if ($raw === false) { throw new \RuntimeException('В архиве нет manifest.json.'); }
                $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                $files = $manifest['files'] ?? [];
                if (!is_array($files) || !$files || count($files) + 1 !== $zip->numFiles) { throw new \RuntimeException('Состав архива не совпадает с манифестом.'); }

                $directory = null;
                if ($extract) {
                    $directory = self::dir() . '/recovery/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(5));
                    if (!mkdir($directory, 0700, true)) { throw new \RuntimeException('Не удалось создать каталог восстановления.'); }
                }
                $total = 0;
                foreach ($files as $relative => $hash) {
                    self::assertMember($relative, $hash);
                    $stat = $zip->statName($relative);
                    if (!$stat) { throw new \RuntimeException('Файл отсутствует: ' . $relative); }
                    $total += $stat['size'] ?? 0;
                    if ($total > 4_000_000_000) { throw new \RuntimeException('Распакованные данные больше 4 ГБ.'); }
                    $stream = $zip->getStream($relative);
                    if (!$stream) { throw new \RuntimeException('Не удалось прочитать ' . $relative); }
                    $ctx = hash_init('sha256');
                    hash_update_stream($ctx, $stream);
                    fclose($stream);
                    if (!hash_equals($hash, hash_final($ctx))) { throw new \RuntimeException('Контрольная сумма не совпадает: ' . $relative); }
                    if ($directory === null) { continue; }
                    $path = $directory . '/' . $relative;
                    if (!is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
                    $source = $zip->getStream($relative);
                    $dest = fopen($path, 'xb');
                    if (!$source || !$dest) { throw new \RuntimeException('Не удалось извлечь ' . $relative); }
                    stream_copy_to_stream($source, $dest);
                    fclose($source); fclose($dest);
                }
                return ['manifest' => $manifest, 'directory' => $directory, 'files' => count($files), 'sha256' => hash_file('sha256', $file)];
            } finally { $zip->close(); }
        } finally { @unlink($plain); }
    }

    /** Everything git does not keep for you, and everything it does. @return array<string,string> */
    private static function payload(): array
    {
        $out = [];
        $root = str_replace('\\', '/', realpath(Network::root()) ?: Network::root());
        foreach (self::SOURCES as $top) {
            $base = Network::path($top);
            if (!is_dir($base)) { continue; }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file->isFile()) { continue; }
                $relative = ltrim(str_replace('\\', '/', substr(str_replace('\\', '/', $file->getPathname()), strlen($root))), '/');
                $out[$relative] = $file->getPathname();
            }
        }
        ksort($out);
        return $out;
    }

    private static function assertMember(string $relative, mixed $hash): void
    {
        if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) { throw new \RuntimeException('Неверный хеш в манифесте.'); }
        if (!preg_match('~^(?:content|config|data|static)/[a-zA-Z0-9_./@-]+$~D', $relative)
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $relative) || str_contains($relative, '//')) {
            throw new \RuntimeException('Недопустимый путь в архиве: ' . $relative);
        }
    }

    // ------------------------------------------------------------ encryption

    private static function encrypt(string $from, string $to): void
    {
        $salt = random_bytes(16);
        $nonce = random_bytes(8);
        [$in, $out] = [self::open($from, 'rb'), self::open($to, 'wb')];
        try {
            fwrite($out, self::MAGIC . "\n");
            fwrite($out, json_encode(['salt' => base64_encode($salt), 'nonce' => base64_encode($nonce), 'chunk' => self::CHUNK], JSON_THROW_ON_ERROR) . "\n");
            $key = hash_hkdf('sha256', self::key(), 32, 'minicms-backup-enc', $salt);
            $counter = 0;
            while (!feof($in)) {
                $chunk = fread($in, self::CHUNK);
                if ($chunk === false) { throw new \RuntimeException('Ошибка чтения архива.'); }
                if ($chunk === '') { continue; }
                self::writeChunk($out, $key, $nonce, $counter++, $chunk);
            }
            self::writeChunk($out, $key, $nonce, $counter, '');   // authenticated terminator
        } finally { fclose($in); fclose($out); }
    }

    private static function decrypt(string $from, string $to): void
    {
        [$in, $out] = [self::open($from, 'rb'), self::open($to, 'wb')];
        try {
            if (rtrim((string) fgets($in), "\n") !== self::MAGIC) { throw new \RuntimeException('Это не архив MiniCMS.'); }
            $header = json_decode((string) fgets($in), true, 8, JSON_THROW_ON_ERROR);
            $salt = base64_decode((string) ($header['salt'] ?? ''), true);
            $nonce = base64_decode((string) ($header['nonce'] ?? ''), true);
            $size = (int) ($header['chunk'] ?? 0);
            if ($salt === false || strlen($salt) !== 16 || $nonce === false || strlen($nonce) !== 8 || $size < 1 || $size > 64 * 1048576) {
                throw new \RuntimeException('Повреждён заголовок архива.');
            }
            $key = hash_hkdf('sha256', self::key(), 32, 'minicms-backup-enc', $salt);
            $counter = 0;
            while (true) {
                $head = self::readExactly($in, 20);
                $length = unpack('N', substr($head, 0, 4))[1];
                $tag = substr($head, 4);
                if ($length > $size) { throw new \RuntimeException('Повреждён размер блока.'); }
                $body = $length === 0 ? '' : self::readExactly($in, $length);
                $plain = openssl_decrypt($body, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, self::iv($nonce, $counter++), $tag);
                if ($plain === false) { throw new \RuntimeException('Не удалось расшифровать: неверный ключ или архив повреждён.'); }
                if ($length === 0) { break; }                      // terminator verified
                fwrite($out, $plain);
            }
        } finally { fclose($in); fclose($out); }
    }

    private static function writeChunk($handle, string $key, string $nonce, int $counter, string $chunk): void
    {
        $tag = '';
        $body = openssl_encrypt($chunk, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, self::iv($nonce, $counter), $tag);
        if ($body === false || strlen($tag) !== 16) { throw new \RuntimeException('Ошибка шифрования.'); }
        fwrite($handle, pack('N', strlen($body)) . $tag . $body);
    }

    private static function iv(string $nonce, int $counter): string
    {
        return $nonce . pack('N', $counter);
    }

    private static function readExactly($handle, int $length): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $part = fread($handle, $length - strlen($buffer));
            if ($part === false || $part === '') { throw new \RuntimeException('Архив обрывается раньше времени.'); }
            $buffer .= $part;
        }
        return $buffer;
    }

    private static function open(string $path, string $mode)
    {
        $handle = fopen($path, $mode);
        if (!$handle) { throw new \RuntimeException('Не удалось открыть ' . $path); }
        return $handle;
    }
}
