<?php

declare(strict_types=1);

/**
 * scripts/prepare — regenerates the cross-GEO build artefacts.
 * Runs automatically before every build; run it by hand after adding a GEO.
 */

require __DIR__ . '/../vendor/autoload.php';

use AiGf\Tools\Cli;
use AiGf\Tools\Prepare;

[$args] = Cli::parse($argv);
$codes = Cli::resolveGeos($args);

$summary = Prepare::run($codes);

Cli::ok(\sprintf(
    'Network map generated: %d GEO(s), %d translation key(s) -> %s',
    $summary['geos'],
    $summary['keys'],
    str_replace(\dirname(__DIR__) . \DIRECTORY_SEPARATOR, '', $summary['file'])
));
