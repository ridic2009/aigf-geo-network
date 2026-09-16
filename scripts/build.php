<?php

declare(strict_types=1);

/**
 * scripts/build <geo> [--drafts] [--optimize] [--skip-validation] [--output=DIR]
 *
 * Builds one GEO into dist/<domain>/. Validation runs before and after the
 * Cecil build; any error aborts with exit code 1 and leaves production alone.
 */

require __DIR__ . '/../vendor/autoload.php';

use AiGf\Tools\Builder;
use AiGf\Tools\Cli;
use AiGf\Tools\Network;
use AiGf\Tools\Prepare;

[$args, $options] = Cli::parse($argv);

if ($args === []) {
    Cli::error('Usage: scripts/build <geo> [--drafts] [--optimize] [--skip-validation]');
    Cli::info('Available GEOs: ' . implode(', ', Network::codes(false)));
    exit(1);
}

$codes = Cli::resolveGeos($args);
if (\count($codes) !== 1) {
    Cli::error('scripts/build takes exactly one GEO. Use scripts/build-all for the whole network.');
    exit(1);
}
$code = $codes[0];

Prepare::run(array_values(array_unique(array_merge(Network::codes(true), $codes))));

Cli::title(\sprintf('Building %s (%s)', strtoupper($code), Network::host($code)));
$started = microtime(true);
$result = Builder::build($code, $options);

if (!$result['ok']) {
    Cli::error(\sprintf('%s: BUILD FAILED — production output was not written.', strtoupper($code)));
    exit(1);
}

Cli::ok(\sprintf(
    '%s: %d page(s) -> %s (%.1fs, %d warning(s))',
    strtoupper($code),
    $result['pages'],
    $result['output'],
    microtime(true) - $started,
    $result['warnings']
));
