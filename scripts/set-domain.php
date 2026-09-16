<?php

declare(strict_types=1);

/**
 * scripts/set-domain <geo> <domain|url> [--staging] [--production]
 *
 * Changes the domain of a GEO in one safe operation. `baseurl` is the single
 * place a domain is written down, but four generated things follow from it, so
 * this script keeps them in step:
 *
 *   config/geos/<geo>.yml   baseurl (and the staging flag)
 *   .cecil/generated/       hreflang map
 *   infra/nginx/sites/      vhost file name and server_name
 *   dist/<domain>/          build output directory
 *
 * Use it twice: once to put the network on a rehearsal host, once to move it to
 * the real domain.
 *
 *   ./scripts/set-domain us us.203.0.113.10.sslip.io --staging
 *   ./scripts/set-domain us aigirlfriendranking.com --production
 *
 * --staging marks the GEO as a rehearsal host: every page gets noindex and
 * robots.txt disallows everything, so a temporary domain cannot be indexed and
 * compete with the real one later.
 */

require __DIR__ . '/../vendor/autoload.php';

use AiGf\Tools\Builder;
use AiGf\Tools\Cli;
use AiGf\Tools\Network;

[$args, $options] = Cli::parse($argv);

if (\count($args) < 2) {
    Cli::error('Usage: scripts/set-domain <geo> <domain|url> [--staging] [--production]');
    Cli::info('Available GEOs: ' . implode(', ', Network::codes(false)));
    exit(1);
}

$code = Cli::resolveGeos([$args[0]])[0];
$input = trim($args[1]);

$staging = !empty($options['staging']);
$production = !empty($options['production']);
if ($staging && $production) {
    Cli::error('--staging and --production are mutually exclusive.');
    exit(1);
}

/* ------------------------------------------------------- normalise the URL */

$url = preg_match('#^https?://#i', $input) ? $input : (($staging ? 'http://' : 'https://') . $input);
$parts = parse_url($url);
$host = (string) ($parts['host'] ?? '');
$scheme = (string) ($parts['scheme'] ?? 'https');

if ($host === '' || !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $host)) {
    Cli::error(\sprintf('"%s" is not a valid hostname.', $input));
    exit(1);
}

$baseUrl = $scheme . '://' . $host . '/';
$previous = Network::baseUrl($code);
$wasStaging = Network::isStaging($code);

/* --------------------------------------------------------- rewrite the file */

$nowStaging = $staging || (!$production && $wasStaging);
\AiGf\Tools\StudioSites::save(['login' => 'cli', 'role' => 'admin', 'sites' => ['*']], $code, [
    'baseurl' => $baseUrl,
    'staging' => $nowStaging,
    'enabled' => Network::isEnabled($code),
]);
Cli::ok('Domain updated; the previous domain is retained for 301 redirects.');

/* ------------------------------------------------------------- regenerate */

foreach ([['prepare.php', 'hreflang map'], ['cms-config.php', 'CMS schema']] as [$script, $label]) {
    [$exit] = Builder::run([\PHP_BINARY, Network::path('scripts', $script)]);
    $exit === 0 ? Cli::ok($label . ' regenerated') : Cli::warn($label . ' regeneration failed — run ./scripts/' . basename($script, '.php'));
}

$nginxArgs = [\PHP_BINARY, Network::path('scripts', 'nginx-config.php'), $code];
if ($scheme === 'http') {
    $nginxArgs[] = '--http-only';
}
[$exit] = Builder::run($nginxArgs);
$exit === 0
    ? Cli::ok('infra/nginx/sites/' . $host . '.conf regenerated' . ($scheme === 'http' ? ' (HTTP only)' : ''))
    : Cli::warn('Nginx config regeneration failed — run ./scripts/nginx-config ' . $code);

/* --------------------------------------------------------------- clean up */

$oldHost = (string) parse_url($previous, \PHP_URL_HOST);
if ($oldHost !== '' && $oldHost !== $host) {
    $oldDist = Network::path('dist', $oldHost);
    if (is_dir($oldDist)) {
        Builder::removeDirectory($oldDist);
        Cli::info('removed the previous output dist/' . $oldHost . '/');
    }
}

Cli::title('Next');
Cli::info('./scripts/validate ' . $code);
Cli::info('./scripts/build ' . $code . ' --optimize');
Cli::info('install infra/nginx/sites/' . $host . '.conf on the server, then:');
Cli::info('./scripts/deploy ' . $code);
if ($oldHost !== '' && $oldHost !== $host) {
    Cli::warn('The old site is still on the server at ' . $oldHost . ' — remove its vhost and /srv/www/' . $oldHost . '/ when it is no longer needed.');
}
