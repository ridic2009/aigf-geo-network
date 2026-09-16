<?php
declare(strict_types=1);
namespace AiGf\Tools;

/**
 * Version history for the editable sources.
 *
 * Studio used to lean on Git for this: every published page, every settings
 * change and every price ended up in a commit, and GitHub held the only copy
 * of what a file looked like yesterday. With Git gone that has to live here,
 * or publishing would silently destroy the previous version of a file.
 *
 * The store is deliberately dull:
 *
 *   .studio/history/objects/<ab>/<sha256>   gzipped file contents
 *   .studio/history/log.jsonl               append-only, one JSON per line
 *
 * Objects are content-addressed, so re-recording an unchanged file costs
 * nothing and a page edited fifty times keeps fifty small blobs. The log is
 * append-only text: it can be read with `tail`, shipped to the backup host as
 * a plain file, and never needs a schema migration.
 */
final class StudioHistory
{
    /** Directories whose contents are the editable source of the network. */
    private const TRACKED = ['content', 'config', 'data'];

    public static function dir(): string { return StudioStore::dir() . '/history'; }

    /** Path of a tracked file relative to the workspace root, or null when it is not tracked. */
    public static function relative(string $absolute): ?string
    {
        $root = str_replace('\\', '/', realpath(Network::root()) ?: Network::root());
        // realpath() returns false for a file that was just deleted — and a
        // deletion is exactly the thing we most need to record.
        $real = realpath($absolute);
        if ($real === false) {
            $parent = realpath(dirname($absolute));
            $real = $parent === false ? $absolute : $parent . DIRECTORY_SEPARATOR . basename($absolute);
        }
        $real = str_replace('\\', '/', $real);
        if (!str_starts_with($real, $root . '/')) { return null; }
        $relative = substr($real, strlen($root) + 1);
        $top = explode('/', $relative)[0];
        return in_array($top, self::TRACKED, true) ? $relative : null;
    }

    public static function assertPath(string $relative): string
    {
        $relative = str_replace('\\', '/', trim($relative, '/'));
        $top = explode('/', $relative)[0];
        if ($relative === '' || str_contains($relative, '..') || !in_array($top, self::TRACKED, true)
            || !preg_match('~^[\w./-]+$~D', $relative)) {
            throw new \InvalidArgumentException('Недопустимый путь: ' . $relative);
        }
        return $relative;
    }

    /**
     * Records the current contents of a file. Pass a file that no longer exists
     * to record a deletion. Returns the version id, or null when nothing changed.
     */
    public static function record(string $absolute, string $actor, string $action, ?string $note = null): ?string
    {
        self::ensure();
        $relative = self::relative($absolute) ?? self::assertPath(str_replace('\\', '/', $absolute));
        $exists = is_file($absolute);
        $sha = $exists ? hash_file('sha256', $absolute) : null;
        $previous = self::versions($relative, 1)[0]['sha'] ?? null;
        if ($sha === $previous) { return null; }
        if ($exists) { self::store($absolute, $sha); }
        self::append([
            'at' => gmdate('c'),
            'actor' => $actor,
            'action' => $action,
            'path' => $relative,
            'sha' => $sha,
            'prev' => $previous,
            'size' => $exists ? filesize($absolute) : 0,
            'note' => $note,
        ]);
        return $sha ?? 'deleted';
    }

    /** @param list<string> $paths absolute paths */
    public static function recordMany(array $paths, string $actor, string $action, ?string $note = null): int
    {
        $count = 0;
        foreach ($paths as $path) { if (self::record($path, $actor, $action, $note) !== null) { $count++; } }
        return $count;
    }

    /**
     * Takes the first baseline when the history is empty, so the very first
     * publication has something to be a diff against. Once per process, and a
     * no-op on every workspace that has ever recorded anything.
     */
    public static function ensure(): void
    {
        static $done = false;
        if ($done) { return; }
        $done = true;                       // set first: baseline() calls record()
        if (self::entries() === []) { self::baseline(); }
    }

    /**
     * Records everything tracked that has no version yet, so an existing
     * workspace starts with a full baseline rather than with holes until each
     * file happens to be edited.
     */
    public static function baseline(string $actor = 'import'): int
    {
        $known = [];
        foreach (self::entries() as $entry) { $known[$entry['path']] = true; }
        $count = 0;
        foreach (self::files() as $absolute) {
            $relative = self::relative($absolute);
            if ($relative === null || isset($known[$relative])) { continue; }
            if (self::record($absolute, $actor, 'import', 'Состояние на момент подключения истории') !== null) { $count++; }
        }
        return $count;
    }

    /** Every tracked file currently on disk. @return list<string> absolute paths */
    public static function files(): array
    {
        $out = [];
        foreach (self::TRACKED as $top) {
            $base = Network::path($top);
            if (!is_dir($base)) { continue; }
            $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($walk as $file) {
                if ($file->isFile() && $file->getFilename() !== '.gitkeep') { $out[] = $file->getPathname(); }
            }
        }
        sort($out);
        return $out;
    }

    /**
     * The log, newest first.
     *
     * @param array{path?:string,actor?:string,action?:string} $filter
     * @return list<array<string,mixed>>
     */
    public static function log(array $filter = [], int $limit = 200, int $offset = 0): array
    {
        $out = [];
        foreach (array_reverse(self::entries()) as $entry) {
            foreach ($filter as $key => $value) {
                if ($value !== null && $value !== '' && ($entry[$key] ?? null) !== $value) { continue 2; }
            }
            if ($offset-- > 0) { continue; }
            $out[] = $entry;
            if (count($out) >= $limit) { break; }
        }
        return $out;
    }

    /** Versions of one file, newest first. @return list<array<string,mixed>> */
    public static function versions(string $relative, int $limit = 100): array
    {
        return self::log(['path' => self::assertPath($relative)], $limit);
    }

    /** The bytes stored under a version id. */
    public static function read(string $sha): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $sha)) { throw new \InvalidArgumentException('Неверный идентификатор версии.'); }
        $file = self::object($sha);
        if (!is_file($file)) { throw new \RuntimeException('Версия не найдена.', 404); }
        $body = gzdecode((string) file_get_contents($file));
        if ($body === false || hash('sha256', $body) !== $sha) { throw new \RuntimeException('Версия повреждена.', 500); }
        return $body;
    }

    /**
     * Puts an old version back on disk.
     *
     * A content file with an unfinished draft in Studio is refused rather than
     * silently overwritten: the draft would immediately fail its base-hash
     * check at publication and the author would have no idea why.
     */
    public static function restore(array $user, string $relative, string $sha, array &$state): string
    {
        StudioAuth::requireAdmin($user);
        $relative = self::assertPath($relative);
        $body = self::read($sha);
        $absolute = Network::root() . '/' . $relative;

        foreach ($state['documents'] as $doc) {
            $docPath = str_replace('\\', '/', StudioContent::path($doc['site'], $doc['page']));
            if (str_replace('\\', '/', $absolute) !== $docPath) { continue; }
            if (!in_array($doc['state'], ['published', 'source', 'publication_failed'], true)) {
                throw new \RuntimeException('По этому материалу есть незавершённый черновик (' . $doc['state'] . '). Завершите или верните его в работу, потом восстанавливайте.', 409);
            }
        }

        if (!is_dir(dirname($absolute))) { mkdir(dirname($absolute), 0755, true); }
        if (file_put_contents($absolute, $body) === false) { throw new \RuntimeException('Не удалось записать файл.'); }
        Network::reset();
        self::record($absolute, $user['login'], 'restore', 'Восстановлена версия ' . substr($sha, 0, 8));
        StudioStore::audit($state, $user['login'], 'history.restored', ['path' => $relative, 'sha' => $sha]);
        return $relative;
    }

    /**
     * Line diff between two versions. Plain LCS: the files here are small and a
     * dependency for this would be silly.
     *
     * @return list<array{op:string,text:string}> op is ' ', '-' or '+'
     */
    public static function diff(string $before, string $after): array
    {
        $a = $before === '' ? [] : explode("\n", str_replace("\r\n", "\n", $before));
        $b = $after === '' ? [] : explode("\n", str_replace("\r\n", "\n", $after));
        $n = count($a); $m = count($b);
        // Common prefix and suffix first: most edits touch a few lines.
        $start = 0;
        while ($start < $n && $start < $m && $a[$start] === $b[$start]) { $start++; }
        $endA = $n - 1; $endB = $m - 1;
        while ($endA >= $start && $endB >= $start && $a[$endA] === $b[$endB]) { $endA--; $endB--; }
        $midA = array_slice($a, $start, $endA - $start + 1);
        $midB = array_slice($b, $start, $endB - $start + 1);

        $lcs = [];
        $rows = count($midA); $cols = count($midB);
        if ($rows * $cols <= 4000000) {
            $table = array_fill(0, $rows + 1, array_fill(0, $cols + 1, 0));
            for ($i = $rows - 1; $i >= 0; $i--) {
                for ($j = $cols - 1; $j >= 0; $j--) {
                    $table[$i][$j] = $midA[$i] === $midB[$j] ? $table[$i + 1][$j + 1] + 1 : max($table[$i + 1][$j], $table[$i][$j + 1]);
                }
            }
            $i = 0; $j = 0;
            while ($i < $rows && $j < $cols) {
                if ($midA[$i] === $midB[$j]) { $lcs[] = [' ', $midA[$i]]; $i++; $j++; }
                elseif ($table[$i + 1][$j] >= $table[$i][$j + 1]) { $lcs[] = ['-', $midA[$i]]; $i++; }
                else { $lcs[] = ['+', $midB[$j]]; $j++; }
            }
            while ($i < $rows) { $lcs[] = ['-', $midA[$i++]]; }
            while ($j < $cols) { $lcs[] = ['+', $midB[$j++]]; }
        } else {
            foreach ($midA as $line) { $lcs[] = ['-', $line]; }
            foreach ($midB as $line) { $lcs[] = ['+', $line]; }
        }

        $out = [];
        for ($i = 0; $i < $start; $i++) { $out[] = ['op' => ' ', 'text' => $a[$i]]; }
        foreach ($lcs as [$op, $text]) { $out[] = ['op' => $op, 'text' => $text]; }
        for ($i = $endA + 1; $i < $n; $i++) { $out[] = ['op' => ' ', 'text' => $a[$i]]; }
        return $out;
    }

    /** Totals for the history screen. */
    public static function stats(): array
    {
        $entries = self::entries();
        $bytes = 0; $objects = 0;
        $base = self::dir() . '/objects';
        if (is_dir($base)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) { $objects++; $bytes += $file->getSize(); }
            }
        }
        $paths = [];
        foreach ($entries as $entry) { $paths[$entry['path']] = true; }
        return ['versions' => count($entries), 'files' => count($paths), 'objects' => $objects, 'bytes' => $bytes];
    }

    // ---------------------------------------------------------------- internals

    private static function object(string $sha): string
    {
        return self::dir() . '/objects/' . substr($sha, 0, 2) . '/' . $sha;
    }

    private static function store(string $absolute, string $sha): void
    {
        $file = self::object($sha);
        if (is_file($file)) { return; }
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0700, true) && !is_dir(dirname($file))) {
            throw new \RuntimeException('Не удалось создать хранилище версий.');
        }
        $body = (string) file_get_contents($absolute);
        $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, gzencode($body, 6)) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('Не удалось сохранить версию файла.');
        }
        chmod($file, 0600);
    }

    private static function append(array $entry): void
    {
        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) { throw new \RuntimeException('Не удалось создать каталог истории.'); }
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        // Appends under LOCK_EX are atomic enough for one worker plus the web
        // process; the log is never rewritten, only extended.
        if (file_put_contents($dir . '/log.jsonl', $line, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Не удалось записать историю.');
        }
        self::$cache = null;
    }

    /** @var list<array<string,mixed>>|null */
    private static ?array $cache = null;

    /** @return list<array<string,mixed>> oldest first */
    private static function entries(): array
    {
        if (self::$cache !== null) { return self::$cache; }
        $file = self::dir() . '/log.jsonl';
        $out = [];
        if (is_file($file)) {
            foreach (explode("\n", (string) file_get_contents($file)) as $line) {
                if (trim($line) === '') { continue; }
                try { $out[] = json_decode($line, true, 8, JSON_THROW_ON_ERROR); } catch (\JsonException) { /* skip a torn line */ }
            }
        }
        return self::$cache = $out;
    }
}
