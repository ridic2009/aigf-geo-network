<?php

declare(strict_types=1);

/**
 * scripts/new-geo <code> [--domain=example.site] [--from=us] [--no-content]
 *
 * Scaffolds a new GEO: config, content directories, optional draft copies of an
 * existing GEO's pages, and a regenerated Pages CMS schema.
 *
 * Nothing is cloned: the new site uses the same engine, the same components and
 * the same deployment pipeline as every other GEO.
 */

require __DIR__ . '/../vendor/autoload.php';

use AiGf\Tools\Builder;
use AiGf\Tools\Cli;
use AiGf\Tools\Network;
use Symfony\Component\Yaml\Yaml;

[$args, $options] = Cli::parse($argv);

if ($args === []) {
    Cli::error('Usage: scripts/new-geo <code> [--domain=example.site] [--from=us] [--no-content]');
    Cli::info('Known presets: ' . implode(', ', array_keys(Network::presets()['geos'] ?? [])));
    exit(1);
}

$code = strtolower($args[0]);
if (!preg_match('/^[a-z]{2}$/', $code)) {
    Cli::error('A GEO code must be two lowercase letters, e.g. "es".');
    exit(1);
}
if (Network::exists($code)) {
    Cli::error(\sprintf('GEO "%s" already exists (config/geos/%s.yml).', $code, $code));
    exit(1);
}

$presets = Network::presets();
$preset = $presets['geos'][$code] ?? null;
if ($preset === null) {
    Cli::error(\sprintf('No preset for "%s" in config/network.yml. Add one first so language, locale and currency are correct.', $code));
    exit(1);
}

$domain = (string) ($options['domain'] ?? $preset['domain'] ?? '');
if ($domain === '') {
    Cli::error('No domain: add one to config/network.yml or pass --domain=example.site');
    exit(1);
}

$source = (string) ($options['from'] ?? 'us');
if (!Network::exists($source)) {
    Cli::error(\sprintf('Source GEO "%s" does not exist.', $source));
    exit(1);
}
$sourceGeo = Network::geo($source);

/* ------------------------------------------------------------- GEO config */

$config = [
    'geo' => [
        'code'         => $code,
        'name'         => $preset['name'] ?? strtoupper($code),
        'country_code' => strtoupper($code),
        'hreflang'     => $preset['hreflang'] ?? $code,
        // A new GEO starts disabled: build-all, deploy-all and the hreflang map
        // skip it until its content is translated and published.
        'enabled'      => false,
        'timezone'     => $preset['timezone'] ?? 'UTC',
        'currency'     => [
            'code'                => $preset['currency'] ?? 'EUR',
            'symbol'              => $preset['symbol'] ?? '€',
            'position'            => $preset['position'] ?? ($presets['defaults']['currency_position'] ?? 'after'),
            'decimals'            => $preset['decimals'] ?? 2,
            'decimal_separator'   => $preset['decimal_separator'] ?? ($presets['defaults']['decimal_separator'] ?? ','),
            'thousands_separator' => $preset['thousands_separator'] ?? ($presets['defaults']['thousands_separator'] ?? '.'),
        ],
    ],
    'baseurl'   => 'https://' . $domain . '/',
    'language'  => $preset['language'] ?? 'en',
    'languages' => [[
        'code'    => $preset['language'] ?? 'en',
        'name'    => $preset['name'] ?? strtoupper($code),
        'locale'  => $preset['locale'] ?? 'en_US',
        'enabled' => true,
    ]],
    'title'       => ($sourceGeo['title'] ?? 'AI Girlfriend Ranking') . ' ' . ($preset['name'] ?? strtoupper($code)),
    'baseline'    => $sourceGeo['baseline'] ?? '',
    'description' => $sourceGeo['description'] ?? '',
    'pages'       => ['dir' => 'content/' . $code],
    'routes'      => Network::common()['routes'] ?? [],
    'organization' => [
        'name'       => ($sourceGeo['organization']['name'] ?? 'AI Girlfriend Ranking'),
        'legal_name' => $sourceGeo['organization']['legal_name'] ?? '',
        'email'      => 'editorial@' . $domain,
        'logo'       => $sourceGeo['organization']['logo'] ?? '/images/brand/logo.svg',
    ],
    // TRANSLATE ME: copied from the source GEO so the site builds immediately.
    'ui' => $sourceGeo['ui'] ?? [],
];

$header = <<<TXT
# ---------------------------------------------------------------------------
# GEO: {$config['geo']['name']}
# Scaffolded by scripts/new-geo from config/network.yml.
#
# TODO before going live:
#   1. translate `ui.*` (interface labels)
#   2. localize `routes.*` (URL prefixes, e.g. reviews -> avis)
#   3. translate `title` / `description`
#   4. write content in content/{$code}/ (or via Pages CMS)
# ---------------------------------------------------------------------------

TXT;

file_put_contents(
    Network::path('config', 'geos', $code . '.yml'),
    $header . Yaml::dump($config, 6, 2)
);
Cli::ok('config/geos/' . $code . '.yml created');

/* --------------------------------------------------------- content skeleton */

$sections = array_values(array_filter(Network::typeSections($code)));
foreach ($sections as $section) {
    $dir = Network::path('content', $code, $section);
    if (!is_dir($dir)) {
        mkdir($dir, 0o777, true);
    }
    // Pages CMS reads collection directories through the GitHub API: they must
    // exist in the repository even while empty.
    file_put_contents($dir . \DIRECTORY_SEPARATOR . '.gitkeep', '');
}

if (empty($options['no-content'])) {
    $copied = copyAsDrafts(Network::contentDir($source), Network::path('content', $code));
    Cli::ok(\sprintf('%d page(s) copied from %s as drafts (translate, then publish)', $copied, strtoupper($source)));
} else {
    file_put_contents(Network::path('content', $code, 'index.md'), draftHomepage($config['title']));
    Cli::ok('content/' . $code . '/index.md created (draft)');
}

/* ------------------------------------------------------------- regenerate */

[$exit] = Builder::run([\PHP_BINARY, Network::path('scripts', 'cms-config.php')]);
Cli::ok($exit === 0 ? '.pages.yml regenerated' : '.pages.yml regeneration FAILED — run ./scripts/cms-config');

[$exit] = Builder::run([\PHP_BINARY, Network::path('scripts', 'prepare.php')]);
Cli::ok($exit === 0 ? 'network map regenerated' : 'network map regeneration FAILED — run ./scripts/prepare');

Cli::title('Next steps');
Cli::info('1. Point ' . $domain . ' at the VPS in Cloudflare DNS (A record, proxied).');
Cli::info('2. Translate config/geos/' . $code . '.yml (ui labels + routes).');
Cli::info('3. Translate the content in content/' . $code . '/ and set status: published.');
Cli::info('4. Set geo.enabled: true in config/geos/' . $code . '.yml once the homepage is published.');
Cli::info('5. ./scripts/nginx-config ' . $code . '  -> infra/nginx/sites/' . $domain . '.conf');
Cli::info('6. Install the vhost and request a certificate on the server (see docs/deployment.md).');
Cli::info('7. ./scripts/build ' . $code . ' && ./scripts/deploy ' . $code);
Cli::info('');
Cli::info('Until step 4 the GEO is disabled: build-all, deploy-all and hreflang skip it.');

function copyAsDrafts(string $from, string $to): int
{
    $count = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['md', 'markdown'], true)) {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($from) + 1);
        $target = $to . DIRECTORY_SEPARATOR . $relative;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0o777, true);
        }
        $content = (string) file_get_contents($file->getPathname());
        $content = preg_replace('/^status:\s*\S+$/m', 'status: draft', $content, 1) ?? $content;
        file_put_contents($target, $content);
        $count++;
    }

    return $count;
}

function draftHomepage(string $title): string
{
    return <<<MD
    ---
    title: {$title}
    type: homepage
    status: draft
    translation_key: home

    intro: |
      TODO: write the introduction for this market.

    seo:
      title: {$title}
      description: 'TODO: write a meta description of 120-160 characters for this market.'
      primary_keyword: 'TODO'

    indexing:
      index: true
      follow: true
    ---

    ## TODO

    Write the homepage content for this market.
    MD;
}
