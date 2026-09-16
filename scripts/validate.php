<?php

declare(strict_types=1);

/**
 * scripts/validate [geo…] [--quiet] [--no-cms]
 *
 * Runs every pre-build check: business data, Pages CMS schema and per-GEO content.
 * Exit code 1 when any error is found, so CI can gate a deployment on it.
 */

require __DIR__ . '/../vendor/autoload.php';

use AiGf\Tools\Cli;
use AiGf\Tools\ContentValidator;
use AiGf\Tools\DataValidator;

[$args, $options] = Cli::parse($argv);
$codes = Cli::resolveGeos($args);
$showWarnings = empty($options['quiet']);

$totalErrors = 0;
$totalWarnings = 0;

$check = static function (string $title, array $result) use (&$totalErrors, &$totalWarnings, $showWarnings): void {
    Cli::title($title);
    $totalErrors += \count($result['errors']);
    $totalWarnings += \count($result['warnings']);
    if (Cli::report($result, $showWarnings)) {
        Cli::ok(\sprintf('%s: OK (%d warning(s)).', $title, \count($result['warnings'])));
    }
};

$check('Business data', (new DataValidator())->validate());

foreach ($codes as $code) {
    $check('Content ' . strtoupper($code), (new ContentValidator())->validate($code));
}

Cli::line('');
Cli::line(\sprintf('%d error(s), %d warning(s) across %d GEO(s).', $totalErrors, $totalWarnings, \count($codes)));

exit($totalErrors > 0 ? 1 : 0);
