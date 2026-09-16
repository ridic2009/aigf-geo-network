# 03 — Database Architecture

PostgreSQL 16. Источник правды для контента, настроек и состояния системы;
файлы сайтов генерируются из неё при сборке ([ADR 0006](../adr/0006-database-source-of-truth.md)).

## Общие правила

| Правило | Обоснование |
| --- | --- |
| Первичные ключи — UUIDv7 | сортируемы по времени, безопасны для показа наружу, не раскрывают объём |
| `timestamptz` везде, никогда `timestamp` | сеть работает в 20+ таймзонах |
| Гибкие поля — `jsonb` + GIN | схема страницы задаётся манифестом шаблона и меняется без миграций |
| Всё, по чему фильтруем или джойним — обычная колонка | `jsonb` не заменяет индексируемые поля |
| Денежные суммы — `numeric(12,2)` + код валюты | никаких `float` для денег |
| Внешние ключи объявлены всегда | последний рубеж против мусора |
| Мягкое удаление только там, где нужно восстановление | иначе `WHERE deleted_at IS NULL` расползается по всем запросам |

**Решение о `jsonb`.** Поля, описанные манифестом шаблона, лежат в `jsonb`;
`status`, `slug`, `site_id` и прочее, по чему идёт выборка, — обычные колонки.
Альтернативы: EAV (нечитаемо и медленно), колонка на каждое поле (миграция на
каждое изменение шаблона). Цена решения: часть данных не проверяется базой —
проверяется валидатором по схеме шаблона.

## Карта сущностей

```
site_group 1─n site  n─1 geo
                 │       └─1 locale
                 ├─n domain
                 ├─n page ─n page_revision
                 │     └─n─1 page_family ─n page (другие сайты)  → hreflang
                 └─n release ─n release_pages

product 1─n product_market (по geo)
        1─n affiliate      (по geo)

settings / seo_profiles: (scope_type, scope_id) → global | site_group | geo | site | page
```

## Identity

```sql
CREATE TYPE user_status AS ENUM ('active', 'disabled', 'locked');

CREATE TABLE users (
    id                  uuid PRIMARY KEY,
    email               citext NOT NULL UNIQUE,
    name                text NOT NULL,
    password_hash       text NOT NULL,
    status              user_status NOT NULL DEFAULT 'active',
    mfa_enabled         boolean NOT NULL DEFAULT false,
    mfa_secret_enc      bytea,                    -- libsodium, ключ вне БД
    password_changed_at timestamptz NOT NULL,
    last_login_at       timestamptz,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    version             integer NOT NULL DEFAULT 1
);

CREATE TABLE roles (
    id        uuid PRIMARY KEY,
    key       text NOT NULL UNIQUE,      -- admin, ops, seo_lead, editor, author, viewer
    name      text NOT NULL,
    is_system boolean NOT NULL DEFAULT false
);

-- Каталог прав. Заполняется миграцией, не пользователем.
CREATE TABLE permissions (
    key         text PRIMARY KEY,        -- content.page.publish, site.create, …
    description text NOT NULL,
    module      text NOT NULL
);

CREATE TABLE role_permissions (
    role_id        uuid NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    permission_key text NOT NULL REFERENCES permissions(key) ON DELETE CASCADE,
    PRIMARY KEY (role_id, permission_key)
);

CREATE TYPE scope_type AS ENUM ('global', 'site_group', 'geo', 'site', 'page');

-- Роль выдаётся в области видимости: "админ сети" и "редактор трёх сайтов"
-- выражаются одной таблицей, без второй системы прав.
CREATE TABLE user_roles (
    user_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role_id     uuid NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    scope_kind  scope_type NOT NULL,
    scope_id    uuid,                    -- NULL для global
    granted_by  uuid REFERENCES users(id),
    granted_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (user_id, role_id, scope_kind, scope_id)
);

CREATE TABLE sessions (
    id                  uuid PRIMARY KEY,
    user_id             uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    secret_hash         bytea NOT NULL,          -- SHA-256 от секрета из cookie
    created_at          timestamptz NOT NULL DEFAULT now(),
    last_seen_at        timestamptz NOT NULL DEFAULT now(),
    idle_expires_at     timestamptz NOT NULL,
    absolute_expires_at timestamptz NOT NULL,
    rotated_at          timestamptz NOT NULL DEFAULT now(),
    elevated_until      timestamptz,             -- step-up для чувствительных действий
    ip                  inet NOT NULL,
    user_agent          text NOT NULL,
    device_label        text,
    revoked_at          timestamptz,
    revoked_by          uuid REFERENCES users(id)
);
CREATE INDEX ON sessions (user_id) WHERE revoked_at IS NULL;
CREATE INDEX ON sessions (absolute_expires_at);

CREATE TABLE api_tokens (
    id           uuid PRIMARY KEY,
    name         text NOT NULL,
    secret_hash  bytea NOT NULL,
    scopes       text[] NOT NULL,
    created_by   uuid NOT NULL REFERENCES users(id),
    created_at   timestamptz NOT NULL DEFAULT now(),
    expires_at   timestamptz NOT NULL,
    last_used_at timestamptz,
    revoked_at   timestamptz
);

CREATE TABLE login_attempts (
    id              bigserial PRIMARY KEY,
    identifier_hash bytea NOT NULL,       -- хеш логина: не храним попытки по несуществующим
    ip_hash         bytea NOT NULL,
    at              timestamptz NOT NULL DEFAULT now(),
    outcome         text NOT NULL         -- success | bad_password | unknown_user | locked
);
CREATE INDEX ON login_attempts (identifier_hash, at DESC);
CREATE INDEX ON login_attempts (ip_hash, at DESC);

CREATE TABLE password_resets (
    id         uuid PRIMARY KEY,
    user_id    uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash bytea NOT NULL,
    expires_at timestamptz NOT NULL,
    used_at    timestamptz,
    created_at timestamptz NOT NULL DEFAULT now()
);

-- Append-only. UPDATE и DELETE отозваны у роли приложения:
--   REVOKE UPDATE, DELETE ON audit_log FROM app_user;
-- Неизменяемость обеспечена правами СУБД, а не обещанием кода.
CREATE TABLE audit_log (
    id           uuid PRIMARY KEY,
    at           timestamptz NOT NULL DEFAULT now(),
    actor_id     uuid REFERENCES users(id),
    actor_ip     inet,
    session_id   uuid,
    request_id   text,
    action       text NOT NULL,           -- site.created, session.revoked, secret.rotated
    subject_type text NOT NULL,
    subject_id   uuid,
    site_id      uuid,
    data         jsonb NOT NULL DEFAULT '{}'
) PARTITION BY RANGE (at);
CREATE INDEX ON audit_log (actor_id, at DESC);
CREATE INDEX ON audit_log (subject_type, subject_id, at DESC);
```

## Sites

```sql
CREATE TABLE site_groups (
    id          uuid PRIMARY KEY,
    key         text NOT NULL UNIQUE,
    name        text NOT NULL,
    description text
);

CREATE TABLE geos (
    code            text PRIMARY KEY,           -- us, de, fr, jp
    name            text NOT NULL,
    country_code    char(2) NOT NULL,
    currency_code   char(3) NOT NULL,
    currency_format jsonb NOT NULL,             -- symbol, position, decimals, separators
    timezone        text NOT NULL,
    default_locale  text NOT NULL
);

CREATE TABLE locales (
    code     text PRIMARY KEY,                  -- en-US, de-DE, zh-Hant-TW
    language text NOT NULL,
    region   text,
    hreflang text NOT NULL,
    name     text NOT NULL,
    is_rtl   boolean NOT NULL DEFAULT false
);

CREATE TABLE templates (
    id       uuid PRIMARY KEY,
    key      text NOT NULL,
    version  integer NOT NULL,
    name     text NOT NULL,
    manifest jsonb NOT NULL,                    -- page_types, blocks, settings_schema, tokens
    status   text NOT NULL DEFAULT 'active',
    UNIQUE (key, version)
);

CREATE TYPE site_status AS ENUM
    ('draft', 'provisioning', 'live', 'paused', 'archived');

CREATE TABLE sites (
    id               uuid PRIMARY KEY,
    code             text NOT NULL UNIQUE,
    name             text NOT NULL,
    status           site_status NOT NULL DEFAULT 'draft',
    site_group_id    uuid REFERENCES site_groups(id),
    geo_code         text NOT NULL REFERENCES geos(code),
    locale_code      text NOT NULL REFERENCES locales(code),
    template_id      uuid NOT NULL REFERENCES templates(id),
    primary_domain_id uuid,                     -- FK добавляется после domains
    theme            text NOT NULL,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    archived_at      timestamptz,
    version          integer NOT NULL DEFAULT 1
);

CREATE TYPE domain_role   AS ENUM ('primary', 'alias', 'redirect');
CREATE TYPE domain_status AS ENUM
    ('pending_dns', 'verifying', 'active', 'error', 'retired');

CREATE TABLE domains (
    id          uuid PRIMARY KEY,
    site_id     uuid NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    hostname    text NOT NULL UNIQUE,
    role        domain_role NOT NULL,
    dns_status  domain_status NOT NULL DEFAULT 'pending_dns',
    ssl_status  text NOT NULL DEFAULT 'pending',
    verified_at timestamptz,
    expires_at  timestamptz,                    -- срок регистрации, мониторится
    created_at  timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE sites ADD CONSTRAINT sites_primary_domain_fk
    FOREIGN KEY (primary_domain_id) REFERENCES domains(id);

-- Единственная таблица для всех слоёв конфигурации.
-- Резолвер склеивает слои и сообщает происхождение значения.
CREATE TABLE settings (
    scope_kind scope_type NOT NULL,
    scope_id   uuid,                            -- NULL для global
    key        text NOT NULL,
    value      jsonb NOT NULL,
    updated_by uuid REFERENCES users(id),
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (scope_kind, scope_id, key)
);

CREATE TABLE content_blueprints (
    id    uuid PRIMARY KEY,
    key   text NOT NULL UNIQUE,
    name  text NOT NULL,
    pages jsonb NOT NULL      -- скелет страниц нового сайта + привязка к page_family
);
```

## Content

```sql
CREATE TYPE page_status AS ENUM
    ('draft', 'review', 'approved', 'published', 'archived');

-- Группировка переводов одной страницы по всей сети.
-- Заменяет строковый translation_key: связь становится внешним ключом.
CREATE TABLE page_families (
    id   uuid PRIMARY KEY,
    key  text NOT NULL UNIQUE,
    name text NOT NULL
);

CREATE TABLE pages (
    id                    uuid PRIMARY KEY,
    site_id               uuid NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    family_id             uuid REFERENCES page_families(id),
    type                  text NOT NULL,        -- из манифеста шаблона
    slug                  text NOT NULL,
    path                  text NOT NULL,        -- вычисляется UrlBuilder, хранится для поиска
    status                page_status NOT NULL DEFAULT 'draft',
    locale_code           text NOT NULL REFERENCES locales(code),
    current_revision_id   uuid,
    published_revision_id uuid,
    author_id             uuid REFERENCES users(id),
    created_at            timestamptz NOT NULL DEFAULT now(),
    updated_at            timestamptz NOT NULL DEFAULT now(),
    version               integer NOT NULL DEFAULT 1,
    UNIQUE (site_id, path),
    UNIQUE (site_id, type, slug)
);
CREATE INDEX ON pages (site_id, status);
CREATE INDEX ON pages (family_id);

-- Полный снимок, не дельта: восстановление и диф тривиальны,
-- нет риска сломанной цепочки. См. ADR 0010.
CREATE TABLE page_revisions (
    id         uuid PRIMARY KEY,
    page_id    uuid NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    number     integer NOT NULL,
    data       jsonb NOT NULL,      -- поля типа страницы, блоки, SEO-переопределения
    body       text NOT NULL,       -- основной текст в Markdown
    checksum   bytea NOT NULL,
    comment    text,
    created_by uuid NOT NULL REFERENCES users(id),
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (page_id, number)
);

ALTER TABLE pages ADD CONSTRAINT pages_current_revision_fk
    FOREIGN KEY (current_revision_id) REFERENCES page_revisions(id);
ALTER TABLE pages ADD CONSTRAINT pages_published_revision_fk
    FOREIGN KEY (published_revision_id) REFERENCES page_revisions(id);

CREATE TABLE content_locks (
    page_id     uuid PRIMARY KEY REFERENCES pages(id) ON DELETE CASCADE,
    user_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    acquired_at timestamptz NOT NULL DEFAULT now(),
    expires_at  timestamptz NOT NULL
);

CREATE TABLE media (
    id          uuid PRIMARY KEY,
    site_id     uuid REFERENCES sites(id) ON DELETE SET NULL,   -- NULL = общее для сети
    storage_key text NOT NULL UNIQUE,
    hash        bytea NOT NULL,
    mime        text NOT NULL,
    width       integer,
    height      integer,
    bytes       bigint NOT NULL,
    alt         text,
    created_by  uuid NOT NULL REFERENCES users(id),
    created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ON media (hash);
```

## Catalog

```sql
CREATE TABLE products (
    id     uuid PRIMARY KEY,
    key    text NOT NULL UNIQUE,        -- candy-ai, nomi, replika
    name   text NOT NULL,
    vendor text,
    data   jsonb NOT NULL DEFAULT '{}', -- фичи, описание, дефолты сети
    status text NOT NULL DEFAULT 'active'
);

-- Переопределения по рынку: цена, доступность, редакционная оценка.
CREATE TABLE product_markets (
    product_id   uuid NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    geo_code     text NOT NULL REFERENCES geos(code),
    price        numeric(12,2),
    currency     char(3),
    availability text NOT NULL DEFAULT 'available',
    rating       numeric(2,1),          -- NULL => узел Review не выпускается
    data         jsonb NOT NULL DEFAULT '{}',
    PRIMARY KEY (product_id, geo_code)
);

CREATE TABLE affiliates (
    id         uuid PRIMARY KEY,
    product_id uuid NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    geo_code   text NOT NULL REFERENCES geos(code),
    url        text NOT NULL,
    network    text,
    params     jsonb NOT NULL DEFAULT '{}',
    status     text NOT NULL DEFAULT 'active',
    UNIQUE (product_id, geo_code, network)
);

CREATE TABLE authors (
    id              uuid PRIMARY KEY,
    name            text NOT NULL,
    bio             text,
    avatar_media_id uuid REFERENCES media(id),
    credentials     jsonb NOT NULL DEFAULT '{}'
);
```

## SEO

```sql
CREATE TABLE seo_profiles (
    id         uuid PRIMARY KEY,
    scope_kind scope_type NOT NULL,
    scope_id   uuid,
    data       jsonb NOT NULL,   -- шаблоны title, robots, OG, набор узлов schema
    updated_by uuid REFERENCES users(id),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (scope_kind, scope_id)
);

CREATE TABLE redirects (
    id          uuid PRIMARY KEY,
    site_id     uuid NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    source_path text NOT NULL,
    target_path text NOT NULL,
    status_code smallint NOT NULL DEFAULT 301,
    is_auto     boolean NOT NULL DEFAULT false,   -- создан автоматически при смене slug
    created_by  uuid REFERENCES users(id),
    created_at  timestamptz NOT NULL DEFAULT now(),
    hits        bigint NOT NULL DEFAULT 0,
    UNIQUE (site_id, source_path)
);

CREATE TABLE seo_findings (
    id            uuid PRIMARY KEY,
    scope_kind    scope_type NOT NULL,
    scope_id      uuid,
    rule_key      text NOT NULL,
    severity      text NOT NULL,          -- error | warning | info
    message       text NOT NULL,
    detail        jsonb NOT NULL DEFAULT '{}',
    first_seen_at timestamptz NOT NULL DEFAULT now(),
    last_seen_at  timestamptz NOT NULL DEFAULT now(),
    resolved_at   timestamptz,
    UNIQUE (scope_kind, scope_id, rule_key)
);
```

`first_seen_at` вместе с `resolved_at` даёт ответ на вопрос «сколько эта проблема
уже висит» — без него список находок не отличает вчерашнюю ошибку от
двухмесячной.

## Publishing

```sql
CREATE TYPE job_status AS ENUM
    ('queued', 'running', 'succeeded', 'failed', 'cancelled');

CREATE TABLE jobs (
    id              uuid PRIMARY KEY,
    type            text NOT NULL,          -- site.build, site.publish, backup.verify
    payload         jsonb NOT NULL,
    status          job_status NOT NULL DEFAULT 'queued',
    priority        smallint NOT NULL DEFAULT 100,
    attempts        smallint NOT NULL DEFAULT 0,
    max_attempts    smallint NOT NULL DEFAULT 3,
    available_at    timestamptz NOT NULL DEFAULT now(),
    locked_by       text,
    locked_at       timestamptz,
    finished_at     timestamptz,
    error           text,
    idempotency_key text UNIQUE,
    created_by      uuid REFERENCES users(id),
    created_at      timestamptz NOT NULL DEFAULT now()
);
-- Выборка воркером: FOR UPDATE SKIP LOCKED по этому индексу.
CREATE INDEX ON jobs (status, priority, available_at)
    WHERE status = 'queued';

CREATE TABLE builds (
    id            uuid PRIMARY KEY,
    site_id       uuid NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    job_id        uuid REFERENCES jobs(id),
    status        text NOT NULL,
    started_at    timestamptz,
    finished_at   timestamptz,
    artifact_key  text,                    -- ключ в object storage
    artifact_hash bytea,
    stats         jsonb NOT NULL DEFAULT '{}',  -- страниц, размер, длительность
    log_key       text
);

CREATE TABLE servers (
    id       uuid PRIMARY KEY,
    hostname text NOT NULL UNIQUE,
    ip       inet NOT NULL,
    role     text NOT NULL,                -- web | app | db | backup
    provider text NOT NULL,
    status   text NOT NULL DEFAULT 'active',
    metadata jsonb NOT NULL DEFAULT '{}'
);

CREATE TABLE releases (
    id             uuid PRIMARY KEY,
    site_id        uuid NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    build_id       uuid NOT NULL REFERENCES builds(id),
    server_id      uuid NOT NULL REFERENCES servers(id),
    release_ref    text NOT NULL,          -- 20260916-141500
    status         text NOT NULL,
    deployed_at    timestamptz,
    rolled_back_at timestamptz,
    UNIQUE (site_id, server_id, release_ref)
);

-- Какая именно версия текста сейчас на проде.
-- Без этой таблицы вопрос решается по времени деплоя, то есть неточно.
CREATE TABLE release_pages (
    release_id  uuid NOT NULL REFERENCES releases(id) ON DELETE CASCADE,
    page_id     uuid NOT NULL REFERENCES pages(id),
    revision_id uuid NOT NULL REFERENCES page_revisions(id),
    PRIMARY KEY (release_id, page_id)
);
```

## Monitoring

```sql
CREATE TYPE health_state AS ENUM
    ('healthy', 'degraded', 'unhealthy', 'offline', 'unknown');

CREATE TABLE checks (
    id          uuid PRIMARY KEY,
    target_type text NOT NULL,             -- site | domain | server | service
    target_id   uuid NOT NULL,
    kind        text NOT NULL,             -- http | dns | tls | process | resource | db
                                           -- | queue | cron | content | release
    config      jsonb NOT NULL,
    interval_s  integer NOT NULL DEFAULT 60,
    severity    text NOT NULL DEFAULT 'critical',
    enabled     boolean NOT NULL DEFAULT true
);

CREATE TABLE check_results (
    check_id   uuid NOT NULL REFERENCES checks(id) ON DELETE CASCADE,
    at         timestamptz NOT NULL DEFAULT now(),
    status     text NOT NULL,
    latency_ms integer,
    detail     jsonb NOT NULL DEFAULT '{}'
) PARTITION BY RANGE (at);            -- помесячно, retention 90 дней
CREATE INDEX ON check_results (check_id, at DESC);

CREATE TABLE health_states (
    target_type          text NOT NULL,
    target_id            uuid NOT NULL,
    state                health_state NOT NULL,
    since                timestamptz NOT NULL,
    reason               text,
    last_ok_at           timestamptz,
    consecutive_failures smallint NOT NULL DEFAULT 0,
    PRIMARY KEY (target_type, target_id)
);

CREATE TABLE incidents (
    id          uuid PRIMARY KEY,
    target_type text NOT NULL,
    target_id   uuid NOT NULL,
    opened_at   timestamptz NOT NULL DEFAULT now(),
    closed_at   timestamptz,
    severity    text NOT NULL,
    summary     text NOT NULL,
    timeline    jsonb NOT NULL DEFAULT '[]',
    acked_by    uuid REFERENCES users(id),
    acked_at    timestamptz
);

CREATE TABLE alerts (
    id              uuid PRIMARY KEY,
    incident_id     uuid NOT NULL REFERENCES incidents(id) ON DELETE CASCADE,
    channel         text NOT NULL,
    dedup_key       text NOT NULL,
    sent_at         timestamptz NOT NULL DEFAULT now(),
    acknowledged_at timestamptz,
    UNIQUE (dedup_key, incident_id)
);

-- Backup, который ни разу не восстанавливали, не является backup.
-- Отсутствие свежей записи здесь переводит подсистему в состояние degraded.
CREATE TABLE restore_tests (
    id          uuid PRIMARY KEY,
    asset       text NOT NULL,            -- postgres | media | full
    started_at  timestamptz NOT NULL,
    finished_at timestamptz,
    outcome     text NOT NULL,            -- passed | failed
    detail      jsonb NOT NULL DEFAULT '{}'
);
```

## Проверка модели

Три сценария, которые модель обязана выражать запросами к описанным таблицам
**без новых сущностей** (пункт 3 раздела Verification):

| Сценарий | Как выражается |
| --- | --- |
| Добавить 30 сайтов одной группой | `INSERT` в `sites` с общим `site_group_id`; настройки берутся слоем `site_group` из `settings`; контент — из `content_blueprints` |
| Сменить slug опубликованной страницы | `UPDATE pages`, новая `page_revisions`, автоматический `INSERT` в `redirects` с `is_auto = true`, пересборка |
| Откатить сайт на релиз двухнедельной давности | `releases` хранит `release_ref` на каждом сервере; переключение симлинка; `release_pages` показывает, какие ревизии вернутся |
