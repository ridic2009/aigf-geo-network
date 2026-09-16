<?php

declare(strict_types=1);

/**
 * scripts/build-all [--optimize] [--continue-on-error]
 *
 * Builds every enabled GEO. Exit code 1 if any GEO fails, so a broken site can
 * never reach production through CI.
 */

require __DIR__ . '/../vendor/autoload.php';

use AiGf\Tools\Builder;
use AiGf\Tools\Cli;
use AiGf\Tools\Network;
use AiGf\Tools\Prepare;

[$args, $options] = Cli::parse($argv);
$codes = Cli::resolveGeos($args);

Prepare::run(array_values(array_unique(array_merge(Network::codes(true), $codes))));

$results = [];
$started = microtime(true);

foreach ($codes as $code) {
    Cli::title(\sprintf('Building %s (%s)', strtoupper($code), Network::host($code)));
    $result = Builder::build($code, $options);
    $results[$code] = $result;

    if ($result['ok']) {
        Cli::ok(\sprintf('%s: %d page(s) -> %s', strtoupper($code), $result['pages'], $result['output']));
    } else {
        Cli::error(\sprintf('%s: build failed', strtoupper($code)));
        if (empty($options['continue-on-error'])) {
            break;
        }
    }
}

$successful = \count(array_filter($results, static fn ($r) => $r['ok']));

Cli::line('');
Cli::line('---------------------------------------------');
foreach ($codes as $code) {
    $state = isset($results[$code]) ? ($results[$code]['ok'] ? 'OK' : 'FAILED') : 'SKIPPED';
    Cli::line(\sprintf('  %-4s %s', strtoupper($code), $state));
}
Cli::line('---------------------------------------------');
Cli::line(\sprintf('%d/%d successful in %.1fs', $successful, \count($codes), microtime(true) - $started));

exit($successful === \count($codes) ? 0 : 1);
