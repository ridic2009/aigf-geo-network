<?php
declare(strict_types=1);
namespace AiGf\Tools;

/** Verify every byte before recovery; never extract arbitrary ZIP paths. */
final class ReleaseArchive
{
    public static function read(string $path, bool $extract = false): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) { throw new \RuntimeException('Не удалось открыть архив.'); }
        try {
            $raw = $zip->getFromName('manifest.json');
            if ($raw === false || strlen($raw) > 16_000_000) { throw new \RuntimeException('Отсутствует manifest.json или он слишком большой.'); }
            $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            $files = $manifest['files'] ?? [];
            if (!$files || !is_array($files) || count($files) + 1 !== $zip->numFiles) { throw new \RuntimeException('Состав архива не совпадает с манифестом.'); }
            $total = 0;
            foreach ($files as $name => $hash) {
                if (!preg_match('~^(?:dist|config|content|data|static|engine)/[a-zA-Z0-9_./@-]+$~D', $name)
                    || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $name) || str_contains($name, '//')
                    || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                    throw new \RuntimeException('Недопустимый путь или хеш в архиве.');
                }
                $stat = $zip->statName($name);
                $total += $stat['size'] ?? 0;
                if (!$stat || $total > 2_000_000_000) { throw new \RuntimeException('Файл отсутствует или архив больше 2 ГБ.'); }
                $stream = $zip->getStream($name);
                if (!$stream) { throw new \RuntimeException('Не удалось прочитать ' . $name); }
                $ctx = hash_init('sha256'); hash_update_stream($ctx, $stream); fclose($stream);
                if (!hash_equals($hash, hash_final($ctx))) { throw new \RuntimeException('Контрольная сумма не совпадает: ' . $name); }
            }
            $directory = null;
            if ($extract) {
                $directory = StudioStore::dir() . '/recovery/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(5));
                if (!mkdir($directory, 0700, true)) { throw new \RuntimeException('Не удалось создать каталог восстановления.'); }
                foreach ($files as $name => $hash) {
                    $target = $directory . '/' . $name;
                    if (!is_dir(dirname($target))) { mkdir(dirname($target), 0700, true); }
                    $source = $zip->getStream($name); $dest = fopen($target, 'xb');
                    if (!$source || !$dest) { throw new \RuntimeException('Не удалось извлечь ' . $name); }
                    stream_copy_to_stream($source, $dest); fclose($source); fclose($dest);
                    if (!hash_equals($hash, hash_file('sha256', $target))) { throw new \RuntimeException('Ошибка записи ' . $name); }
                }
            }
            return ['manifest' => $manifest, 'directory' => $directory, 'sha256' => hash_file('sha256', $path)];
        } finally { $zip->close(); }
    }
}
