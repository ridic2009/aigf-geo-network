# Content model

Every page is a Markdown file with YAML front matter. The front matter is the
same shape for every page type; a type only adds the fields it needs. This is
deliberate: one shape means one validator, one CMS schema generator and no
"twenty incompatible page formats" six months from now.

```
content/<geo>/
├── index.md                       type: homepage
├── about.md                       type: static
├── privacy.md                     type: static
├── affiliate-disclosure.md        type: static
├── reviews/<key>.md               type: review
├── rankings/<key>.md              type: ranking
├── compare/<key>.md               type: comparison
└── guides/<key>.md                type: guide
```

The directory decides the page type and the layout. The file name is the page id
and **must be the same in every GEO** — that is what links translations together.
Only `slug` (the URL) and the text are localized.

## Common fields

| Field | Required | Notes |
| ----- | -------- | ----- |
| `title` | yes | Rendered as the single `<h1>`. Never write `#` in the body. |
| `slug` | yes (except the homepage) | Localized URL segment, `^[a-z0-9]+(-[a-z0-9]+)*$`. |
| `type` | yes | `homepage`, `static`, `review`, `ranking`, `comparison`, `guide`. Must match the directory. |
| `status` | yes | `draft` · `published`. Only `published` is rendered into production. |
| `translation_key` | recommended | Groups the same logical page across GEOs for hreflang. Defaults to the page id. |
| `date` | no | First publication. Defaults to the file date. |
| `updated` | no | Shown on the page, used as the sitemap `lastmod`. |
| `author`, `reviewer` | no | Ids from `data/authors/`. |
| `intro` | no | Markdown lead paragraph, shown under the title. |
| *body* | no | Everything after the front matter. Markdown, `##` and below. |
| `faq` | no | List of `{question, answer}`. Renders the accordion **and** `FAQPage` JSON-LD. |
| `related` | no | Page ids, e.g. `reviews/candy-ai`. |
| `seo` | yes for published | See below. |
| `indexing` | no | `{index: bool, follow: bool}`, both default to `true`. |
| `canonical` | no | Advanced: `{url: https://…}` overrides the generated canonical and removes the page from the sitemap. |

### `seo`

```yaml
seo:
  title: Candy AI Review 2026 — Features, Pricing and Verdict   # required
  description: Hands-on review after two weeks on a paid plan…  # required
  primary_keyword: candy ai review
  og_title: …          # optional, defaults to seo.title
  og_description: …    # optional, defaults to seo.description
  og_image: /images/content/…   # optional
```

Editors never enter a canonical URL, `og:locale`, `html lang`, hreflang, an
absolute domain or a schema id. All of those are generated from the GEO config
and the resolved route.

## Type-specific fields

### `review`

```yaml
product: candy-ai          # required, must exist in data/products/
verdict: |                 # optional, rendered in a highlighted box
  …
pros: [ …, … ]             # optional
cons: [ …, … ]             # optional
```

The product card (logo, rating, price, availability, CTA) comes from the product
database. Never copy product data into an article.

### `ranking`

```yaml
ranking:                   # required, at least one entry, in order
  - product: candy-ai
    badge: Best overall
    highlight: One sentence on why it ranks here.
  - product: nomi
    badge: Best conversations
```

Generates the ranking table and the detailed cards below it.

### `comparison`

```yaml
products: [candy-ai, nomi] # required, at least two
```

Generates the side-by-side table from the product database.

### `guide`

```yaml
products: [candy-ai, nomi] # optional, shown as compact cards
```

### `homepage`

```yaml
hero_cta: rankings/best-ai-girlfriend   # page id the hero button links to
top_products_title: Top rated this month
top_products: [candy-ai, nomi]
```

## Business data

### `data/products/<id>.yml`

The file name is the product id used by every article and affiliate link.

```yaml
name: Candy AI
slug: candy-ai            # must equal the file name
website: 'https://candy.ai/'
logo: /images/products/candy-ai.svg
rating: 4.6               # 0–5, drives stars and Review schema
free_tier: true
platforms: 'Web, iOS, Android'
features: [ …, … ]
price: { amount: 12.99, period: month }

geo:                      # per-country overrides, merged over the defaults
  de:
    availability: available        # available | limited | unavailable
    tagline: …                     # localized
    best_for: …                    # localized
    price: { amount: 12.99 }
    rating: 4.5                    # only if the methodology requires it
```

### `data/affiliates/<id>.yml`

```yaml
product: candy-ai         # must equal the file name
default: 'https://…'      # used when a GEO has no dedicated link
geo:
  us: { url: 'https://…' }
  de: { url: 'https://…' }
```

Templates never read this file. `engine/layouts/helpers/affiliate.twig` resolves
`product_id + geo → URL` and renders `rel="sponsored nofollow noopener"`.
Switching to a redirect service (`go.example.com/<product>/<geo>`) is one setting
in `config/common.yml` and touches no template.

### `data/authors/<id>.yml`

```yaml
id: m-keller              # must equal the file name
name: 'M. Keller'
role:
  en: 'Senior reviewer'
  de: 'Leitender Tester'
```

## Editorial workflow

```
Draft → Published
```

Two states, and whoever writes a page publishes it: there is no approval step
and the two working roles — rewriter and SEO — have identical rights. What
guards the network is the strict validation a release must pass and the version
history behind every write, not a person holding a gate.

`draft` pages are converted but never rendered: they do not reach `dist/`, the
sitemap, section listings or menus. `./scripts/dev <geo>` and
`./scripts/build <geo> --drafts` render them for preview only — never use
`--drafts` for a production build.

Who may edit is a Pages CMS question: collaborators are invited by email and can
change content and media, nothing else.
