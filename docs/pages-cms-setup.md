# Pages CMS setup

Pages CMS is an open-source CMS that edits files in a GitHub repository. It runs
**outside** the SEO origin: the production servers stay pure static files with no
admin surface at all.

## How the schema is produced

`.pages.yml` is **generated** — never hand-edited:

```bash
./scripts/cms-config              # every GEO that has a config file
./scripts/cms-config us de        # only these
./scripts/cms-config --lang=ru    # Russian labels and hints
git add .pages.yml && git commit -m "cms: regenerate schema"
```

It is derived from `config/geos/*.yml` and the page-type model in
`config/common.yml`, so adding a GEO, a page type or a product attribute is a
regeneration rather than a 2,800-line manual edit.


## Interface language

Pages CMS has no interface localization: its own chrome — *Save*, *Add an
entry*, *Settings*, date pickers — is hardcoded English, and there is no i18n
package in the project at all.

Everything the schema defines is ours, though, and that is most of what an
editor actually reads: the sidebar, country and page-type names, every field
label, every hint, the status options, the media source names.
`--lang=ru` translates all of it through `config/cms-labels.ru.yml`.

```
Сайты                       Обзоры → Заголовок · Адрес страницы · Статус ·
  Deutschland                        Ключ страницы · Продукт · Вступление ·
    Настройки сайта                  Главное изображение · Основной текст ·
    Главная страница                 Итог редакции · Плюсы · Минусы ·
    Страницы                         Частые вопросы · SEO · Индексация
    Обзоры · Рейтинги · Сравнения · Гайды
Продукты · Партнёрские ссылки · Авторы · Сеть
```

A string with no entry in the dictionary stays English, and the script lists
what is missing — so adding a field later never silently leaves a gap. Country
and language names are deliberately left untranslated.

Another language is a new `config/cms-labels.<code>.yml`; nothing in the
generator changes. Switching back is `./scripts/cms-config` without `--lang`.

`./scripts/validate` checks the result against the Pages CMS schema rules
(unknown keys, unknown field types, `type`/`component` exclusivity, references to
missing collections, collection paths that do not exist in the repository). Pages
CMS rejects its whole config on a single unknown key, so this check is worth
having in CI.

## Configuration files edited through the CMS

Two kinds of configuration are exposed as forms:

| CMS entry | File | Editable | Read-only |
| --------- | ---- | -------- | --------- |
| Sites → *country* → **Site settings** | `config/geos/<geo>.yml` | title, tagline, description, publisher, all interface labels | domain, URL prefixes, market/hreflang, live flag |
| Network → **Navigation & SEO** | `config/common.yml` | menu structure, schema.org switches, affiliate `rel` | redirect service |

This is only safe because of one setting:

```yaml
settings:
  content:
    merge: true
```

With `merge`, Pages CMS reads the existing file and **deep-merges** the form into
it, so keys the form does not declare — the content directory, the language, the
currency format, every Cecil option — are preserved on save. Without it, saving
would replace the file with just the form fields and silently destroy the build
configuration.

`./scripts/validate` refuses a `.pages.yml` that exposes anything under
`config/` without `settings.content.merge: true`.

Two consequences worth knowing:

- YAML comments in those files are lost the first time the CMS saves them.
- Merge never deletes keys. Emptying a label in the form writes an empty value;
  it does not remove the key.

## Connecting the repository

1. Sign in at <https://app.pagescms.org> with GitHub (or self-host — see the
   Pages CMS docs; self-hosting needs PostgreSQL and a GitHub App).
2. Install the Pages CMS GitHub App on the organisation and grant it access to
   **this repository only**. Private repositories are supported.
3. Open the repository in Pages CMS. It reads `.pages.yml` from the default
   branch and renders the editing UI from it.

## Giving access to the SEO specialist and the rewriter

They do **not** need GitHub accounts and should not get repository access.

In Pages CMS, invite them as **collaborators** by email. Collaborators can edit
content and media; they cannot change the configuration, manage other
collaborators or touch caches. Their writes go through the GitHub App
installation token.

If you want their names on the commits, set in `.pages.yml`:

```yaml
settings:
  commit:
    identity: user
```

Collaborators live in the Pages CMS database, not in `.pages.yml`, so they must
be re-invited if you migrate to another Pages CMS instance.

## Branch and deployment behaviour

Pages CMS commits straight to the default branch (`main`). That is intentional
here: the editorial gate is the `status` field, not a Git branch — non-technical
editors should never see branches or pull requests.

Every commit to `main` triggers `.github/workflows/deploy.yml`, which validates,
builds and deploys. `Draft` and `Review` pages are committed like anything else
but are never rendered into production output, so saving a draft is always safe.

## Media

```yaml
media:
  - name: content        # input: static/images/content  -> output: /images/content
  - name: products       # input: static/images/products -> output: /images/products
```

Uploads land in a fixed directory and the public path written into the content is
the one Nginx serves. Rich-text image uploads use the `content` source. Product
logo fields use the `products` source. Nothing can end up in an arbitrary path.

## What editors never see

Filesystem, YAML, Git, branches, Cecil, SSH, deployment internals, canonical
URLs, `og:locale`, `html lang`, hreflang, absolute domains or schema ids. Those
are either generated or live in files the CMS does not expose.

## When the CMS shows a configuration error

1. Run `./scripts/validate` locally — it mirrors the Pages CMS schema rules and
   usually names the exact key.
2. Regenerate with `./scripts/cms-config` and commit.
3. Collection paths must exist in the repository, including empty ones: every
   section directory therefore carries a `.gitkeep`. `./scripts/new-geo` creates
   them for you.
