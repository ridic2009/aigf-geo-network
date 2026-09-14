# Local development

## Setup

```bash
git clone <repo> aigf-network
cd aigf-network
composer install
```

Requirements: PHP 8.2+ with `mbstring`, `intl`, `gd`, `dom`, `simplexml`,
`fileinfo`, and Composer. No Node, no database.

## Everyday commands

```bash
./scripts/validate             # data + CMS schema + all content
./scripts/validate de          # one GEO
./scripts/build de             # build -> dist/aigirlfriendranking-germany.site/
./scripts/build de --drafts    # include Draft/Review pages
./scripts/build-all            # the whole network
./scripts/dev de               # live-reload preview on http://localhost:8000
./scripts/dev de --port=8080
./scripts/prepare              # regenerate the hreflang map (build does this too)
./scripts/cms-config           # regenerate .pages.yml
./scripts/nginx-config         # regenerate infra/nginx/
```

On Windows the same commands exist as `scripts/*.cmd`, and
`php scripts/build.php de` always works.

`./scripts/dev` renders drafts and pages in review so writers and reviewers can
see work in progress. Production builds never do.

## How a build is assembled

```
config/common.yml                 engine defaults
config/geos/<geo>.yml             GEO identity, routes, labels
.cecil/generated/network.yml      generated: GEO registry + hreflang map
```

which becomes:

```bash
vendor/bin/cecil build \
  --config=config/common.yml,config/geos/de.yml,.cecil/generated/network.yml \
  --output=dist/aigirlfriendranking-germany.site
```

`./scripts/build` wraps that with validation before and after. Calling Cecil
directly skips the checks — useful when debugging a template, not for anything else.

## Templates

Twig runs with `strict_variables: true`, so reading a front matter field that a
page does not define is an error. Always guard optional fields:

```twig
{{ page.verdict|default(null) }}
{% if page.faq|default([])|length > 0 %}
```

Escaping is extension-based: `.html.twig` is escaped, `.txt.twig` and `.xml.twig`
are not. Rendered Markdown therefore needs `|raw`:

```twig
{{ page.content|raw }}
{{ item.answer|markdown_to_html|raw }}
```

Variables available inside layout blocks (set in `_default/base.html.twig`):

| Variable | Contents |
| -------- | -------- |
| `geo` | GEO identity: `code`, `hreflang`, `name`, `currency` |
| `ui`, `L` | localized labels (`L` is `ui.labels`) |
| `products` | every product, already merged with its GEO overrides, keyed by id |
| `crumbs` | breadcrumb trail (used by both the visual component and JSON-LD) |

## Adding a component

1. Create `engine/layouts/components/<name>.twig`. Document its parameters in a
   comment at the top — that comment is the component's contract.
2. Use it: `{% include 'components/<name>.twig' with { 'foo': bar } %}`.
3. Style it in `engine/assets/css/main.css` with a `.<name>__element` class.
4. `./scripts/build-all` — the component now exists on every site.

Never copy markup between layouts. If two layouts need the same block, it is a
component.

## Adding a page type

Say you want `comparison-hub`.

1. **Register it** in `config/common.yml`:

   ```yaml
   page_types:
     comparison_hub:
       section: hubs
   routes:
     hubs: hubs          # and a localized prefix in each config/geos/<geo>.yml
   ```

2. **Create the layout** `engine/layouts/hubs/page.html.twig`. Extend
   `_default/article.html.twig` and fill the `lead` / `after_body` blocks —
   headings, meta, disclosure, FAQ and related content come for free.

3. **Create the directory** `content/<geo>/hubs/` with a `.gitkeep` in every GEO
   (Pages CMS needs the directory to exist in the repository).

4. **Add validation** if the type has required fields: one rule in
   `scripts/php/ContentValidator.php`.

5. **Add it to the CMS**: one `collection(...)` call in `geoGroup()` inside
   `scripts/cms-config.php`, then `./scripts/cms-config`.

6. `./scripts/validate && ./scripts/build-all`.

## Adding a GEO

See [adding-geo.md](adding-geo.md).

## Debugging

- `php vendor/bin/cecil show:content --config=config/common.yml,config/geos/de.yml`
  prints the resolved content tree.
- `php vendor/bin/cecil show:config --config=config/common.yml,config/geos/de.yml`
  prints the merged configuration.
- Build errors always name a file. Content errors point at `content/<geo>/…`,
  output errors at a path inside `dist/`.
- To see what a validator sees without building: `./scripts/validate de`.
