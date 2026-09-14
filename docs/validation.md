# Validation

Everything below runs on every build and in CI. **Errors fail the build**, which
means the deploy job never starts and production keeps serving the previous
release. **Warnings** are printed and do not block.

```bash
./scripts/validate            # pre-build checks only
./scripts/build de            # pre-build + build + post-build checks
```

## 1. Business data — `scripts/php/DataValidator.php`

| Check | Level |
| ----- | ----- |
| `data/products/<id>.yml`: `slug` equals the file name | error |
| product has a `name` | error |
| `rating` is a number between 0 and 5 | error |
| `logo` exists under `static/` | error |
| product `geo:` keys are known GEO codes | error |
| `data/affiliates/<id>.yml`: `product` equals the file name | error |
| an affiliate file matches an existing product | error |
| at least one affiliate URL (`default` or a GEO) | error |
| affiliate URLs are absolute `https://` | error |
| affiliate `geo:` keys are known GEO codes | error |
| `data/authors/<id>.yml`: `id` equals the file name, `name` present | error |
| a product has no affiliate file | warning |
| an enabled GEO has neither its own link nor a fallback | warning |

## 2. Pages CMS schema — `scripts/php/CmsConfigValidator.php`

Pages CMS rejects its entire config on one unknown key, which would lock the
editors out of the CMS. This mirrors its schema:

| Check | Level |
| ----- | ----- |
| unknown key on a content entry, group, field or media source | error |
| entry `type` is `collection`, `file` or `group` | error |
| `name` matches `^[a-zA-Z0-9-_]+$` | error |
| `path` has no leading/trailing slash | error |
| a collection/file path actually exists in the repository | error |
| `format` is a supported Pages CMS format | error |
| `filename` is a string or `{template, field}` | error |
| a field has exactly one of `type` or `component` | error |
| field `type` is a supported Pages CMS type | error |
| `object` has `fields`, `block` has `blocks` | error |
| `select` has `options.values` | error |
| `reference` points at a collection that exists | error |
| `component:` points at a defined component | error |
| media `input` exists, `output` well formed, `name` present when several sources | error |
| a `config/` file is exposed without `settings.content.merge: true` | error |
| no media configuration at all | warning |

## 3. Content — `scripts/php/ContentValidator.php`

Per GEO, before Cecil runs.

### Structure

| Check | Level |
| ----- | ----- |
| front matter parses as YAML | error |
| `title` present | error |
| `type` present and known | error |
| `type` matches the directory (`review` → `reviews/`, …) | error |
| `status` present and one of `draft`/`review`/`published` | error |
| `slug` present (except homepage and section index) | error |
| `slug` matches `^[a-z0-9]+(-[a-z0-9]+)*$` | error |
| no two published pages resolve to the same route | error |
| the GEO has a homepage | error |
| `translation_key` missing | warning |

### SEO

| Check | Level |
| ----- | ----- |
| published page has `seo.title` | error |
| published page has `seo.description` | error |
| `indexing.index` / `indexing.follow` are booleans | error |
| `canonical` is an object with a `url` | error |
| body contains a level-1 heading (`# …`) — the title is the only `<h1>` | error |
| `seo.title` longer than 70 characters | warning |
| `seo.description` outside 50–175 characters | warning |
| no `seo.primary_keyword` | warning |

### References

| Check | Level |
| ----- | ----- |
| `product` / `products` / `top_products` / `ranking[].product` exist in `data/products/` | error |
| a review references a product | error |
| a comparison references at least two products | error |
| a ranking has at least one entry, each with a `product` | error |
| `author` / `reviewer` exist in `data/authors/` | error |
| `related` and `hero_cta` point at existing page ids | error |
| `faq[]` entries have a non-empty question and answer | error |
| a Markdown link to `/something/` resolves to a route this GEO produces | error |
| a menu item has no `ui.nav` label in this GEO | error |
| a published product has no affiliate link for this GEO | warning |
| a menu item points at a page this GEO does not have | warning |

## 4. Generated output — `scripts/php/OutputValidator.php`

After Cecil runs, against the files in `dist/<domain>/`.

| Check | Level |
| ----- | ----- |
| the build produced HTML at all | error |
| no unrendered Twig expression (`{{`, `{%`) left in the output | error |
| exactly one `<h1>` per page | error |
| non-empty `<title>` | error |
| non-empty meta description | error |
| `<html lang>` present and equal to the GEO language | error |
| canonical present | error |
| **canonical equals the URL the file is actually served at** | error |
| JSON-LD parses as valid JSON | error |
| every internal link resolves to a generated file | error |
| no link to a `.html` URL (except `/404.html`) | error |
| every referenced CSS/JS/image exists in the output | error |
| every `<img>` has an `alt` attribute | error |
| `sitemap.xml` exists, is valid XML and is not empty | error |
| every sitemap URL belongs to this GEO and resolves to a file | error |
| no duplicate URLs in the sitemap | error |
| every published, indexable page is in the sitemap | error |
| no non-indexable page in the sitemap | error |
| **no `draft`/`review` page rendered into the output** | error |
| `robots.txt` exists and points at this GEO's sitemap | error |
| canonical points outside this site (explicit override) | warning |
| an `<img>` has no `width`/`height` | warning |

## Why the canonical check matters

The failure mode this architecture is designed against is a URL written down in
several places that drift apart:

```
menu:     /review.html
canonical: /review/
sitemap:  /reviews/review/
```

Here the URL is produced once by `RouteResolver`, and the output validator
independently recomputes the expected canonical **from the file's own location on
disk** and compares it with what the HTML says. If routing, canonical and sitemap
ever disagree, the build fails.

## Extending

Add content rules to `ContentValidator::validate()` and output rules to
`OutputValidator::checkHtml()`. Keep the convention: something that would put a
wrong page in front of Google is an error; something a human should look at is a
warning.
