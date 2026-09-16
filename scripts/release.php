<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use AiGf\Tools\{Cli, ReleaseArchive};
[$args] = Cli::parse($argv);
try {
    if (!in_array($args[0] ?? '', ['verify', 'extract'], true) || empty($args[1])) {
        throw new RuntimeException('Usage: php scripts/release.php verify|extract /path/to/release.zip');
    }
    $result = ReleaseArchive::read($args[1], $args[0] === 'extract');
    Cli::ok(count($result['manifest']['files']) . ' файлов проверено. SHA-256: ' . $result['sha256']);
    Cli::info('Сайты: ' . implode(', ', $result['manifest']['sites']));
    if ($result['directory']) { Cli::info('Каталог восстановления: ' . $result['directory']); }
} catch (Throwable $e) { Cli::error($e->getMessage()); exit(1); }
