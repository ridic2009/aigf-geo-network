# AIGF GEO Network

A static, multi-country network of SEO/affiliate review sites built from **one
engine, one deployment pipeline and N GEO configurations**, edited through
**[Pages CMS](https://pagescms.org)** in the browser.

There is no per-country copy of the codebase. A GEO is a config file, a content
directory and a domain. Changing a component changes every site on the next build.

```
one engine → many GEOs → static HTML
```

| Layer | Technology |
| ----- | ---------- |
| Editor | [Pages CMS](https://pagescms.org) on top of GitHub, schema in `.pages.yml` |
| Agent interface | `scripts/mcp.php` — MCP server over the same files |
| Static site generator | [Cecil](https://cecil.app) 9 (PHP) |
| Templates | Twig |
| Content | Markdown + YAML front matter |
| Source of truth | the git repository |
| Publishing | `infra/auto-deploy.sh` on the VPS (pull → validate → build → release switch) |
| Hosting | Ubuntu VPS + Nginx (static files only) |
| CDN/DNS | Cloudflare |
| Frontend | Vanilla JS + plain CSS |

**One write path.** Editors work in Pages CMS, agents work through the MCP
server, and both do the same thing: commit Markdown and YAML to `main`. There is
no second source of truth and no workspace to reconcile — git is the history and
the queue.

**One publisher.** A timer on the VPS checks `main` every two minutes and runs
the pipeline: validation, Cecil build per GEO, output verification, atomic
release switch, smoke test, rollback on failure. GitHub Actions validates and
builds every push but does not deploy unless the repository variable
`PUBLISHER=actions` says so — two publishers would take turns overwriting each
other.

Production sites are 100% static: no PHP, no Node, no database on the server.

> The editorial application that used to live here, **MiniCMS Studio**, was
> moved to its own repository to be rewritten. It owned a second copy of the
> content and a second way to publish, which is exactly what this setup no
> longer has.

---

## Quick start

Requirements: **PHP 8.2+** with `mbstring`, `intl`, `gd`, `dom`, `simplexml`,
`fileinfo`, `zip`, plus **Composer**. Nothing else — no Node, no database.

```sh
composer install
./scripts/validate          # every pre-build check
./scripts/dev fr            # local preview with live reload, drafts included
```

The day-to-day editing path is Pages CMS in the browser — the editors' manual is
**[docs/copywriter-guide.md](docs/copywriter-guide.md)**, and it is also the
first item in the CMS sidebar.

---

## How it fits together

```
SEO specialist / copywriter        AI agent (MCP)
          │  (browser)                  │  scripts/mcp.php
          ▼                             ▼
      Pages CMS ─────────────────────► git: main
          │  commits Markdown + YAML        content/ config/ data/ static/
          │
          ▼
   VPS timer, every 2 minutes  (infra/auto-deploy.sh)
          ├── validation (data, CMS schema, content, SEO, links)
          ├── Cecil build, once per GEO
          ├── output verification
          └── atomic release switch + smoke test (rollback on failure)
                          │
                          ▼
                     Ubuntu VPS
                          │   /srv/www/<domain>/releases/<ts>/
                        Nginx     current -> releases/<ts>
                          │
                ┌─────────┼─────────┐
                ▼         ▼         ▼
               FR        JP        NL
                          │
                          ▼
                 Cloudflare → visitors / Googlebot
```

Full diagrams and the source-of-truth table: [docs/architecture.md](docs/architecture.md).

---

## Where the data lives, and how it survives

The repository is the source of truth, and git is the history: every edit —
from Pages CMS, from an agent, from a shell — is a commit on `main`, with an
author, a message and the previous bytes one command away.

| What | Where | Protected by |
| ---- | ----- | ------------ |
| Pages | `content/<geo>/*.md` | git + backup |
| Site settings | `config/geos/<geo>.yml` | git + backup |
| Products and affiliate links | `data/products/`, `data/affiliates/` | git + backup |
| Images | `static/images/` | git + backup |
| Built sites | `dist/`, server releases | rebuildable |

```sh
git log --oneline -- content/fr        # who changed what, and when
git revert <commit>                    # undo a bad edit, keeping the trail
```

**Off-site backup.** Git is a second copy of everything text, but not of a
repository that becomes unreachable. One encrypted archive of `content/`,
`config/`, `data/` and `static/`, shipped to a second server and verified by
SHA-256 before the remote rotation runs.

```sh
php scripts/backup.php key                    # show the key fingerprint
php scripts/backup.php create                 # local archive
php scripts/backup.php push --host=203.0.113.20
php scripts/backup.php verify <file>          # decrypt and check every checksum
php scripts/backup.php extract <file>         # unpack into .backups/recovery/<id>/
```

The encryption key is generated on first use in `.secrets/backup.key` and is
never part of an archive. **Keep a copy of it somewhere other than these two
servers.** Without it the backups are unreadable — which is the point, and the
reason to restore-test on purpose at least once.

Install `infra/minicms-backup.service` and `infra/minicms-backup.timer` for a
nightly run.

---

## Repository layout

```
engine/                     THE engine — shared by every GEO
  layouts/                  base, article, list, 404, sitemap, components, partials
  assets/css|js/            one stylesheet, one small script
  php/
    Generator/              Cecil generators: EditorialStatus, Router
    Routing/                RouteResolver — the single URL source of truth

config/
  common.yml                engine config shared by all GEOs
  geos/<geo>.yml            GEO identity: domain, language, currency, routes, labels
  models/<model>.yml        page types and their fields — drives the CMS schema
  themes/<theme>.yml        colours, widths, radii
  network.yml               presets used by scripts/new-geo

content/<geo>/              one directory per GEO, identical structure everywhere

data/                       business data, shared by the whole network
  products/<id>.yml         product defaults + per-market overrides
  affiliates/<id>.yml       affiliate URLs per market
  authors/<id>.yml          bylines

static/images/              images copied as-is
scripts/                    build, validate, deploy, rollback, backup, mcp, cms-config…
infra/                      nginx vhosts, systemd units, server provisioning
.pages.yml                  Pages CMS schema — GENERATED by ./scripts/cms-config
.github/workflows/          validate.yml, deploy.yml (deploy is opt-in, see PUBLISHER)
var/                        publisher log and state on the VPS (git-ignored)
.secrets/                   deploy and backup keys (git-ignored)
dist/<domain>/              build output (git-ignored)
```

`.pages.yml` is generated from `config/geos/`, `config/models/` and
`config/cms-labels.ru.yml`. Regenerate it after adding a GEO, a page type or a
product attribute — `./scripts/new-geo` already does:

```sh
./scripts/cms-config && git commit .pages.yml
```

---

## Building

```sh
./scripts/build de                 # one GEO
./scripts/build de --drafts        # include drafts (never for production)
./scripts/build-all                # every enabled GEO
./scripts/build-all --optimize     # + HTML/CSS/JS/image minification
./scripts/dev de                   # local preview with live reload, drafts included
```

Each build runs, in order: business data validation → content validation →
Cecil build → output verification. A failure at any step exits non-zero and the
deployment never starts.

---

## Publishing

The normal path is a commit on `main`. A timer on the VPS notices it within two
minutes and runs validation, the build, the release switch and a smoke test;
nobody approves anybody, and a release that fails any step leaves the previous
one serving.

```sh
sudo -u deploy /srv/aigf-network/infra/auto-deploy.sh           # run it now
sudo -u deploy /srv/aigf-network/infra/auto-deploy.sh --force   # rebuild anyway
journalctl -u aigf-deploy.service                               # what it did
tail -f /srv/aigf-network/var/auto-deploy.log
```

Install it with `infra/install-auto-deploy.sh` — see
[docs/deployment.md](docs/deployment.md). Deploying to a *separate* server
instead of the build host needs SSH access and these variables:

```sh
DEPLOY_HOSTS=203.0.113.10,198.51.100.7   # primary + backup
DEPLOY_USER=deploy
DEPLOY_PORT=22
DEPLOY_ROOT=/srv/www
KEEP_RELEASES=5
```

The same thing can be driven by hand:

```sh
./scripts/deploy de            # one GEO, every server
./scripts/deploy de --build    # build first, then deploy
./scripts/deploy-all           # every enabled GEO, every server
./scripts/deploy-all --host=198.51.100.7   # seed one server only
```

After each release switch the server is smoke-tested directly (homepage,
robots.txt, sitemap, three real pages, a 404). **A server that fails is rolled
back automatically** and the run exits non-zero.

Each deploy uploads into a fresh timestamped release and only then switches the
`current` symlink, atomically:

```
/srv/www/aigirlfriendranking-germany.site/
├── releases/
│   ├── 20260914-210100/
│   └── 20260914-223500/
├── current -> releases/20260914-223500/
└── releases.log
```

Nginx serves `current`. Visitors never see a partially uploaded site.

Server preparation and certificates: [docs/deployment.md](docs/deployment.md).

---

## Rolling back

No rebuild, no upload — the previous release is still on disk:

```sh
./scripts/rollback de --list          # what is on each server
./scripts/rollback de                 # back to the previous release
./scripts/rollback de 20260914-210100 # back to a specific release
```

To roll back the *source* rather than the release, revert the commit: the timer
publishes the result the same way it published the mistake.

---

## Operations

```sh
./scripts/smoke us                    # check the live site like a visitor
./scripts/smoke us --origin=198.51.100.7   # check one server, bypassing DNS

./scripts/dns status                  # where every domain points right now
./scripts/dns apply --confirm         # point the network at the primary server
./scripts/dns failover --ip=198.51.100.7 --confirm   # switch to the backup

./scripts/redirect-config --enable=go.example.com   # affiliate redirect service
./scripts/nginx-config                # regenerate vhosts
```

`dns` needs `CLOUDFLARE_API_TOKEN` (Zone:Read + DNS:Edit) and changes nothing
without `--confirm`.

---

## Adding a GEO

From a shell:

```sh
./scripts/new-geo es --from=us        # config + content skeleton
```

Then:

1. Add the domain to Cloudflare DNS (A record to the VPS, proxied).
2. Translate `config/geos/es.yml` — `ui.*` labels and `routes.*` URL prefixes,
   or edit them in Pages CMS under Site settings.
3. Fill the catalogue for the new market: prices and affiliate links are per
   market, and the Товары screen marks the gaps.
4. Translate the content in `content/es/` and publish it.
5. `./scripts/nginx-config es` and install the vhost (see [docs/deployment.md](docs/deployment.md)).

No cloning, no new pipeline, no template changes.
Details: [docs/adding-geo.md](docs/adding-geo.md).

---

## Adding a page

In Pages CMS: pick the country, pick the page type, **Add an entry**. From a shell it is one Markdown file:

```yaml
---
title: Kupid AI Erfahrungen 2026
slug: kupid-ai-erfahrungen        # localized URL segment
type: review
status: draft                     # draft | published
translation_key: kupid            # same key in every country -> hreflang
product: kupid                    # must exist in data/products/
seo:
  title: Kupid AI Erfahrungen 2026 — Test und Fazit
  description: …
indexing: { index: true, follow: true }
---
```

The engine derives the rest: URL `/erfahrungen/kupid-ai-erfahrungen/`, canonical,
breadcrumbs, hreflang, JSON-LD, sitemap entry, internal links.

Front matter reference: [docs/content-model.md](docs/content-model.md).

---

## Validation

`./scripts/validate` runs everything that can be checked before a build; the
build additionally verifies the generated HTML. Errors fail the build, warnings
do not. The MCP server runs the same checks before it writes a page.

Checked, among others: required front matter, valid status and page type, slug
format, duplicate routes, required SEO fields, product/author/affiliate
references, FAQ structure, exactly one `<h1>`, canonical equal to the served URL,
`html lang`, valid JSON-LD, no broken internal links, no `.html` URLs, sitemap
completeness and no unpublished page in the output.

The Pages CMS schema is checked too: `.pages.yml` parses, every collection and
file points at a path that exists in the repository, field and component names
are well formed, and no field references a component that is not defined. Skip
that stage with `./scripts/validate --no-cms`.

Full list: [docs/validation.md](docs/validation.md).

---

## Tests

```sh
php tests/run.php        # integration: roles, workflow, build, history, backup
python3 -m unittest discover -s tests -p 'test_*.py'   # the server-side redirect helper
node tests/browser.cjs   # the application in a real browser
node tests/audit.cjs http://127.0.0.1:8788 <login> <password>   # design audit
```

---

## Diagnosing a failed publication

1. **Read the first error.** Every message carries a location, either
   `<geo>/<file>.md` or a path inside `dist/`.
2. **Reproduce one GEO:** `./scripts/build de` is faster than the whole network.
3. **Content errors** (`de/reviews/x.md: …`) are front matter problems — a missing
   `seo.description`, an unknown `product`, a broken internal link.
4. **Output errors** (`reviews/x/index.html: …`) mean the page rendered but is not
   correct — two `<h1>`, a canonical that does not match the URL, a dead link.
5. **Twig errors** name the template and line. Remember `strict_variables` is on:
   an optional front matter field must be read as `page.thing|default(null)`.
6. **Nothing changed on production?** A failed job never switches `current`.
   The previous release is still being served.
7. **Publisher log**: `var/auto-deploy.log` on the VPS.

---

## Further documentation

| Document | Contents |
| -------- | -------- |
| [docs/architecture.md](docs/architecture.md) | System diagram, source-of-truth table, design decisions |
| [docs/content-model.md](docs/content-model.md) | Front matter reference for every page type |
| [docs/local-development.md](docs/local-development.md) | Local setup, adding a page type, adding a component |
| [docs/deployment.md](docs/deployment.md) | VPS provisioning, Nginx, TLS, Cloudflare, releases, rollback |
| [docs/adding-geo.md](docs/adding-geo.md) | Adding a country end to end |
| [docs/validation.md](docs/validation.md) | Every check, and what to do when it fires |
| [docs/copywriter-guide.md](docs/copywriter-guide.md) | The editors' manual, in Russian — also served read-only inside Pages CMS |
| [docs/mcp.md](docs/mcp.md) | The MCP server: tools, the account writes belong to, the deploy switch |
| [docs/pages-cms-setup.md](docs/pages-cms-setup.md) | Pages CMS schema, access, in-app guide |
