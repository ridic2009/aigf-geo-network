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
 */

require __DIR__ . '/../vendor/autoload.php';

use AiGf\Tools\Cli;
use AiGf\Tools\Network;

[$args, $options] = Cli::parse($argv);
$codes = Cli::resolveGeos($args);
$httpOnly = !empty($options['http-only']);

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
# Fingerprinted assets (Cecil adds a content hash to CSS/JS file names):
# safe to cache forever.
location ~* "\.[0-9a-f]{8,}\.(css|js)$" {
    expires 1y;
    add_header Cache-Control "public, immutable";
    access_log off;
}

# Images and fonts: long cache, revalidated by the browser.
location ~* \.(?:svg|png|jpe?g|webp|avif|ico|woff2?)$ {
    expires 30d;
    add_header Cache-Control "public";
    access_log off;
}

# HTML must always be revalidated so a release switch is visible immediately.
location ~* \.html$ {
    add_header Cache-Control "public, max-age=0, must-revalidate";
}

location = /sitemap.xml {
    add_header Cache-Control "public, max-age=3600";
}

location = /robots.txt {
    add_header Cache-Control "public, max-age=3600";
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
    $root = '/srv/www/' . $domain . '/current';
    $name = strtoupper($code);

$common = <<<CONF
    root {$root};
    index index.html;

    charset utf-8;

    include /etc/nginx/snippets/aigf-security-headers.conf;
    include /etc/nginx/snippets/aigf-static-cache.conf;

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
    listen 80;
    listen [::]:80;
    server_name {$domain} www.{$domain};

{$common}
}

CONF;
    } else {
$vhost = <<<CONF
# {$name} — {$domain}
# GENERATED by scripts/nginx-config. Do not edit on the server: regenerate and
# redeploy, otherwise the next release silently overwrites your change.

server {
    listen 80;
    listen [::]:80;
    server_name {$domain} www.{$domain};

    # Let certbot renew without taking the site down.
    location ^~ /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        return 301 https://{$domain}\$request_uri;
    }
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name www.{$domain};

    ssl_certificate     /etc/letsencrypt/live/{$domain}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/{$domain}/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;

    return 301 https://{$domain}\$request_uri;
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
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
}

Cli::title('Install on the server');
Cli::info('rsync -az infra/nginx/snippets/  root@VPS:/tmp/aigf-snippets/');
Cli::info('ssh root@VPS "for f in /tmp/aigf-snippets/*.conf; do cp \\$f /etc/nginx/snippets/aigf-\\$(basename \\$f); done"');
Cli::info('rsync -az infra/nginx/sites/     root@VPS:/etc/nginx/sites-available/');
Cli::info('ssh root@VPS "ln -sf /etc/nginx/sites-available/DOMAIN.conf /etc/nginx/sites-enabled/ && nginx -t && systemctl reload nginx"');
