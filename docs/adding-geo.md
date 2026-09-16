# Adding a GEO

A new country is a config file, a content directory and a domain. Nothing is
cloned: the new site uses the same engine, components, validation and pipeline as
every other GEO.

## 1. Register the preset

`config/network.yml` holds language, locale, currency and domain presets so
nobody has to type technical locale codes by hand. Check that your country is
there; add it if not:

```yaml
geos:
  es: { name: 'España', language: es, locale: es_ES, hreflang: es-ES,
        currency: EUR, symbol: '€', timezone: Europe/Madrid,
        domain: 'aigirlfriendranking-spain.site' }
```

This file is scaffolding only — it is never read at build time.

## 2. Scaffold

```bash
./scripts/new-geo es --from=us
```

This creates:

- `config/geos/es.yml` — domain, language, locale, currency, routes and UI labels
  copied from the source GEO, marked `TODO` for translation
- `content/es/` with `reviews/`, `rankings/`, `compare/`, `guides/` (each with a
  `.gitkeep`)
- draft copies of the source GEO's pages (`--no-content` to start empty instead)
- a regenerated hreflang map

The new GEO starts with `geo.enabled: false`, so `build-all`, `deploy-all` and
the hreflang map skip it while it is being prepared. Flip it to `true` once the
homepage is published (step 7 below).

## 3. DNS

Add the domain to Cloudflare, A record to the VPS, proxy enabled.
Registrar → Cloudflare DNS → VPS.

## 4. Localize the config

Edit `config/geos/es.yml`:

```yaml
title: 'AI Girlfriend Ranking España'
description: '…'

routes:                    # localized URL prefixes
  reviews: opiniones       # /opiniones/<slug>/
  compare: comparativa
  guides: guias
  rankings: ''             # rankings live at the site root

ui:
  nav:   { ranking: 'Mejores novias IA', reviews: 'Opiniones', … }
  sections: { reviews: 'Opiniones', … }
  labels: { home: 'Inicio', pros: 'Ventajas', … }
```

Every `ui.*` key that exists in `config/geos/us.yml` must exist here — the
validator reports missing navigation labels as errors.

## 5. Translate the content

Work in Studio (Сайты → España) or directly in `content/es/`. Keep the **file
names identical** to the other GEOs — that is what links translations together
for hreflang. Only `slug` and the text change.

Set `status: published` when a page is ready.

## 6. Nginx and TLS

```bash
./scripts/nginx-config es
```

Install the vhost and request a certificate — see [deployment.md](deployment.md).

## 7. Build and deploy

```bash
./scripts/validate es
./scripts/build es
./scripts/deploy es
```

## 8. Connect the CMS

`./scripts/new-geo` already regenerated the hreflang map. The new country
appears under **Sites** the next time an editor loads the CMS.

## Checklist

- [ ] preset in `config/network.yml`
- [ ] `./scripts/new-geo <code>`
- [ ] domain in Cloudflare DNS
- [ ] `config/geos/<code>.yml` translated (title, description, routes, ui)
- [ ] content translated and published
- [ ] `geo.enabled: true` in `config/geos/<code>.yml`
- [ ] `./scripts/nginx-config <code>` + vhost installed + certificate issued
- [ ] `./scripts/build <code>` clean
- [ ] `./scripts/deploy <code>`
- [ ] hreflang verified: the new URLs appear on the other GEOs after their next build

The last point matters: hreflang is reciprocal. After adding a GEO, rebuild and
redeploy the whole network once (`./scripts/build-all && ./scripts/deploy-all`,
or simply push to `main`) so the existing sites start pointing at the new one.

## Disabling a GEO

Set `geo.enabled: false` in `config/geos/<code>.yml`. It disappears from
`build-all`, `deploy-all` and the hreflang map, but the content and config stay
in the repository.
