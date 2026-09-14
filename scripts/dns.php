<?php

declare(strict_types=1);

/**
 * scripts/dns <status|apply|failover> [geo…] [--ip=IP] [--confirm]
 *
 * Cloudflare DNS for the network. Read-only by default: nothing is written
 * without --confirm, and every change is printed before it happens.
 *
 *   ./scripts/dns status                     where every domain points right now
 *   ./scripts/dns apply --confirm            point every domain at the primary server
 *   ./scripts/dns apply jp nl --confirm      only these markets (adding a GEO)
 *   ./scripts/dns failover --ip=198.51.100.7 --confirm   switch the whole network
 *
 * Needs CLOUDFLARE_API_TOKEN with Zone:Read + DNS:Edit on the relevant zones.
 * The default IP is the first entry of DEPLOY_HOSTS.
 *
 * Nothing in the build depends on this script: DNS stays a deliberate, separate
 * operation, it is just no longer a manual click-through for 25 domains.
 */

require __DIR__ . '/../vendor/autoload.php';

use AiGf\Tools\Cli;
use AiGf\Tools\Network;

const API_BASE_DEFAULT = 'https://api.cloudflare.com/client/v4';

[$args, $options] = Cli::parse($argv);

$command = $args[0] ?? 'status';
if (!\in_array($command, ['status', 'apply', 'failover'], true)) {
    Cli::error('Usage: scripts/dns <status|apply|failover> [geo…] [--ip=IP] [--confirm]');
    exit(1);
}

$codes = Cli::resolveGeos(\array_slice($args, 1));
$confirm = !empty($options['confirm']);
$apiBase = rtrim((string) (getenv('CLOUDFLARE_API_BASE') ?: API_BASE_DEFAULT), '/');

$token = (string) getenv('CLOUDFLARE_API_TOKEN');
if ($token === '') {
    Cli::error('CLOUDFLARE_API_TOKEN is not set.');
    Cli::info('Create a token with Zone:Read + DNS:Edit and export it, or add it to .env');
    exit(1);
}

/* target IP */
$ip = (string) ($options['ip'] ?? '');
if ($ip === '') {
    $hosts = preg_split('/[\s,]+/', trim((string) (getenv('DEPLOY_HOSTS') ?: getenv('DEPLOY_HOST') ?: ''))) ?: [];
    $ip = (string) ($hosts[0] ?? '');
}
if ($command !== 'status') {
    if ($ip === '' || filter_var($ip, \FILTER_VALIDATE_IP) === false) {
        Cli::error('No target IP: pass --ip=203.0.113.10 or set DEPLOY_HOSTS.');
        exit(1);
    }
}

/**
 * @return array{ok: bool, result: mixed, errors: string}
 */
function api(string $method, string $path, ?array $payload = null): array
{
    global $token, $apiBase;

    $ch = curl_init($apiBase . $path);
    curl_setopt_array($ch, [
        \CURLOPT_RETURNTRANSFER => true,
        \CURLOPT_CUSTOMREQUEST  => $method,
        \CURLOPT_TIMEOUT        => 20,
        \CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
    ]);
    if ($payload !== null) {
        curl_setopt($ch, \CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if (!\is_string($raw)) {
        return ['ok' => false, 'result' => null, 'errors' => $error ?: 'no response'];
    }

    $data = json_decode($raw, true);
    if (!\is_array($data)) {
        return ['ok' => false, 'result' => null, 'errors' => 'unexpected response'];
    }

    $messages = [];
    foreach ((array) ($data['errors'] ?? []) as $item) {
        $messages[] = (string) ($item['message'] ?? 'unknown error');
    }

    return [
        'ok'     => ($data['success'] ?? false) === true,
        'result' => $data['result'] ?? null,
        'errors' => implode('; ', $messages),
    ];
}

/** Cloudflare zone id for a domain (the zone is usually the domain itself). */
function zoneId(string $domain): ?string
{
    static $cache = [];
    if (\array_key_exists($domain, $cache)) {
        return $cache[$domain];
    }

    $parts = explode('.', $domain);
    // try the full name first, then progressively shorter suffixes (sub.example.com -> example.com)
    while (\count($parts) >= 2) {
        $candidate = implode('.', $parts);
        $response = api('GET', '/zones?name=' . urlencode($candidate));
        if ($response['ok'] && !empty($response['result'])) {
            return $cache[$domain] = (string) $response['result'][0]['id'];
        }
        array_shift($parts);
    }

    return $cache[$domain] = null;
}

/* ------------------------------------------------------------------ run */

Cli::title(\sprintf('Cloudflare DNS — %s%s', $command, $command === 'status' ? '' : ' -> ' . $ip));
if ($command !== 'status' && !$confirm) {
    Cli::warn('Dry run: nothing will be changed. Add --confirm to apply.');
}

$changes = 0;
$problems = 0;

foreach ($codes as $code) {
    $domain = Network::host($code);
    $zone = zoneId($domain);

    if ($zone === null) {
        Cli::error(\sprintf('%s: no Cloudflare zone found (is the domain on this account, and does the token cover it?)', $domain));
        $problems++;
        continue;
    }

    foreach ([$domain, 'www.' . $domain] as $name) {
        $response = api('GET', \sprintf('/zones/%s/dns_records?name=%s', $zone, urlencode($name)));
        if (!$response['ok']) {
            Cli::error(\sprintf('%s: %s', $name, $response['errors']));
            $problems++;
            continue;
        }

        $records = (array) $response['result'];
        $existing = null;
        foreach ($records as $record) {
            if (\in_array($record['type'], ['A', 'AAAA', 'CNAME'], true)) {
                $existing = $record;
                break;
            }
        }

        if ($command === 'status') {
            if ($existing === null) {
                Cli::warn(\sprintf('%-40s missing', $name));
            } else {
                Cli::info(\sprintf(
                    '%-40s %-5s %-16s %s',
                    $name,
                    $existing['type'],
                    $existing['content'],
                    ($existing['proxied'] ?? false) ? 'proxied' : 'DNS only'
                ));
            }
            continue;
        }

        $payload = [
            'type'    => 'A',
            'name'    => $name,
            'content' => $ip,
            'ttl'     => 1,     // automatic
            'proxied' => true,  // Cloudflare in front of the origin
        ];

        if ($existing !== null && $existing['type'] === 'A' && $existing['content'] === $ip && ($existing['proxied'] ?? false)) {
            Cli::info(\sprintf('%-40s already %s', $name, $ip));
            continue;
        }

        $was = $existing === null ? 'missing' : $existing['type'] . ' ' . $existing['content'];
        if (!$confirm) {
            Cli::info(\sprintf('%-40s %s -> A %s (proxied)', $name, $was, $ip));
            $changes++;
            continue;
        }

        $write = $existing === null
            ? api('POST', \sprintf('/zones/%s/dns_records', $zone), $payload)
            : api('PUT', \sprintf('/zones/%s/dns_records/%s', $zone, $existing['id']), $payload);

        if ($write['ok']) {
            Cli::ok(\sprintf('%-40s %s -> A %s (proxied)', $name, $was, $ip));
            $changes++;
        } else {
            Cli::error(\sprintf('%-40s %s', $name, $write['errors']));
            $problems++;
        }
    }
}

Cli::line('');
if ($command === 'status') {
    Cli::info(\sprintf('%d domain(s) checked, %d problem(s).', \count($codes), $problems));
} elseif ($confirm) {
    Cli::info(\sprintf('%d record(s) changed, %d problem(s).', $changes, $problems));
    Cli::info('Cloudflare serves the new origin within seconds; visitors never see the IP.');
} else {
    Cli::info(\sprintf('%d record(s) would change. Re-run with --confirm.', $changes));
}

exit($problems > 0 ? 1 : 0);
