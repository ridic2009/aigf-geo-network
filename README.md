# AIGF GEO Network

A static, multi-country network of SEO/affiliate review sites built from **one
engine, one deployment pipeline and N GEO configurations**, edited through
**MiniCMS Studio** or **Pages CMS**.

There is no per-country copy of the codebase. A GEO is a config file, a content
directory and a domain. Changing a component changes every site on the next build.

```
one engine → many GEOs → static HTML
```

| Layer | Technology |
| ----- | ---------- |
| Editorial application | MiniCMS Studio (PHP, no framework) |
| Alternative editor | [Pages CMS](https://pagescms.org) on top of GitHub, schema in `.pages.yml` |
| Static site generator | [Cecil](https://cecil.app) 9 (PHP) |
| Templates | Twig |
| Content | Markdown + YAML front matter |
| Source of truth | the Studio workspace, versioned by Studio itself |
| Publishing | Studio worker (build → verify → deploy → adopt sources) |
| Hosting | Ubuntu VPS + Nginx (static files only) |
| CDN/DNS | Cloudflare |
| Frontend | Vanilla JS + plain CSS |

**Two editors, one publisher.** Pages CMS edits `content/`, `config/` and `data/`
in the GitHub repository; Studio edits the same files in its own workspace. Only
one of them may deploy, or the last one to run silently reverts the other. The
repository variable `PUBLISHER` decides:

| `PUBLISHER` | Who deploys | What GitHub Actions does |
| ----------- | ----------- | ------------------------ |
| `studio` *(current)* | Studio worker | validates and builds only — never deploys |
| unset / `git` | GitHub Actions | validates, builds and deploys on push to `main` |

With `PUBLISHER=studio`, work done in Pages CMS reaches production only after it
is pulled into the Studio workspace and published from there. Editing the same
page in both at once is what the base-hash check at publication is there to catch.

Production sites are 100% static: no PHP, no Node, no database on the server.
Studio runs on the editorial host only, never on a public site.

---

## Quick start

Requirements: **PHP 8.2+** with `mbstring`, `intl`, `gd`, `dom`, `simplexml`,
`fileinfo`, `zip`, plus **Composer**. Nothing else — no Node, no database.

```sh
composer install
php scripts/studio.php serve
```

Open **http://127.0.0.1:8787** and create the first administrator.
Everything below is also available from a shell, but the day-to-day path is Studio.

Full guide, in Russian: **[docs/studio.md](docs/studio.md)**.

---

## How it fits together

```
SEO specialist / copywriter
          │  (browser only — no Git, no YAML, no SSH)
          ▼
    MiniCMS Studio ── roles, draft → review → approval
          │
          ├── version history      every write to content/config/data
          ├── catalogue            products, prices, affiliate links
          ├── SEO                  cross-site audit, hreflang matrix, redirects
          └── publication queue
                  │
                  ▼
           Studio worker
                  ├── validation (data, content, SEO, links)
                  ├── Cecil build, once per GEO
                  ├── output verification
                  ├── deploy + smoke test (rollback on failure)
                  └── adopt sources into the workspace, versioned
                          │
                          ▼
                     Ubuntu VPS
                          │   /srv/www/<domain>/releases/<ts>/
                        Nginx     current -> releases/<ts>
                          │
                ┌─────────┼─────────┐
                ▼         ▼         ▼
               US        DE        FR
                          │
                          ▼
                 Cloudflare → visitors / Googlebot
```

Full diagrams and the source-of-truth table: [docs/architecture.md](docs/architecture.md).

---

## Where the data lives, and how it survives

The workspace is the source of truth. Studio keeps its own history and its own
off-site copy, so losing the editorial host is recoverable.

| What | Where | Protected by |
| ---- | ----- | ------------ |
| Pages | `content/<geo>/*.md` | version history + backup |
| Site settings | `config/geos/<geo>.yml` | version history + backup |
| Products and affiliate links | `data/products/`, `data/affiliates/` | version history + backup |
| Accounts, drafts, queue | `.studio/state.json` | backup |
| Version history | `.studio/history/` | backup |
| Images | `static/images/` | backup |
| Built sites | `dist/`, server releases | rebuildable |

**Version history.** Every write Studio makes to `content/`, `config/` or `data/`
is recorded: who, when, why, and the previous bytes. The History screen shows the
log, a line diff of any version and a one-click restore. Objects are
content-addressed, so re-recording an unchanged file costs nothing.

**Off-site backup.** One encrypted archive of the sources, the history and the
Studio state, shipped to a second server and verified by SHA-256 before the
remote rotation runs.

```sh
php scripts/backup.php key                    # show the key fingerprint
php scripts/backup.php create                 # local archive
php scripts/backup.php push --host=203.0.113.20
php scripts/backup.php verify <file>          # decrypt and check every checksum
php scripts/backup.php extract <file>         # unpack into .studio/recovery/<id>/
```

The encryption key is generated on first use in `.secrets/backup.key` and is
never part of an archive. **Keep a copy of it somewhere other than these two
servers.** Without it the backups are unreadable — which is the point, and the
reason to restore-test on purpose at least once.

Install `infra/studio-backup.service` and `infra/studio-backup.timer` for a
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

studio/public/              the editorial application (index.php, app.js, app.css)

config/
  common.yml                engine config shared by all GEOs
  geos/<geo>.yml            GEO identity: domain, language, currency, routes, labels
  models/<model>.yml        page types and their fields — drives Studio forms
  themes/<theme>.yml        colours, widths, radii
  network.yml               presets used by scripts/new-geo

content/<geo>/              one directory per GEO, identical structure everywhere

data/                       business data, shared by the whole network
  products/<id>.yml         product defaults + per-market overrides
  affiliates/<id>.yml       affiliate URLs per market
  authors/<id>.yml          bylines

static/images/              images copied as-is
scripts/                    build, validate, deploy, rollback, backup, studio, cms-config…
infra/                      nginx vhosts, systemd units, server provisioning
.pages.yml                  Pages CMS schema — GENERATED by ./scripts/cms-config
.github/workflows/          validate.yml, deploy.yml (deploy gated by PUBLISHER)
.studio/                    accounts, drafts, queue, history, backups (git-ignored)
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
./scripts/build de --drafts        # include Draft/Review pages (never for production)
./scripts/build-all                # every enabled GEO
./scripts/build-all --optimize     # + HTML/CSS/JS/image minification
./scripts/dev de                   # local preview with live reload, drafts included
```

Each build runs, in order: business data validation → content validation →
Cecil build → output verification. A failure at any step exits non-zero and the
deployment never starts.

---

## Publishing

The normal path is Studio: approve a revision, press Publish, and the worker does
the rest. For the worker to deploy it needs SSH access and these variables
(`infra/studio.env.example`):

```sh
DEPLOY_HOSTS=203.0.113.10,198.51.100.7   # primary + backup
DEPLOY_USER=deploy
DEPLOY_PORT=22
DEPLOY_ROOT=/srv/www
KEEP_RELEASES=5
```

```sh
php scripts/studio.php worker --deploy    # process one job
```

Install `infra/studio-worker.service` and `infra/studio-worker.timer` for
continuous operation.

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

To roll back the *source* rather than the release, use the History screen in
Studio and publish again.

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

From Studio: **Новый сайт**. From a shell:

```sh
./scripts/new-geo es --from=us        # config + content skeleton
```

Then:

1. Add the domain to Cloudflare DNS (A record to the VPS, proxied).
2. Translate `config/geos/es.yml` — `ui.*` labels and `routes.*` URL prefixes,
   or do it on the Тексты and Адреса tabs in Studio.
3. Fill the catalogue for the new market: prices and affiliate links are per
   market, and the Товары screen marks the gaps.
4. Translate the content in `content/es/` and publish it.
5. `./scripts/nginx-config es` and install the vhost (see [docs/deployment.md](docs/deployment.md)).

No cloning, no new pipeline, no template changes.
Details: [docs/adding-geo.md](docs/adding-geo.md).

---

## Adding a page

In Studio: **Материалы → Новый материал**. From a shell it is one Markdown file:

```yaml
---
title: Kupid AI Erfahrungen 2026
slug: kupid-ai-erfahrungen        # localized URL segment
type: review
status: draft                     # draft | review | published
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
do not. Studio runs the same checks before a page can be submitted for review.

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
   `<geo>/<file>.md` or a path inside `dist/`. In Studio it links to the field.
2. **Reproduce one GEO:** `./scripts/build de` is faster than the whole network.
3. **Content errors** (`de/reviews/x.md: …`) are front matter problems — a missing
   `seo.description`, an unknown `product`, a broken internal link.
4. **Output errors** (`reviews/x/index.html: …`) mean the page rendered but is not
   correct — two `<h1>`, a canonical that does not match the URL, a dead link.
5. **Twig errors** name the template and line. Remember `strict_variables` is on:
   an optional front matter field must be read as `page.thing|default(null)`.
6. **Nothing changed on production?** A failed job never switches `current`.
   The previous release is still being served.
7. **Job log**: Публикации → Журнал, or `.studio/jobs/<id>/worker.log`.

---

## Further documentation

| Document | Contents |
| -------- | -------- |
| [docs/studio.md](docs/studio.md) | The application: workflow, catalogue, history, server setup, recovery |
| [docs/architecture.md](docs/architecture.md) | System diagram, source-of-truth table, design decisions |
| [docs/content-model.md](docs/content-model.md) | Front matter reference for every page type |
| [docs/local-development.md](docs/local-development.md) | Local setup, adding a page type, adding a component |
| [docs/deployment.md](docs/deployment.md) | VPS provisioning, Nginx, TLS, Cloudflare, releases, rollback |
| [docs/adding-geo.md](docs/adding-geo.md) | Adding a country end to end |
| [docs/validation.md](docs/validation.md) | Every check, and what to do when it fires |
