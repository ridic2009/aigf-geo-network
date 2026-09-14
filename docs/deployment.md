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
restores the visitor IP in the access logs.

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
GitHub Actions ──rsync┤
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

## When GitHub Actions is not available

Actions can be blocked for reasons that have nothing to do with this repository:
exhausted minutes on a private repo, a billing problem, an account restriction.
The symptom is a run that ends in `startup_failure` in a second, with no job logs
— and it happens even to a three-line hello-world workflow. Check
<https://github.com/settings/billing> first.

There are three ways out; none of them requires changing project code.

### A. Self-hosted runner (keeps the GitHub pipeline)

Jobs on your own runner do **not** consume GitHub-hosted minutes, so this works
even when the hosted ones are cut off. Install the runner from the repository's
*Settings → Actions → Runners*, then set a repository **variable**:

```
RUNNER_LABEL = self-hosted
```

Both workflows read `runs-on: ${{ vars.RUNNER_LABEL || 'ubuntu-latest' }}`, so
that single variable moves the whole pipeline. Unset it to go back.

The runner needs the PHP CLI toolchain (see the package list in
`infra/install-auto-deploy.sh`). Use a self-hosted runner only on a **private**
repository: on a public one, a pull request could run arbitrary code on it.

### B. Pull-based deployment on the VPS (no Actions at all)

The server watches the repository itself and does exactly what CI would do.
This is the most robust option: it keeps working whatever GitHub decides about
Actions.

```
every 2 minutes:  git fetch -> anything new? -> validate -> build -> release switch
```

Setup, after `infra/server-setup.sh`:

```bash
# 1. a read-only deploy key for the repository
ssh-keygen -t ed25519 -C "aigf-repo-readonly" -f .secrets/repo_deploy_key -N ""
gh api repos/<owner>/<repo>/keys -f title="VPS auto-deploy" \
       -f key="$(cat .secrets/repo_deploy_key.pub)" -F read_only=true

# 2. put the private half on the server
scp .secrets/repo_deploy_key root@<VPS_IP>:/home/deploy/.ssh/repo_key

# 3. install the timer
scp infra/install-auto-deploy.sh root@<VPS_IP>:/tmp/
ssh root@<VPS_IP> "REPO_SSH_URL=git@github.com:<owner>/<repo>.git bash /tmp/install-auto-deploy.sh"
```

Operating it:

```bash
systemctl status aigf-auto-deploy.timer          # is it running
tail -f /srv/aigf-network/var/auto-deploy.log    # what it did
sudo -u deploy /srv/aigf-network/infra/auto-deploy.sh --force   # publish now
systemctl disable --now aigf-auto-deploy.timer   # stop watching
```

Every guarantee of the CI pipeline still applies: validation and build failures
abort before anything is published, the release switch is atomic, and a release
that fails its smoke test is rolled back automatically. The editorial workflow is
unchanged — Pages CMS commits, the site updates a couple of minutes later.

The trade-off: the PHP CLI and Composer are installed on the origin. Nothing
web-facing changes — there is still no PHP-FPM and Nginx still serves only static
files — but the server is no longer purely a file server. Move back to CI when
Actions works again and `systemctl disable --now aigf-auto-deploy.timer`.

### C. Deploy from a workstation

Always available, nothing to install on the server:

```bash
./scripts/build-all --optimize
./scripts/deploy-all
```

Fine while you are the only one publishing. It stops being fine once editors
publish through the CMS and expect the site to update on its own.

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

## Affiliate redirect service (optional)

By default a partner URL is written straight into the HTML, so changing it means
rebuilding every page that mentions the product. The redirect service moves that
indirection to the edge:

```
https://go.example.com/candy-ai/de   ->  302  ->  partner URL
```

It is **not** an application: `scripts/redirect-config` generates an Nginx `map`
from `data/affiliates/`, so the origin stays static-only.

```bash
./scripts/redirect-config --enable=go.example.com   # writes affiliate.redirect_base
./scripts/build-all --optimize                      # CTAs now point at the redirect host
```

Then install it once:

```bash
rsync -az infra/nginx/snippets/affiliate-map.conf root@VPS:/etc/nginx/snippets/aigf-affiliate-map.conf
rsync -az infra/nginx/sites/go.example.com.conf    root@VPS:/etc/nginx/sites-available/
ssh root@VPS 'ln -sf /etc/nginx/sites-available/go.example.com.conf /etc/nginx/sites-enabled/ && nginx -t && systemctl reload nginx'
```

Afterwards, changing a partner link is `./scripts/redirect-config` plus copying
one file — no rebuild, no cache invalidation, and every click is in one access
log per product and per GEO. Redirects are 302 (destinations change), the host
sends `X-Robots-Tag: noindex` and serves `Disallow: /`.

`./scripts/redirect-config --disable` puts the direct links back.

Prerequisites: a DNS record for `go.example.com`, a certificate for it, and the
`include /etc/nginx/snippets/aigf-affiliate-map.conf;` line inside `http{}`
(`infra/server-setup.sh` adds it on new servers).

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
| Did the build or the validation fail, and why? | GitHub Actions run log + job summary |
| Which GEOs were in the last deploy? | Job summary of the deploy workflow |
| Traffic and errors | Nginx `access.log` / `error.log` per domain; Cloudflare analytics |

## Secrets

`DEPLOY_HOSTS`, `DEPLOY_USER`, `DEPLOY_PORT`, `DEPLOY_ROOT`, `DEPLOY_SSH_KEY` live
in GitHub Secrets (repository or the `production` environment). Never in the
repository, never in a config file, never in a log.
