<?php

declare(strict_types=1);

/**
 * scripts/nginx-config [geo…] [--http-only]
 *
 * Generates one Nginx vhost per GEO into infra/nginx/sites/, plus the shared
 * snippets. Every vhost is derived from `baseurl`, so a domain is still written
 * down exactly once in the whole project.
 *
 * --http-only emits a port 80 vhost that serves files directly: use it for the
 * first deploy, before the TLS certificate exists.
 *
 * --listen=IP binds the vhost to one address instead of every interface. Needed
 * when nginx is shared with something else (a control panel) that already binds
 * a specific IP: mixing "listen 80" and "listen <ip>:80" in one nginx confuses
 * default_server selection.
 */

require __DIR__ . '/../vendor/autoload.php';

use AiGf\Tools\Cli;
use AiGf\Tools\Network;

[$args, $options] = Cli::parse($argv);
$codes = Cli::resolveGeos($args);
$httpOnly = !empty($options['http-only']);
$listen = isset($options['listen']) ? trim((string) $options['listen']) . ':' : '';
$listen6 = $listen === '' ? '[::]:' : '';
// Where releases live on the server. Overridable for hosts where /srv is taken.
$webRoot = (string) ($options['web-root'] ?? getenv('DEPLOY_ROOT') ?: '/srv/www');

// Git Bash on Windows rewrites "/opt/x" into "C:/Program Files/Git/opt/x" before
// PHP ever sees it, which would silently produce an unusable `root` directive.
if (preg_match('#^[A-Za-z]:[\\/]#', $webRoot)) {
    Cli::error(sprintf('"%s" was rewritten by the shell into a Windows path.', $webRoot));
    Cli::info('Re-run with MSYS_NO_PATHCONV=1, e.g.:');
    Cli::info('    MSYS_NO_PATHCONV=1 ./scripts/nginx-config --web-root=/opt/aigf/www');
    exit(1);
}

$sitesDir = Network::path('infra', 'nginx', 'sites');
$snippetsDir = Network::path('infra', 'nginx', 'snippets');
foreach ([$sitesDir, $snippetsDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0o777, true);
    }
}

/* ------------------------------------------------------------- snippets */

file_put_contents($snippetsDir . '/security-headers.conf', <<<'CONF'
# Shared security headers. Static sites: no scripts from third parties,
# no inline event handlers, no frames.
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "SAMEORIGIN" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "geolocation=(), microphone=(), camera=(), interest-cohort=()" always;
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
add_header Content-Security-Policy "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; form-action 'none'; frame-ancestors 'self'; base-uri 'self'" always;

CONF);

file_put_contents($snippetsDir . '/static-cache.conf', <<<'CONF'
# Caching rules.
#
# Every location below re-includes the security headers on purpose: in nginx,
# `add_header` directives are inherited from the parent level ONLY when the
# current level defines none of its own. A location that sets Cache-Control
# therefore silently drops every security header set on the server block —
# including for "/", which is internally rewritten to "/index.html".

# Fingerprinted assets (Cecil adds a content hash to CSS/JS file names):
# safe to cache forever.
location ~* "\.[0-9a-f]{8,}\.(css|js)$" {
    include /etc/nginx/snippets/aigf-security-headers.conf;
    add_header Cache-Control "public, max-age=31536000, immutable" always;
    access_log off;
}

# Images and fonts: long cache, revalidated by the browser.
location ~* \.(?:svg|png|jpe?g|webp|avif|ico|woff2?)$ {
    include /etc/nginx/snippets/aigf-security-headers.conf;
    add_header Cache-Control "public, max-age=2592000" always;
    access_log off;
}

# HTML must always be revalidated so a release switch is visible immediately.
location ~* \.html$ {
    include /etc/nginx/snippets/aigf-security-headers.conf;
    add_header Cache-Control "public, max-age=0, must-revalidate" always;
}

location = /sitemap.xml {
    include /etc/nginx/snippets/aigf-security-headers.conf;
    add_header Cache-Control "public, max-age=3600" always;
}

location = /robots.txt {
    include /etc/nginx/snippets/aigf-security-headers.conf;
    add_header Cache-Control "public, max-age=3600" always;
}

CONF);

file_put_contents($snippetsDir . '/compression.conf', <<<'CONF'
# Include from the http{} block of /etc/nginx/nginx.conf
gzip on;
gzip_vary on;
gzip_comp_level 6;
gzip_min_length 512;
gzip_proxied any;
gzip_types
    text/plain
    text/css
    text/xml
    text/javascript
    application/javascript
    application/json
    application/ld+json
    application/xml
    application/rss+xml
    image/svg+xml;

# Pre-compressed files, if you add a brotli/gzip step to the deploy later.
# gzip_static on;

CONF);

file_put_contents($snippetsDir . '/cloudflare-real-ip.conf', <<<'CONF'
# Restores the visitor IP when Cloudflare proxies the traffic.
# Refresh with: curl -s https://www.cloudflare.com/ips-v4 https://www.cloudflare.com/ips-v6
# (Cloudflare changes these ranges rarely; check once per quarter.)
set_real_ip_from 173.245.48.0/20;
set_real_ip_from 103.21.244.0/22;
set_real_ip_from 103.22.200.0/22;
set_real_ip_from 103.31.4.0/22;
set_real_ip_from 141.101.64.0/18;
set_real_ip_from 108.162.192.0/18;
set_real_ip_from 190.93.240.0/20;
set_real_ip_from 188.114.96.0/20;
set_real_ip_from 197.234.240.0/22;
set_real_ip_from 198.41.128.0/17;
set_real_ip_from 162.158.0.0/15;
set_real_ip_from 104.16.0.0/13;
set_real_ip_from 104.24.0.0/14;
set_real_ip_from 172.64.0.0/13;
set_real_ip_from 131.0.72.0/22;
set_real_ip_from 2400:cb00::/32;
set_real_ip_from 2606:4700::/32;
set_real_ip_from 2803:f800::/32;
set_real_ip_from 2405:b500::/32;
set_real_ip_from 2405:8100::/32;
set_real_ip_from 2a06:98c0::/29;
set_real_ip_from 2c0f:f248::/32;
real_ip_header CF-Connecting-IP;

CONF);

Cli::ok('infra/nginx/snippets/ written (security headers, cache, compression, Cloudflare real IP)');

/* --------------------------------------------------------------- vhosts */

foreach ($codes as $code) {
    $domain = Network::host($code);
    $root = rtrim($webRoot, '/') . '/' . $domain . '/current';
    $name = strtoupper($code);
    $listen6Line80 = $listen6 === '' ? '' : "    listen {$listen6}80;
";
    $listen6Line443 = $listen6 === '' ? '' : "    listen {$listen6}443 ssl;
";

$common = <<<CONF
    root {$root};
    index index.html;

    charset utf-8;

    include /etc/nginx/snippets/aigf-security-headers.conf;
    include /etc/nginx/snippets/aigf-static-cache.conf;
    # Only root-owned, validated directives are included (never release-writable code).
    include /etc/nginx/aigf-redirects/{$domain}/*.conf;
    location = /redirects.nginx.conf { deny all; }
    location = /redirects.json { deny all; }
    location = /_redirects { deny all; }

    # Pretty URLs: /reviews/candy-ai/ -> /reviews/candy-ai/index.html
    location / {
        try_files \$uri \$uri/ =404;
    }

    error_page 404 /404.html;
    location = /404.html {
        internal;
    }

    # There is no application here: nothing to probe.
    location ~ /\.(?!well-known) {
        deny all;
    }

    access_log /var/log/nginx/{$domain}.access.log;
    error_log  /var/log/nginx/{$domain}.error.log warn;
CONF;

    if ($httpOnly) {
$vhost = <<<CONF
# {$name} — {$domain}
# GENERATED by scripts/nginx-config --http-only. HTTP only: use it for the very
# first deploy, then re-run scripts/nginx-config without --http-only.

server {
    listen {$listen}80;
{$listen6Line80}    server_name {$domain} www.{$domain};

{$common}
}

CONF;
    } else {
$vhost = <<<CONF
# {$name} — {$domain}
# GENERATED by scripts/nginx-config. Do not edit on the server: regenerate and
# redeploy, otherwise the next release silently overwrites your change.

server {
    listen {$listen}80;
{$listen6Line80}    server_name {$domain} www.{$domain};

    # Let certbot renew without taking the site down.
    location ^~ /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        return 301 https://{$domain}\$request_uri;
    }
}

server {
    listen {$listen}443 ssl;
{$listen6Line443}    http2 on;
    server_name www.{$domain};

    ssl_certificate     /etc/letsencrypt/live/{$domain}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/{$domain}/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;

    return 301 https://{$domain}\$request_uri;
}

server {
    listen {$listen}443 ssl;
{$listen6Line443}    http2 on;
    server_name {$domain};

    ssl_certificate     /etc/letsencrypt/live/{$domain}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/{$domain}/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;

{$common}
}

CONF;
    }

    $file = $sitesDir . \DIRECTORY_SEPARATOR . $domain . '.conf';
    file_put_contents($file, $vhost);
    Cli::ok(\sprintf('%s: infra/nginx/sites/%s.conf', $name, $domain));
    foreach (Network::geo($code)['previous_domains'] ?? [] as $oldDomain) {
        if (!preg_match('/^[a-z0-9.-]+$/D', $oldDomain) || $oldDomain === $domain) {
            throw new RuntimeException('Invalid previous domain.');
        }
        foreach (Network::codes(false) as $other) {
            if (Network::host($other) === $oldDomain) { throw new RuntimeException('Previous domain is used by another site: ' . $oldDomain); }
        }
        $redirectVhost = "# Domain migration: keep DNS and certificates for this hostname.\nserver {\n"
            . "    listen {$listen}80;\n    server_name {$oldDomain} www.{$oldDomain};\n"
            . "    location ^~ /.well-known/acme-challenge/ { root /var/www/certbot; }\n"
            . '    location / { return 301 ' . rtrim(Network::baseUrl($code), '/') . '$request_uri; }' . "\n}\n";
        if (!$httpOnly) {
            $redirectVhost .= "server {\n    listen {$listen}443 ssl;\n    server_name {$oldDomain} www.{$oldDomain};\n"
                . "    ssl_certificate /etc/letsencrypt/live/{$oldDomain}/fullchain.pem;\n"
                . "    ssl_certificate_key /etc/letsencrypt/live/{$oldDomain}/privkey.pem;\n"
                . '    return 301 ' . rtrim(Network::baseUrl($code), '/') . '$request_uri;' . "\n}\n";
        }
        file_put_contents($sitesDir . '/' . $oldDomain . '.conf', $redirectVhost);
        Cli::ok($oldDomain . ': generated 301 redirect to ' . $domain);
    }
}

Cli::title('Install on the server');
Cli::info('rsync -az infra/nginx/snippets/  root@VPS:/tmp/aigf-snippets/');
Cli::info('ssh root@VPS "for f in /tmp/aigf-snippets/*.conf; do cp \\$f /etc/nginx/snippets/aigf-\\$(basename \\$f); done"');
Cli::info('rsync -az infra/nginx/sites/     root@VPS:/etc/nginx/sites-available/');
Cli::info('ssh root@VPS "ln -sf /etc/nginx/sites-available/DOMAIN.conf /etc/nginx/sites-enabled/ && nginx -t && systemctl reload nginx"');
