# Deployment

## Target

A small Ubuntu VPS (1–2 vCPU, 1–2 GB RAM, 20–40 GB disk) serving static files.
No PHP-FPM, no Node, no database — the build happens in CI.

```
/srv/www/<domain>/
├── releases/
│   ├── 20260914-210100/     ← previous
│   └── 20260914-223500/     ← current
├── current -> releases/20260914-223500/
└── releases.log
```

Nginx's `root` points at `current`. A deploy uploads a new release directory and
then moves the symlink in one atomic rename, so a visitor never sees a
half-uploaded site and a rollback is the same rename in reverse.

## One-off server provisioning

```bash
scp infra/server-setup.sh root@VPS:/tmp/
ssh root@VPS "bash /tmp/server-setup.sh"
```

Installs Nginx, rsync, certbot, ufw and fail2ban; creates the `deploy` user and
`/srv/www`; wires the shared Nginx snippets into `nginx.conf`; opens the
firewall. The deploy user is allowed to run exactly two commands as root:
`nginx -t` and `systemctl reload nginx`.

Then add the CI public key:

```bash
ssh-keygen -t ed25519 -C "aigf-deploy" -f deploy_key
ssh root@VPS "cat >> /home/deploy/.ssh/authorized_keys" < deploy_key.pub
```

Put the **private** key in the GitHub secret `DEPLOY_SSH_KEY`.

## Nginx

```bash
./scripts/nginx-config              # all GEOs, HTTPS vhosts
./scripts/nginx-config de --http-only   # HTTP only, for the very first deploy
```

Two options matter when the server is not exclusively ours:

| Option | When |
| ------ | ---- |
| `--listen=IP` | Nginx is shared with something else (a control panel) that already binds a specific address. Mixing `listen 80` and `listen <ip>:80` in one nginx breaks `default_server` selection. |
| `--web-root=/path` | `/srv/www` is taken. On Git Bash, prefix the command with `MSYS_NO_PATHCONV=1`, otherwise the shell rewrites the path into `C:/Program Files/Git/...` — the script detects that and refuses rather than writing a broken vhost. |

Install:

```bash
rsync -az infra/nginx/snippets/ root@VPS:/tmp/aigf-snippets/
ssh root@VPS 'for f in /tmp/aigf-snippets/*.conf; do cp "$f" "/etc/nginx/snippets/aigf-$(basename "$f")"; done'
rsync -az infra/nginx/sites/ root@VPS:/etc/nginx/sites-available/
ssh root@VPS 'ln -sf /etc/nginx/sites-available/DOMAIN.conf /etc/nginx/sites-enabled/ && nginx -t && systemctl reload nginx'
```

Each vhost: HTTP→HTTPS redirect, `www`→apex redirect, pretty URLs via
`try_files`, `error_page 404 /404.html`, security headers, immutable caching for
fingerprinted assets, always-revalidate for HTML, dotfiles denied.

The worldwide edition (`x_default` in `config/network.yml`) also sends a visitor
on from its homepage, and only from there, to the site of their own country:
a 302, decided by the `CF-IPCountry` header Cloudflare adds. Every enabled
market whose language is not the worldwide edition's takes part; one in the same
language (US, AU, CA, NZ) does not. A Belgian whose browser asks for French goes
to the French site rather than the Dutch Belgian one. Search engines, link
previews and anything that is not a browser always get the page itself, so the
worldwide edition stays indexed and a shared link keeps its card. Without the
header (no Cloudflare in front), nothing is redirected.

Never edit a vhost on the server — regenerate and redeploy, otherwise the next
`nginx-config` run silently overwrites the change.

### Certificates

The HTTPS vhost expects Let's Encrypt paths, so issue the certificate before
enabling it (or deploy once with `--http-only`):

```bash
ssh root@VPS "certbot certonly --webroot -w /var/www/certbot \
    -d aigirlfriendranking-germany.site -d www.aigirlfriendranking-germany.site"
```

Renewal runs from `certbot.timer`. The port-80 vhost keeps serving
`/.well-known/acme-challenge/` from `/var/www/certbot`, so renewals never need
downtime.

### Cloudflare

```
Registrar → Cloudflare DNS → VPS
```

A record per domain, proxy enabled. SSL/TLS mode **Full (strict)** so the origin
certificate is actually validated. `infra/nginx/snippets/cloudflare-real-ip.conf`
holds Cloudflare's ranges and `infra/nginx/http/cloudflare-real-ip.conf` includes it
in `http{}` (as `/etc/nginx/conf.d/aigf-cloudflare-real-ip.conf`), so every log has
the visitor's address. Until 2026-10-07 nothing included the snippet and the logs
showed Cloudflare's edges.

The application is not tied to the Cloudflare API: DNS is a manual step in v1,
and automating it later changes nothing in the build or the deploy.

## Deploying

From CI (normal case): push to `main`, or run the **Build and deploy** workflow
manually with an optional GEO input.

By hand:

```bash
# one or more servers, comma or space separated — or put these in .env
export DEPLOY_HOSTS=203.0.113.10,198.51.100.7 DEPLOY_USER=deploy

./scripts/deploy de --build           # one GEO, every server
./scripts/deploy-all                  # every GEO, every server
./scripts/deploy-all --host=198.51.100.7   # every GEO, one server only
```

The deploy refuses to run if `dist/<domain>/index.html` is missing and, on the
server, refuses to switch `current` unless the new release contains both
`index.html` and `sitemap.xml`. If any server fails, the script exits non-zero
(so CI goes red) while the servers that succeeded keep serving the new release
and the failed one keeps serving its previous release.

`KEEP_RELEASES` (default 5) old releases are kept and older ones pruned.

## Second server (backup / failover)

The deploy scripts take a **list** of servers. Adding a second VPS means every
release is pushed to both, so the backup is always a byte-identical copy of
production, ready to take over by changing DNS.

```
                      ┌─ 203.0.113.10  (primary)
VPS publisher ──rsync─┤
                      └─ 198.51.100.7  (backup, identical)
                             ▲
                   Cloudflare points at one of them
```

### Adding it

1. **Provision it exactly like the first one:**

   ```bash
   scp infra/server-setup.sh root@198.51.100.7:/tmp/
   ssh root@198.51.100.7 "bash /tmp/server-setup.sh"
   ssh root@198.51.100.7 "cat >> /home/deploy/.ssh/authorized_keys" < deploy_key.pub
   ```

2. **Install the same Nginx configuration** (same commands as above, new IP).

3. **Certificates.** Let's Encrypt validates over HTTP, which only works for the
   server DNS currently points at. Two options for the backup:
   - copy the certificates from the primary and keep them in sync with a nightly
     `rsync /etc/letsencrypt`, or
   - use a **Cloudflare Origin Certificate** (valid 15 years, issued from the
     Cloudflare dashboard, no HTTP validation) and install the same file on both
     servers. This is the simpler option when Cloudflare is in front anyway.

4. **Seed it with the whole network:**

   ```bash
   ./scripts/build-all --optimize
   ./scripts/deploy-all --host=198.51.100.7
   ```

5. **Make it permanent** — add the second IP to the `DEPLOY_HOSTS` secret:

   ```
   DEPLOY_HOSTS = 203.0.113.10,198.51.100.7
   ```

   From now on every CI deploy updates both servers.

### Verifying the backup without touching DNS

```bash
curl -sI --resolve aigirlfriendranking.com:443:198.51.100.7 \
     https://aigirlfriendranking.com/ | head -3
```

This asks the backup server directly while still sending the right `Host` and
SNI, so you see exactly what visitors would get after a failover.

### Failing over

Manual (works on any Cloudflare plan): in Cloudflare DNS, change the A record of
each domain from the primary IP to the backup IP. With the orange cloud enabled
Cloudflare serves the new origin within seconds — visitors never see the
underlying IP, so no DNS propagation delay for them.

Automatic (Cloudflare Load Balancing, paid add-on): create a pool with both
origins, attach a health check on `/robots.txt`, and Cloudflare removes a dead
origin on its own. The application needs no change for this — both servers
already serve identical content.

Failing back is the same operation in reverse. Because both servers keep the
same release ids, `./scripts/rollback <geo> <release>` means the same thing on
either of them.

## Rolling back

```bash
./scripts/rollback de --list                 # what each server has
./scripts/rollback de                        # previous release, every server
./scripts/rollback de 20260914-210100        # specific release, every server
./scripts/rollback de --host=203.0.113.10    # one server only
```

No rebuild, no upload — one symlink move, about a second. Every switch and
rollback is appended to `/srv/www/<domain>/releases.log`:

```
2026-09-14T22:35:00Z	20260914-223500	a1b2c3d4…
2026-09-14T23:02:11Z	rollback	20260914-210100
```

## Who publishes

A timer on the VPS, and nothing else. `infra/auto-deploy.sh` runs every two
minutes, and when `main` has moved it does the whole pipeline: validation, Cecil
build per GEO, output verification, atomic release switch, smoke test, rollback
if the smoke test fails. Nothing else may publish at the same time — two
publishers would take turns overwriting each other.

```sh
sudo -u deploy /srv/aigf-network/infra/auto-deploy.sh           # run it now
sudo -u deploy /srv/aigf-network/infra/auto-deploy.sh --force   # rebuild anyway
journalctl -u aigf-deploy.service                               # what it did
tail -f /srv/aigf-network/var/auto-deploy.log
```

Install it with `infra/install-auto-deploy.sh`, which also creates the clone,
the PHP toolchain and the timer. The deploy key it needs is a read-only GitHub
deploy key at `/home/deploy/.ssh/repo_key`.

Building on the same host it serves from is the simple case: the release scripts
are pointed at `localhost`. Deploying to *other* servers needs `DEPLOY_HOSTS`,
`DEPLOY_USER`, `DEPLOY_PORT` and `DEPLOY_ROOT` in the environment file, plus an
SSH key with `known_hosts` for that account.

### GitHub Actions

`.github/workflows/deploy.yml` can do the same job, and validates and builds
every push in any case. Its deploy step is opt-in: set the repository variable
`PUBLISHER=actions` **and** stop the VPS timer, or the two will fight.

> As of 2026-09-17 every Actions run on this repository ends in
> `startup_failure` at zero seconds — an account-level problem, most likely the
> spending limit on a private repository. Until that is resolved the VPS timer
> is the only publisher, which is why it does not depend on Actions.

### Publishing from a workstation

Always available, nothing extra on the server:

```sh
./scripts/build-all --optimize
./scripts/deploy-all
```

Use this to seed a new server, to recover, or when the VPS timer is stopped. It
publishes whatever is in your working copy, not what is on `main`.
## Post-deploy smoke test

Every release switch is followed by a check of the server that just switched,
sent directly to its IP with the real Host and SNI:

| Check | Why |
| ----- | --- |
| homepage returns 200 | the obvious one |
| `<html lang>` matches the GEO, canonical points at this domain | catches the classic mistake of the wrong vhost answering — returns 200 and looks fine from outside |
| `robots.txt` references this site's sitemap | catches a stale or cross-wired release |
| `sitemap.xml` parses and is not empty | catches a build that produced nothing |
| three random pages from the sitemap return 200 and contain an `<h1>` | catches a partially uploaded release |
| an unknown URL returns 404 | catches a misconfigured `try_files` that would turn the whole site into soft-404s |

If a server fails, **it is rolled back to its previous release automatically**
and the deploy exits non-zero. Other servers are unaffected.

```bash
./scripts/smoke us                          # through Cloudflare, as a visitor sees it
./scripts/smoke us --origin=198.51.100.7    # one specific server, bypassing DNS
./scripts/deploy us --no-smoke              # skip it (first deploy, before TLS exists)
```

With `--origin`, certificate trust is relaxed, because a Cloudflare Origin
Certificate is not signed by a public CA.

> Running the smoke test against a local `php -S` preview reports a false failure
> on the 404 check: the PHP development server answers 200 with the homepage for
> unknown directories. Nginx (`try_files … =404`) does not.

## DNS automation

`./scripts/dns` talks to the Cloudflare API so that 25 domains are not 25 manual
click-throughs. It is read-only unless you pass `--confirm`.

```bash
export CLOUDFLARE_API_TOKEN=…            # Zone:Read + DNS:Edit

./scripts/dns status                     # where every domain points, and whether it is proxied
./scripts/dns apply --confirm            # point every domain at the primary server
./scripts/dns apply jp nl --confirm      # only these markets (launching a GEO)
./scripts/dns failover --ip=198.51.100.7 --confirm
```

It manages the apex record and `www` (which the vhost redirects to the apex),
always as a proxied `A` record with automatic TTL. The target IP defaults to the
first entry of `DEPLOY_HOSTS`. Without `--confirm` it prints exactly what it
would change and exits.

Nothing in the build depends on this: DNS stays a deliberate, separate step.

## Partner clicks and the traffic report

Every partner button on a ConvertStudio site points at the site's own address,
`/go/<product>/?from=<block>`, never at the partner. That address is the click
counter:

```
reader -> https://<domain>/go/candy-ai/?from=hero
       -> 302 -> partner link of this market       (written by the root helper)
       -> one JSON line in /var/log/nginx/aigf-clicks.log
```

* The release carries `outbound.json` (product -> partner link of the site's
  market). `minicms-redirects`, run by `converge.sh` every two minutes, turns
  it into `location = /go/<id>/ { return 302 "..."; }` in
  `/etc/nginx/aigf-outbound/<domain>/outbound.conf`, nested in the vhost's
  `location ^~ /go/`. A link with a character that cannot sit in a quoted Nginx
  string (`"`, `\`, `$`, spaces, braces) is left out rather than escaped.
* The release also carries `go/<id>/index.html`, a page that sends the reader on
  at once. It answers in the minutes between a release and the next converge,
  and for any link the helper left out, so a button is never dead.
* `/go/` sends `Cache-Control: private, no-store` and `X-Robots-Tag: noindex`;
  `robots.txt` disallows it.
* Every page a reader is given (a GET answered 200/304 at an address ending in
  `/`) is one line in `/var/log/nginx/aigf-views.log`, without the reader's
  address. Both formats are in `infra/nginx/http/traffic.conf` (installed as
  `/etc/nginx/conf.d/aigf-traffic.conf`).
* `aigf-traffic.timer` runs `/usr/local/sbin/aigf-traffic`
  (`infra/aggregate-traffic.py`) every ten minutes. It writes one file per UTC
  day to `/var/lib/aigf-traffic/<date>.json`: views by page, source, country and
  device; clicks by page, product, block, country and device, with repeat
  presses of the same reader on the same day counted once; robots counted apart.
  A robot is anything that is not a person's browser: a program that names
  itself, a user-agent no browser sends (`compatible;`, WebKit without
  `(KHTML, like Gecko)`, Windows XP/Vista), a browser far out of date (Chrome
  more than ~15 months behind, Firefox more than two years, iOS/Safari before
  15), a request a browser makes ahead of the reader (`Sec-Purpose: prefetch`),
  and, once the Sec-Fetch headers are logged, a page that is not a navigation to
  a document or a press that is not a same-site navigation by a user. A press
  must come from a page of the same site (Referer); a reader who presses 4
  buttons of 3 partners within a minute, or 10 in a day, is a program.
  No address or browser string is written. Today and yesterday are recounted on
  every run; an older day is filled in once while the logs (two weeks) still
  hold it.
* ConvertStudio reads that directory (`CSTUDIO_TRAFFIC=/var/lib/aigf-traffic`
  in its `.env`) and shows it under «Переходы к партнёрам», with an estimated revenue
  from the EPC its catalogue states per product and market.

Check it by hand:

```bash
curl -sI https://<domain>/go/<product>/ | grep -i '^location'
tail -n 3 /var/log/nginx/aigf-clicks.log
systemctl list-timers aigf-traffic.timer
journalctl -u aigf-traffic.service -n 5
ls -l /var/lib/aigf-traffic | tail -n 3
```

`scripts/redirect-config` is what the Cecil build used for the same job
(`go.<domain>`, `affiliate.redirect_base`); it was never enabled, and
ConvertStudio does not read it.

## What is deployed, and what is not

Only `dist/<domain>/`: HTML, one fingerprinted stylesheet, one script, images,
`robots.txt`, `sitemap.xml`, `404.html`. No sources, no `vendor/`, no config, no
Markdown.

A failed validation or build exits non-zero before the deploy job starts, so
production keeps serving the previous release.

## Observability

| Question | Where to look |
| -------- | ------------- |
| What was published, when, from which commit? | `/srv/www/<domain>/releases.log` |
| Which release is live? | `readlink /srv/www/<domain>/current` |
| Did the build or the validation fail, and why? | `var/auto-deploy.log`, or `journalctl -u aigf-deploy.service` |
| Which commit is published? | `var/last-successful-commit` |
| Traffic and errors | Nginx `access.log` / `error.log` per domain; Cloudflare analytics |
| Partner clicks and page views | `/var/lib/aigf-traffic/<date>.json`, «Переходы к партнёрам» in ConvertStudio; raw lines in `aigf-clicks.log` / `aigf-views.log` |

## Secrets

`DEPLOY_HOSTS`, `DEPLOY_USER`, `DEPLOY_PORT`, `DEPLOY_ROOT`, `DEPLOY_SSH_KEY` live
in GitHub Secrets (repository or the `production` environment). Never in the
repository, never in a config file, never in a log.
