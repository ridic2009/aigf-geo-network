# Production setup runbook

Go-live checklist, in order. Every step says what it needs and how to verify it
before moving on. Nothing here is reversible-by-accident: the first real traffic
only arrives at step 8.

---

## 0. What you need before starting

| Item | Where it is used | Notes |
| ---- | ---------------- | ----- |
| Registered domains, one per GEO | `config/geos/<geo>.yml` → `baseurl` | This drives canonical, sitemap, hreflang, the Nginx vhost and the deploy target. Get these right first. |
| Ubuntu VPS (22.04/24.04), root SSH | `infra/server-setup.sh` | 1–2 vCPU, 1–2 GB RAM is enough for the whole network |
| A GitHub account | source of truth + CI | private repository is fine |
| Cloudflare account with the domains added | DNS + TLS + CDN | nameservers must already point at Cloudflare |
| Deploy keypair | CI → VPS | generated into `.secrets/` (git-ignored) |

---

## 1. Put the real domains into the configuration

The repository ships with placeholder domains. Replace them **before** the first
deploy, because changing a domain later means new canonicals, a new sitemap and
a redirect plan.

```bash
# config/geos/us.yml
baseurl: 'https://your-real-domain.com/'
```

Also update `config/network.yml` (the scaffolding presets) so future GEOs are
generated with the right domains.

Verify:

```bash
./scripts/validate
php scripts/geo.php list-all          # codes
for g in $(php scripts/geo.php list); do php scripts/geo.php host "$g"; done
```

---

## 2. Decide what goes live

The repository contains a complete demo content set (3 GEOs × 8 pages) with
**invented ratings and invented testing claims**. It exists to prove the
pipeline, not to be published.

Pick one:

- **Replace the text before launch** — keep the structure, rewrite the content
  with real testing results.
- **Launch empty** — set every page to `status: draft` except the legal pages,
  and let the editors fill it in through the CMS.

Ratings live in `data/products/<id>.yml` and feed `Review` structured data.
Publishing a rating you did not measure is a problem both for readers and for
search engines.

---

## 3. GitHub repository

```bash
git init
git add .
git commit -m "AIGF GEO network: engine, GEO configs, CMS, CI/CD"
gh repo create <owner>/<name> --private --source=. --push
```

Verify: the repository exists, `main` is the default branch, and
`.github/workflows/` is present.

---

## 4. Provision the server

```bash
scp infra/server-setup.sh root@<VPS_IP>:/tmp/
ssh root@<VPS_IP> "bash /tmp/server-setup.sh"
ssh root@<VPS_IP> "cat >> /home/deploy/.ssh/authorized_keys" < .secrets/deploy_key.pub
```

Verify:

```bash
ssh -i .secrets/deploy_key deploy@<VPS_IP> "nginx -v && ls -ld /srv/www"
```

---

## 5. DNS

Point each domain at the server, proxied through Cloudflare:

```bash
export CLOUDFLARE_API_TOKEN=…        # Zone:Read + DNS:Edit
export DEPLOY_HOSTS=<VPS_IP>

./scripts/dns status                 # what exists today
./scripts/dns apply                  # dry run — read it
./scripts/dns apply --confirm
```

In the Cloudflare dashboard set **SSL/TLS → Full (strict)** for each zone.

---

## 6. Nginx and certificates

```bash
./scripts/nginx-config               # generates infra/nginx/sites/*.conf
```

Copy the shared snippets and the vhosts (the script prints the exact commands),
then issue certificates **before** enabling the HTTPS vhosts:

```bash
ssh root@<VPS_IP> "certbot certonly --webroot -w /var/www/certbot -d DOMAIN -d www.DOMAIN"
ssh root@<VPS_IP> "nginx -t && systemctl reload nginx"
```

Alternative when Cloudflare is in front: a Cloudflare **Origin Certificate**
(15 years, no HTTP validation) installed at the same paths.

---

## 7. First deploy

```bash
./scripts/build-all --optimize
./scripts/deploy-all --no-smoke      # --no-smoke only this first time
```

Then check the origin directly, bypassing Cloudflare:

```bash
./scripts/smoke us --origin=<VPS_IP>
```

Once that passes, every later deploy runs the smoke test automatically and rolls
back a server that fails it.

---

## 8. Hand CI the keys

Repository → Settings → Secrets and variables → Actions:

| Secret | Value |
| ------ | ----- |
| `DEPLOY_HOSTS` | `<VPS_IP>` (later: `primary,backup`) |
| `DEPLOY_USER` | `deploy` |
| `DEPLOY_PORT` | `22` |
| `DEPLOY_ROOT` | `/srv/www` |
| `DEPLOY_SSH_KEY` | contents of `.secrets/deploy_key` (the private one) |

Verify by pushing a trivial content change and watching **Build and deploy**
go green.

---

## 9. Connect the CMS

1. <https://app.pagescms.org> → sign in with GitHub.
2. Install the Pages CMS GitHub App on **this repository only**.
3. Open the repository — the sidebar is built from `.pages.yml`.
4. Invite the SEO specialist and the rewriter as **collaborators by email**
   (no GitHub account needed, no repository access).
5. Send them [cms.md](cms.md).

---

## 10. Post-launch

- Submit each `https://<domain>/sitemap.xml` in Google Search Console.
- Check `https://<domain>/robots.txt` and a random page's `hreflang` block.
- `./scripts/rollback <geo> --list` should show one release per site.
- Optional: second server for failover — see
  [deployment.md](deployment.md#second-server-backup--failover).
- Optional: affiliate redirect service — see
  [deployment.md](deployment.md#affiliate-redirect-service-optional).

---

## Rollback drill (do it once, on purpose)

Before you rely on it in an emergency:

```bash
./scripts/deploy us                  # release #2
./scripts/rollback us --list         # two releases visible
./scripts/rollback us                # back to #1
./scripts/smoke us                   # still healthy
./scripts/rollback us                # forward again
```
