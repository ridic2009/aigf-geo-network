# Architecture

## The shape of the system

```
1 engine
+ N GEO configurations
+ N localized content sets
+ 1 product database
+ 1 affiliate database
```

Nothing is duplicated per country. A GEO owns a domain, a language, a currency,
URL prefixes, interface labels and its own Markdown files — and nothing else.
Layouts, components, CSS, JS, SEO logic, schema generators, routing, validation
and the deployment pipeline are shared.

## End-to-end flow

```
┌──────────────────────────┐
│ SEO specialist / Rewriter│   browser only
└─────────────┬────────────┘
              │ structured fields
              ▼
┌──────────────────────────┐
│        Pages CMS         │   .pages.yml describes the forms
└─────────────┬────────────┘
              │ commit (Markdown + YAML)
              ▼
┌──────────────────────────┐
│         GitHub           │   source of truth, private repo
└─────────────┬────────────┘
              │ push to main
              ▼
┌──────────────────────────────────────────────────────────┐
│                    GitHub Actions                        │
│                                                          │
│  scripts/validate   data + CMS schema + content          │
│  scripts/prepare    hreflang map + GEO registry          │
│  cecil build        once per GEO, same engine            │
│  OutputValidator    canonical, h1, links, sitemap, JSON-LD│
│  scripts/deploy-all rsync into a new release + switch    │
└─────────────┬────────────────────────────────────────────┘
              │ ssh + rsync                (fails → nothing is deployed)
              ▼
┌──────────────────────────┐
│       Ubuntu VPS         │
│  /srv/www/<domain>/      │
│    releases/<timestamp>/ │
│    current -> releases/… │
└─────────────┬────────────┘
              │
              ▼
┌──────────────────────────┐
│          Nginx           │   static files only, one vhost per domain
└─────────────┬────────────┘
              │
              ▼
┌──────────────────────────┐
│        Cloudflare        │   DNS + CDN + TLS in front of the origin
└─────────────┬────────────┘
              ▼
      Visitors / Googlebot
```

## Source of truth

The rule that keeps a 25-site network maintainable: **every fact is written down
exactly once**. Everything else is derived at build time.

| Fact | Single source | Derived from it |
| ---- | ------------- | --------------- |
| **URL of a page** | `routes.<section>` in the GEO config + `slug` in front matter, resolved by `engine/php/Routing/RouteResolver.php` | `page.path`, canonical, sitemap `<loc>`, menu links, breadcrumbs, hreflang, internal link checks |
| **Domain** | `baseurl` in `config/geos/<geo>.yml` | canonical base, OG url, sitemap URLs, robots.txt, `dist/<domain>/`, Nginx `server_name`, deploy target |
| **Language / locale / currency** | `geo:` + `languages:` in the GEO config | `<html lang>`, `og:locale`, hreflang, price formatting, date formatting |
| **Publication state** | `status:` in front matter | Cecil `published`, section listings, sitemap, internal links, deployed output |
| **Product facts** | `data/products/<id>.yml` (+ `geo:` overrides) | product cards, ranking tables, comparison tables, prices, `Review` schema |
| **Affiliate destination** | `data/affiliates/<id>.yml` | every outbound CTA through `helpers/affiliate.twig`, and — when the redirect service is on — the generated Nginx map behind `go.<domain>/<product>/<geo>` |
| **Translation grouping** | `translation_key` in front matter (default: page id) | hreflang cluster, x-default |
| **Navigation structure** | `navigation:` in `config/common.yml` | header and footer menus in every GEO (labels from `ui.nav`) |
| **Breadcrumb trail** | `crumbs` computed once in `_default/base.html.twig` | visual breadcrumbs *and* `BreadcrumbList` JSON-LD |
| **FAQ** | `faq:` in front matter | visual accordion *and* `FAQPage` JSON-LD |
| **CMS forms** | `config/geos/*.yml` + page-type model, via `scripts/cms-config` | `.pages.yml` |

If you ever find yourself writing a URL, a domain or a price in a second place,
that is the bug.

## Why a custom Cecil generator for routing

Cecil's native `pages.paths` is applied during the *Create* step, before front
matter is parsed. A localized `slug:` is applied later and resets the path back
to `<folder>/<slug>`, silently discarding the configured prefix.

`engine/php/Generator/Router.php` runs at generator priority 45 — after front
matter conversion and after Cecil's `Section` generator — and sets the final path
for both content pages and section index pages. Because `url()` derives
everything from that path, canonical, sitemap, menus and breadcrumbs cannot drift
apart. The same `RouteResolver` class is used by the hreflang map builder and by
the validator, so the checks and the build agree by construction.

Consequences:

- Content directories are identical in every GEO (`reviews/`, `rankings/`,
  `compare/`, `guides/`), so **page ids are stable across countries** — which is
  what makes `translation_key` default to something sensible and makes one shared
  `navigation:` list work everywhere.
- Only the prefix (config) and the slug (front matter) are localized.
- A section routed to `''` (rankings) is published at the site root and its index
  page is suppressed, so `/best-ai-girlfriend/` does not collide with `/rankings/`.

## Why a custom generator for editorial status

Cecil understands `published: true/false` and `draft: true`, but the workflow
needs three states (`draft` → `review` → `published`).
`engine/php/Generator/EditorialStatus.php` runs at priority 15 — after front
matter is parsed, before Cecil builds sections and listings — and maps `status`
onto `published`. Unpublished pages are therefore absent from the rendered
output, from section listings, from menus, from the sitemap and from internal
link resolution. `./scripts/dev` and `--drafts` override this for previews only.

## Build layers

A GEO build merges three configuration layers, left to right:

```
config/common.yml              engine defaults, identical everywhere
config/geos/<geo>.yml          GEO identity and localization
.cecil/generated/network.yml   generated: GEO registry + hreflang map
```

The third layer is produced by `scripts/prepare` and exists because a single-GEO
build cannot know the URLs of the other 24 sites. It is git-ignored and
regenerated before every build.

## Structured data

`engine/layouts/partials/schema.html.twig` emits one JSON-LD `@graph` per page,
assembled from page and product metadata:

| Node | When |
| ---- | ---- |
| `Organization`, `WebSite` | every page |
| `WebPage` | every page |
| `BreadcrumbList` | whenever there is a trail (same array as the visual breadcrumbs) |
| `Article` | ranking, comparison and guide pages |
| `Review` | review pages **that have a real editorial rating** in the product database |
| `FAQPage` | pages with an `faq:` list (toggleable network-wide) |

No aggregate ratings, no review counts and no ratings are invented: if the
product database has no rating, no `Review` node is emitted. Every node type can
be switched off for the whole network in `config/common.yml` under `schema:`.

## Performance and security posture

- Output is static HTML plus one fingerprinted stylesheet and one small script.
  JavaScript only enhances the mobile menu and reports affiliate clicks; the FAQ
  is `<details>`, the menu is a list, the tables are tables. Disabling JS costs
  nothing that matters for users or crawlers.
- Cecil fingerprints CSS/JS, so Nginx serves them with `immutable` caching while
  HTML is always revalidated — a release switch is visible immediately.
- Images carry `width`/`height`, `loading="lazy"` and `decoding="async"`.
- The origin has no application surface: no PHP-FPM, no Node, no database, no
  admin path, no upload endpoint. The CMS lives outside the SEO origin entirely.
- Secrets exist only as GitHub Secrets; the deploy user may reload Nginx and
  nothing else.

## Deliberate non-goals

No microservices, no queues, no Kubernetes, no Redis, no API gateway, no runtime
rendering. The network is a few hundred HTML files per country; the operational
model should stay boring enough that one developer can run it.
