<?php

declare(strict_types=1);

/**
 * scripts/backup create|verify|extract|push|key [options]
 *
 *   create                 encrypted archive of the sources and images
 *   verify  <file>         decrypt and check every checksum, change nothing
 *   extract <file>         same, then unpack into .backups/recovery/<id>/
 *   push    --host=IP      create, copy to the backup host over ssh, rotate
 *   key                    show the key fingerprint and where the key lives
 *
 * The key is generated on first use in .secrets/backup.key and is never part
 * of an archive. Keep a copy of it somewhere other than these two servers, or
 * the backups are unreadable when you actually need them.
 */

require __DIR__ . '/../vendor/autoload.php';

use AiGf\Tools\Builder;
use AiGf\Tools\Cli;
use AiGf\Tools\Backup;

[$args, $options] = Cli::parse($argv);
$command = $args[0] ?? '';

$size = static function (int $bytes): string {
    foreach ([[1073741824, 'ГБ'], [1048576, 'МБ'], [1024, 'КБ']] as [$unit, $label]) {
        if ($bytes >= $unit) { return round($bytes / $unit, 1) . ' ' . $label; }
    }
    return $bytes . ' Б';
};

try {
    switch ($command) {
        case 'key':
            Cli::ok('Отпечаток ключа: ' . Backup::fingerprint());
            Cli::info('Файл ключа: ' . Backup::keyFile());
            Cli::info('Скопируйте его в отдельное место. Без ключа архивы не восстановить.');
            break;

        case 'create':
            $result = Backup::create($options['out'] ?? null);
            Cli::ok(sprintf('%s — %d файлов, %s', basename($result['file']), $result['files'], $size($result['bytes'])));
            Cli::info('Путь: ' . $result['file']);
            Cli::info('SHA-256: ' . $result['sha256']);
            Cli::info('Отпечаток ключа: ' . $result['fingerprint']);
            break;

        case 'verify':
        case 'extract':
            if (empty($args[1])) { throw new RuntimeException('Укажите файл архива.'); }
            $result = Backup::restore($args[1], $command === 'extract');
            Cli::ok($result['files'] . ' файлов проверено, все контрольные суммы совпали.');
            Cli::info('Создан: ' . ($result['manifest']['created_at'] ?? '?') . ' на ' . ($result['manifest']['host'] ?? '?'));
            Cli::info('Сайты: ' . implode(', ', $result['manifest']['sites'] ?? []));
            if ($result['directory']) { Cli::info('Каталог восстановления: ' . $result['directory']); }
            break;

        case 'push':
            $host = $options['host'] ?? getenv('BACKUP_HOST') ?: '';
            if (!$host || !preg_match('/^[a-zA-Z0-9._-]+$/D', (string) $host)) { throw new RuntimeException('Укажите --host=IP или BACKUP_HOST.'); }
            $user = $options['user'] ?? getenv('BACKUP_USER') ?: 'minicms';
            $port = (string) ($options['port'] ?? getenv('BACKUP_PORT') ?: '22');
            $remote = rtrim((string) ($options['path'] ?? getenv('BACKUP_PATH') ?: '/srv/minicms-backups'), '/');
            $keep = (int) ($options['keep'] ?? getenv('BACKUP_KEEP') ?: 30);
            if (!preg_match('/^\d+$/D', $port) || $keep < 2) { throw new RuntimeException('Проверьте --port и --keep (минимум 2).'); }
            if (!preg_match('~^/[\w./-]+$~D', $remote)) { throw new RuntimeException('Некорректный --path.'); }

            $result = Backup::create();
            Cli::ok(sprintf('Архив готов: %s (%s)', basename($result['file']), $size($result['bytes'])));

            // argv arrays, never a shell string: every value below is already
            // pattern-checked, and this leaves no room for quoting mistakes.
            $account = $user . '@' . $host;
            $ssh = static fn (string $remoteCommand): array => Builder::run(
                ['ssh', '-p', $port, '-o', 'StrictHostKeyChecking=accept-new', $account, $remoteCommand]
            );
            $name = basename($result['file']);

            [$code, , $err] = $ssh('mkdir -p ' . $remote . ' && chmod 700 ' . $remote);
            if ($code !== 0) { throw new RuntimeException('Не удалось подготовить каталог на бэкап-хосте: ' . trim($err)); }

            [$code, , $err] = Builder::run(['scp', '-P', $port, '-o', 'StrictHostKeyChecking=accept-new', $result['file'], $account . ':' . $remote . '/']);
            if ($code !== 0) { throw new RuntimeException('Не удалось скопировать архив: ' . trim($err)); }

            // Verify the copy landed intact before anything is rotated away.
            [$code, $out] = $ssh('sha256sum ' . $remote . '/' . $name);
            if ($code !== 0 || !str_starts_with(trim($out), $result['sha256'])) {
                throw new RuntimeException('Копия на бэкап-хосте не совпала по SHA-256. Ротация не выполнена.');
            }
            Cli::ok('Копия проверена по SHA-256.');

            [$code, , $err] = $ssh('cd ' . $remote . ' && ls -1t *.mcbk 2>/dev/null | tail -n +' . ($keep + 1) . ' | xargs -r rm -f');
            if ($code !== 0) { Cli::warn('Архив на месте, но ротация не выполнена: ' . trim($err)); }
            Cli::ok('Готово. На ' . $host . ' хранится до ' . $keep . ' архивов.');
            Cli::info('Отпечаток ключа: ' . $result['fingerprint']);
            break;

        default:
            throw new RuntimeException('Usage: php scripts/backup.php create|verify|extract|push|key');
    }
} catch (Throwable $e) {
    Cli::error($e->getMessage());
    exit(1);
}
