# Working in the CMS

A guide for the SEO specialist and the rewriter. You never need Git, Markdown
files, YAML, SSH or the server — everything happens in the browser.

## What you see when you log in

```
Sites
  United States
  Deutschland
  France
Products
Affiliate links
Authors
```

Open a country and you get its page types:

```
Deutschland
  Homepage
  Pages            (About, Privacy, Affiliate disclosure)
  Reviews
  Rankings
  Comparisons
  Guides
```

## Publishing workflow

Every page has a **Status**:

| Status | What it means |
| ------ | ------------- |
| **Draft** | Work in progress. Not on the live site. |
| **Review** | Finished by the rewriter, waiting for SEO approval. Not on the live site. |
| **Published** | Live. |

Rewriter moves a page Draft → Review. SEO moves it Review → Published.
Pages that are not Published are invisible to visitors and to Google: they are
not built, not in the sitemap, not linked from anywhere.

When you save a Published page, the site rebuilds automatically and the change
is live in roughly two minutes. There is no separate "deploy" button.

## Adding a review

**Sites → Deutschland → Reviews → Add an entry.**

| Field | What to put in it |
| ----- | ----------------- |
| **Title** | The heading of the page, e.g. `Kupid AI Erfahrungen 2026`. |
| **URL** | The address in this country, e.g. `kupid-ai-erfahrungen`. Lowercase, hyphens, no spaces, no accents. |
| **Status** | Start with Draft. |
| **Page key** | The same key in every country, e.g. `kupid`. This is what connects the German page to the English one for Google. Use the product id if you are unsure. |
| **Product** | Pick from the list. The logo, rating, price and the affiliate button come from there — do not retype them in the text. |
| **First published / Last updated** | Dates shown on the page. "Last updated" is what Google sees as the freshness date. |
| **Author / Reviewed by** | Pick from the list. |
| **Introduction** | One or two sentences under the title. |
| **Main content** | The article. Use the editor as you would any other; start headings at level 2. Do not add a level-1 heading — the Title already is one. |
| **Our verdict** | Short conclusion, shown in a highlighted box. |
| **Pros / Cons** | One item per line. |
| **FAQ** | Question + answer pairs. These are shown on the page and also sent to Google as structured data. You never write code for this. |
| **Related pages** | Optional. |
| **SEO** | Title, meta description, primary keyword. Optional social title/description/image. |
| **Search engine indexing** | Leave both switches on. |

Press **Save**. Set Status to Published when it is approved.

Everything else — the URL of the page, the canonical, the breadcrumbs, the
hreflang links to the other countries, the schema.org markup, the sitemap entry —
is produced automatically. You cannot get them out of sync by hand.

## Adding other page types

- **Ranking** — fill the **Ranking** list: product, badge (e.g. "Best overall")
  and one sentence per position, in order. The table and the cards are generated.
- **Comparison** — pick two or more **Products**. The comparison table is generated.
- **Guide** — normal article; optionally list products to show as cards.
- **Pages** — About, Privacy, Affiliate disclosure and similar.

## Images

Use the image button in the editor, or the image fields. Uploads go to a fixed
folder automatically (`Article images` for content, `Product logos` for logos),
so nothing ends up in a random place. Always write a short **alt text** — a build
fails if an image has none.

Prefer WebP or optimized JPG/PNG. Large images make the page slow.

## Site settings (per country)

**Sites → country → Site settings.** This is where the text the system writes by
itself lives — you no longer need a developer for any of it:

- **Site name**, **Tagline**, **Default meta description**
- **Publisher** — name, legal name, contact email
- **Interface text** — three groups:
  - *Menu labels* — what the header and footer links say
  - *Section names* — the titles of the Reviews / Comparisons / Guides pages
  - *Interface labels* — every word the engine prints on its own: Pros, Cons,
    Rating, "Read review", "Visit site", the affiliate notice, "Updated", …

Some fields are shown but greyed out: **Domain**, **URL structure**, **Market**.
They are read-only on purpose — changing a URL prefix moves every page in that
section and needs redirects, and changing a domain needs DNS and a certificate.
Ask a developer for those.

A note on `Try %s`: the `%s` is replaced by the product name, so "Essayer %s"
becomes "Essayer Candy AI". Keep the `%s` in the text.

## Navigation & SEO (whole network)

**Network → Navigation & SEO.** One place for decisions that apply to every
country at once:

- **Menus** — the header and footer structure. An item has a *Page id*
  (e.g. `reviews`, `about`, `rankings/best-ai-girlfriend`), a *Label key* and an
  *Order*. The label key points at an entry of "Menu labels" in each country's
  Site settings — so add the key there for every country too. A country that
  does not have the page simply skips the item.
- **Structured data** — which schema.org blocks the pages emit.
- **Affiliate links** — the `rel` attribute used on monetised links. Keep
  `sponsored` in it.

## Products and affiliate links

**Products** holds one entry per service: name, id, logo, rating, features,
default price, and a **Country overrides** block where you can set a different
price, availability or tagline per country.

**Affiliate links** holds one entry per product: a fallback link plus one link
per country. Buttons on every site pick the right link automatically from the
product and the country. Never paste an affiliate URL into article text — it
would not be tracked, would not carry the right attributes and could not be
updated centrally.

## Common mistakes and what happens

| What you did | What happens |
| ------------ | ------------ |
| Left the SEO title or meta description empty on a Published page | The build fails and the page does not go live. Fill them in and save again. |
| Used capitals, spaces or accents in the **URL** field | The build fails with a message about the slug. |
| Linked to a page that does not exist in that country | The build fails with "Broken internal link". |
| Added a `#` heading in the main content | The build fails: there may be only one level-1 heading, and the Title is it. |
| Picked a product that has no affiliate link for that country | The build passes with a warning; the button links to the official site instead. |
| Gave two pages the same URL in one country | The build fails with "Duplicate route". |

A failed build never changes the live site — the previous version keeps serving
until the problem is fixed.

## What you should never have to do

Open a repository, edit YAML, touch a branch, run a command, log into a server,
or ask a developer where to click for any of the above. If one of those is
needed for a routine task, that is a bug in the setup — report it.
