<?php

declare(strict_types=1);

/**
 * scripts/smoke <geo> [--origin=IP] [--scheme=http] [--timeout=10] [--quiet]
 *
 * Checks a live site the way a visitor and a crawler would. Run automatically
 * by scripts/deploy right after the release switch: if it fails, that server is
 * rolled back to the previous release before anyone notices.
 *
 * --origin=IP sends the request to one specific server while keeping the real
 * Host/SNI, which is how the backup VPS is verified without touching DNS.
 * Certificate trust is relaxed in that mode, because a Cloudflare Origin
 * Certificate is not signed by a public CA.
 */

require __DIR__ . '/../vendor/autoload.php';

use AiGf\Tools\Cli;
use AiGf\Tools\Network;

[$args, $options] = Cli::parse($argv);

if ($args === []) {
    Cli::error('Usage: scripts/smoke <geo> [--origin=IP] [--scheme=http] [--timeout=10]');
    exit(1);
}

$code = Cli::resolveGeos([$args[0]])[0];
$origin = isset($options['origin']) ? (string) $options['origin'] : null;
$timeout = (int) ($options['timeout'] ?? 10);
$quiet = !empty($options['quiet']);

$baseUrl = Network::baseUrl($code);
$host = Network::host($code);
// The scheme comes from the GEO's own baseurl: a staging host served over plain
// HTTP must not be probed on 443, and a production host must not be probed on 80.
$scheme = (string) ($options['scheme'] ?? parse_url($baseUrl, \PHP_URL_SCHEME) ?: 'https');
$port = (int) ($options['port'] ?? ($scheme === 'https' ? 443 : 80));
if ($scheme !== parse_url($baseUrl, \PHP_URL_SCHEME) || isset($options['port'])) {
    // --port is mainly for verifying a local preview before anything is deployed
    $baseUrl = \sprintf('%s://%s%s/', $scheme, $host, \in_array($port, [80, 443], true) ? '' : ':' . $port);
}

// CURLOPT_RESOLVE only accepts an address, so a name like "localhost" (used when
// the server deploys to itself) has to be resolved first.
if ($origin !== null && filter_var($origin, \FILTER_VALIDATE_IP) === false) {
    $resolved = gethostbyname($origin);
    if ($resolved === $origin) {
        Cli::error(\sprintf('Cannot resolve --origin=%s to an IP address.', $origin));
        exit(1);
    }
    $origin = $resolved;
}
$lang = (string) (Network::geo($code)['geo']['hreflang'] ?? $code);

$failures = [];
$checks = 0;

/**
 * @return array{status: int, body: string, error: string, time: float}
 */
$fetch = static function (string $url) use ($origin, $host, $port, $timeout): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        \CURLOPT_RETURNTRANSFER => true,
        \CURLOPT_FOLLOWLOCATION => false,
        \CURLOPT_TIMEOUT        => $timeout,
        \CURLOPT_CONNECTTIMEOUT => $timeout,
        \CURLOPT_USERAGENT      => 'aigf-smoke/1.0',
    ]);
    if ($origin !== null) {
        curl_setopt($ch, \CURLOPT_RESOLVE, [\sprintf('%s:%d:%s', $host, $port, $origin)]);
        // Origin certificates (e.g. Cloudflare's) are not signed by a public CA.
        curl_setopt($ch, \CURLOPT_SSL_VERIFYPEER, false);
    }
    $body = curl_exec($ch);
    $result = [
        'status' => (int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE),
        'body'   => \is_string($body) ? $body : '',
        'error'  => curl_error($ch),
        'time'   => (float) curl_getinfo($ch, \CURLINFO_TOTAL_TIME),
    ];
    curl_close($ch);

    return $result;
};

$check = static function (string $name, callable $test) use (&$failures, &$checks, $quiet): void {
    $checks++;
    $problem = $test();
    if ($problem === null) {
        if (!$quiet) {
            Cli::ok($name);
        }

        return;
    }
    $failures[] = $name . ': ' . $problem;
    Cli::error($name . ': ' . $problem);
};

Cli::title(\sprintf('Smoke test %s (%s%s)', strtoupper($code), $host, $origin !== null ? ' via ' . $origin : ''));

/* ---- homepage ----------------------------------------------------------- */
$home = $fetch($baseUrl);
$check('homepage responds', static function () use ($home) {
    if ($home['error'] !== '') {
        return $home['error'];
    }

    return $home['status'] === 200 ? null : 'HTTP ' . $home['status'];
});

if ($home['status'] === 200) {
    // Guards against the classic multi-vhost mistake: the wrong site answering
    // on this server_name, which returns 200 and looks fine from the outside.
    $check('homepage is the right site', static function () use ($home, $lang, $host) {
        if (!preg_match('/<html[^>]*\blang=["\']?([a-zA-Z-]+)/i', $home['body'], $m)) {
            return 'no <html lang> attribute';
        }
        if ($m[1] !== $lang) {
            return \sprintf('serving lang "%s", expected "%s" — wrong site on this vhost?', $m[1], $lang);
        }

        $canonical = null;
        if (preg_match_all('/<link\b[^>]*>/i', $home['body'], $links)) {
            foreach ($links[0] as $link) {
                if (preg_match('/rel=["\']?canonical\b/i', $link) && preg_match('/href=["\']?([^"\'> ]+)/i', $link, $href)) {
                    $canonical = $href[1];
                    break;
                }
            }
        }
        if ($canonical === null) {
            return 'no canonical link';
        }
        if (!str_contains($canonical, $host)) {
            return 'canonical points at another domain: ' . $canonical;
        }

        return null;
    });
}

/* ---- robots.txt --------------------------------------------------------- */
$robots = $fetch($baseUrl . 'robots.txt');
$staging = Network::isStaging($code);
$check('robots.txt', static function () use ($robots, $host, $staging) {
    if ($robots['status'] !== 200) {
        return 'HTTP ' . $robots['status'];
    }
    if ($staging) {
        return str_contains($robots['body'], 'Disallow: /') ? null : 'staging host is not disallowed for crawlers';
    }
    if (!str_contains($robots['body'], $host . '/sitemap.xml')) {
        return 'does not reference this site\'s sitemap';
    }

    return null;
});

/* ---- sitemap ------------------------------------------------------------ */
$sitemap = $fetch($baseUrl . 'sitemap.xml');
$locations = [];
$check('sitemap.xml', static function () use ($sitemap, &$locations) {
    if ($sitemap['status'] !== 200) {
        return 'HTTP ' . $sitemap['status'];
    }
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($sitemap['body']);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if ($xml === false) {
        return 'not valid XML';
    }
    foreach ($xml->url as $url) {
        $locations[] = (string) $url->loc;
    }
    if ($locations === []) {
        return 'contains no URLs';
    }

    return null;
});

/* ---- a few real pages --------------------------------------------------- */
if ($locations !== []) {
    // Sitemap URLs are always the production ones; the request may go somewhere
    // else (an origin IP, a local preview port), so only the path is reused.
    $productionBase = rtrim(Network::baseUrl($code), '/');
    shuffle($locations);
    foreach (\array_slice($locations, 0, 3) as $location) {
        $path = str_replace($productionBase, '', $location);
        $page = $fetch($baseUrl . ltrim($path, '/'));
        $check('page ' . ($path === '' ? '/' : $path), static function () use ($page) {
            if ($page['error'] !== '') {
                return $page['error'];
            }
            if ($page['status'] !== 200) {
                return 'HTTP ' . $page['status'];
            }
            if (!preg_match('/<h1[\s>]/i', $page['body'])) {
                return 'no <h1> — page rendered empty?';
            }

            return null;
        });
    }
}

/* ---- 404 ---------------------------------------------------------------- */
$missing = $fetch($baseUrl . 'aigf-smoke-' . bin2hex(random_bytes(4)) . '/');
$check('unknown URL returns 404', static function () use ($missing) {
    return $missing['status'] === 404 ? null : 'HTTP ' . $missing['status'];
});

/* ---- verdict ------------------------------------------------------------ */
Cli::line('');
if ($failures === []) {
    Cli::ok(\sprintf('%d/%d checks passed (homepage %.2fs)', $checks, $checks, $home['time']));
    exit(0);
}

Cli::error(\sprintf('%d of %d checks failed', \count($failures), $checks));
exit(1);
