<?php

declare(strict_types=1);

/**
 * scripts/geo.php <what> [geo]
 *
 * Tiny lookup helper for the shell scripts, so deploy/rollback never hardcode a
 * domain. `what` is one of: list, host, baseurl, name.
 */

require __DIR__ . '/../vendor/autoload.php';

use AiGf\Tools\Network;

$what = $argv[1] ?? 'list';
$code = isset($argv[2]) ? strtolower($argv[2]) : null;

try {
    switch ($what) {
        case 'list':
            echo implode(' ', Network::codes(true)), \PHP_EOL;
            break;
        case 'list-all':
            echo implode(' ', Network::codes(false)), \PHP_EOL;
            break;
        case 'host':
            echo Network::host((string) $code), \PHP_EOL;
            break;
        case 'baseurl':
            echo Network::baseUrl((string) $code), \PHP_EOL;
            break;
        case 'name':
            echo (string) (Network::geo((string) $code)['geo']['name'] ?? strtoupper((string) $code)), \PHP_EOL;
            break;
        default:
            fwrite(\STDERR, "Usage: geo.php list|list-all|host|baseurl|name [geo]\n");
            exit(1);
    }
} catch (\Throwable $e) {
    fwrite(\STDERR, $e->getMessage() . \PHP_EOL);
    exit(1);
}
