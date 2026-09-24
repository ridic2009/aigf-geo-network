<?php

declare(strict_types=1);

/**
 * scripts/mcp — an MCP server over the site network.
 *
 * JSON-RPC 2.0 on stdin/stdout, one message per line (the MCP stdio transport).
 * The protocol is small enough to write by hand, which is worth more here than
 * an SDK would be: no second toolchain in a PHP repository.
 *
 * An agent works the way Pages CMS works — it edits files in `content/`,
 * `config/` and `data/` and commits them to `main`. The VPS timer picks the
 * commit up, validates, builds and publishes. There is no second write path and
 * no state of its own to drift: the repository is the state, git is the history.
 *
 *     claude mcp add minicms -- php /path/to/scripts/mcp.php
 *
 * Environment:
 *   MCP_GIT_AUTHOR   "Имя <mail@example.com>" for the commits. Defaults to the
 *                    repository's git identity.
 *   MCP_GIT_PUSH=1   Push after every commit. Off by default: without it the
 *                    work stays local until someone pushes, and nothing an
 *                    agent does reaches the live sites by itself.
 *
 * Nothing here may write to stdout: that stream is the protocol. Builder::run
 * captures every child process, and PHP's own errors are pinned to stderr.
 */

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

require __DIR__ . '/../vendor/autoload.php';

use AiGf\Tools\Backup;
use AiGf\Tools\Builder;
use AiGf\Tools\Catalog;
use AiGf\Tools\CmsConfigValidator;
use AiGf\Tools\Content;
use AiGf\Tools\ContentScanner;
use AiGf\Tools\ContentValidator;
use AiGf\Tools\DataValidator;
use AiGf\Tools\Insight;
use AiGf\Tools\Model;
use AiGf\Tools\Network;
use AiGf\Tools\Sites;

const MCP_SERVER_VERSION = '2.0.0';
const MCP_PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

/* ------------------------------------------------------------------ helpers */

function arg(array $args, string $name, mixed $default = null): mixed
{
    $value = $args[$name] ?? $default;
    if ($value === null && $default === null) {
        throw new InvalidArgumentException(sprintf('Не передан обязательный параметр "%s".', $name));
    }

    return $value;
}

function site(array $args, string $name = 'site'): string
{
    $code = strtolower((string) arg($args, $name));
    if (!Network::exists($code)) {
        throw new InvalidArgumentException(sprintf(
            'Сайт "%s" не найден. Доступны: %s',
            $code,
            implode(', ', Network::codes(false))
        ));
    }

    return $code;
}

/**
 * Commits the files a tool just wrote, and pushes when allowed to.
 *
 * Only the named paths are staged: the working tree may hold somebody else's
 * work in progress, and an agent has no business sweeping it into its commit.
 *
 * @param list<string> $paths repository-relative
 */
function commit(array $paths, string $message): array
{
    $paths = array_values(array_filter($paths));
    if ($paths === []) { return ['committed' => false, 'reason' => 'нечего коммитить']; }

    [$exit, , $err] = Builder::run(array_merge(['git', 'add', '--'], $paths));
    if ($exit !== 0) { throw new RuntimeException('git add: ' . trim($err)); }

    [$exit] = Builder::run(array_merge(['git', 'diff', '--cached', '--quiet', '--'], $paths));
    if ($exit === 0) { return ['committed' => false, 'reason' => 'файлы не изменились']; }

    $command = ['git'];
    $author = trim((string) (getenv('MCP_GIT_AUTHOR') ?: ''));
    if ($author !== '') { $command[] = '-c'; $command[] = 'user.name=' . $author; }
    $command = array_merge($command, ['commit', '-m', $message, '--only', '--'], $paths);
    if ($author !== '') { array_splice($command, 1, 0, ['-c', 'user.email=mcp@local']); }

    [$exit, $out, $err] = Builder::run($command);
    if ($exit !== 0) { throw new RuntimeException('git commit: ' . trim($out . "\n" . $err)); }

    [, $sha] = Builder::run(['git', 'rev-parse', '--short', 'HEAD']);
    $result = ['committed' => true, 'commit' => trim($sha), 'message' => $message];

    if (getenv('MCP_GIT_PUSH') === '1') {
        [$exit, $out, $err] = Builder::run(['git', 'push']);
        $result['pushed'] = $exit === 0;
        if ($exit !== 0) { $result['push_error'] = trim($out . "\n" . $err); }
    } else {
        $result['pushed'] = false;
        $result['note'] = 'Коммит остался локальным. Опубликует его push в main: MCP_GIT_PUSH=1 или человек вручную.';
    }

    return $result;
}

/** Repository-relative path, with the separators git expects. */
function relative(string $absolute): string
{
    $root = str_replace('\\', '/', (string) realpath(Network::root()));
    $path = str_replace('\\', '/', $absolute);

    return ltrim(substr($path, strlen($root)), '/');
}

/**
 * YAML parses `date: 2026-02-03` into a timestamp, and a timestamp is what a
 * round trip would write back. Dates are normalised in both directions.
 */
function normaliseDates(array $frontMatter, array $fields): array
{
    foreach ($fields as $name => $rule) {
        if (($rule['type'] ?? '') !== 'date' || !isset($frontMatter[$name])) { continue; }
        if (is_int($frontMatter[$name])) { $frontMatter[$name] = gmdate('Y-m-d', $frontMatter[$name]); }
    }

    return $frontMatter;
}

/** An empty JSON Schema object must serialise as {}, not as []. */
function schema(array $properties = [], array $required = []): array
{
    return [
        'type'                 => 'object',
        'properties'           => $properties === [] ? new stdClass() : $properties,
        'required'             => $required,
        'additionalProperties' => false,
    ];
}

function text(string $description): array
{
    return ['type' => 'string', 'description' => $description];
}

/* -------------------------------------------------------------------- tools */

/**
 * @return array<string, array{title:string,description:string,schema:array,annotations:array,run:callable}>
 */
function tools(): array
{
    return [
        /* ---------------------------------------------------------- reading */

        'sites_list' => [
            'title'       => 'Сайты сети',
            'description' => 'Список сайтов (GEO): домен, язык, hreflang, префиксы разделов, живой ли сайт. Начинайте отсюда: код сайта нужен почти каждому другому инструменту.',
            'schema'      => schema(),
            'annotations' => ['readOnlyHint' => true],
            'run'         => static function (): array {
                $sites = [];
                foreach (Network::codes(false) as $code) {
                    $geo = Network::geo($code);
                    $sites[] = [
                        'site'     => $code,
                        'title'    => $geo['title'],
                        'baseurl'  => $geo['baseurl'],
                        'language' => $geo['site_identity']['language'] ?? null,
                        'hreflang' => $geo['geo']['hreflang'] ?? null,
                        'market'   => $geo['site_identity']['market'] ?? null,
                        'enabled'  => (bool) ($geo['geo']['enabled'] ?? true),
                        'staging'  => (bool) ($geo['geo']['staging'] ?? false),
                        'routes'   => Network::routes($code),
                        'content'  => $geo['pages']['dir'],
                    ];
                }

                return ['sites' => $sites];
            },
        ],

        'page_types' => [
            'title'       => 'Типы страниц и поля',
            'description' => 'Схема полей каждого типа страницы этого сайта (обзор, рейтинг, сравнение, руководство, статика, главная) и список блоков. Прочитайте её перед page_save — сохранение проверяет ровно эти поля.',
            'schema'      => schema(['site' => text('Код сайта, например "fr".')], ['site']),
            'annotations' => ['readOnlyHint' => true],
            'run'         => static function (array $args): array {
                $code = site($args);
                $types = [];
                foreach (Model::types($code) as $type => $definition) {
                    $types[$type] = [
                        'label'     => $definition['label'] ?? $type,
                        'section'   => $definition['section'] ?? null,
                        'directory' => empty($definition['section']) ? '' : $definition['section'] . '/',
                        'fields'    => Model::fields($code, $type),
                    ];
                }

                return ['site' => $code, 'types' => $types, 'blocks' => Model::blocks()];
            },
        ],

        'pages_list' => [
            'title'       => 'Страницы сайта',
            'description' => 'Все страницы сайта из content/: путь файла, заголовок, тип, статус, адрес и итоговый URL. Фильтры по статусу и типу необязательны.',
            'schema'      => schema([
                'site'   => text('Код сайта.'),
                'status' => ['type' => 'string', 'enum' => ['draft', 'published'], 'description' => 'Показать только страницы с этим статусом.'],
                'type'   => text('Показать только страницы этого типа, например "review".'),
            ], ['site']),
            'annotations' => ['readOnlyHint' => true],
            'run'         => static function (array $args): array {
                $code = site($args);
                $status = $args['status'] ?? null;
                $type = $args['type'] ?? null;
                $pages = [];
                foreach (ContentScanner::scan($code) as $page) {
                    $fm = $page['front_matter'];
                    if ($status !== null && ($fm['status'] ?? 'draft') !== $status) { continue; }
                    if ($type !== null && ($fm['type'] ?? '') !== $type) { continue; }
                    $pages[] = [
                        'page'            => $page['relative'],
                        'id'              => $page['id'],
                        'title'           => $fm['title'] ?? '',
                        'type'            => $fm['type'] ?? null,
                        'status'          => $fm['status'] ?? 'draft',
                        'slug'            => $page['slug'],
                        'url'             => '/' . $page['path'] . '/',
                        'translation_key' => $fm['translation_key'] ?? null,
                        'updated'         => $fm['updated'] ?? null,
                    ];
                }

                return ['site' => $code, 'count' => count($pages), 'pages' => $pages];
            },
        ],

        'page_read' => [
            'title'       => 'Прочитать страницу',
            'description' => 'Front matter, текст и хеш файла. Хеш передайте обратно в page_save: если страницу тем временем поменяли в Pages CMS или подтянули из git, запись будет отклонена, а не затрёт чужое. Несуществующая страница возвращается заготовкой.',
            'schema'      => schema([
                'site' => text('Код сайта.'),
                'page' => text('Путь файла внутри content/<сайт>/, например "reviews/candy-ai.md".'),
                'type' => text('Тип для новой страницы; для существующей игнорируется.'),
            ], ['site', 'page']),
            'annotations' => ['readOnlyHint' => true],
            'run'         => static function (array $args): array {
                $code = site($args);
                $page = (string) arg($args, 'page');
                $doc = Content::load($code, $page, $args['type'] ?? null);
                $type = (string) ($doc['front_matter']['type'] ?? 'static');
                $doc['front_matter'] = normaliseDates($doc['front_matter'], Model::fields($code, $type));
                $doc['issues'] = Content::issues($code, $page, $doc['front_matter'], $doc['body'], false);

                return $doc;
            },
        ],

        'products_list' => [
            'title'       => 'Продукты',
            'description' => 'База продуктов: id, название, рейтинг, рынки с переопределениями, число партнёрских ссылок и страницы, которые на продукт ссылаются. Статьи всегда ссылаются на продукт по id, а не переписывают его данные.',
            'schema'      => schema(),
            'annotations' => ['readOnlyHint' => true],
            'run'         => static fn (): array => ['products' => Catalog::list()],
        ],

        'product_read' => [
            'title'       => 'Карточка продукта',
            'description' => 'Полные данные продукта, его партнёрские ссылки по странам и список страниц, которые его упоминают.',
            'schema'      => schema(['id' => text('Идентификатор продукта, например "candy-ai".')], ['id']),
            'annotations' => ['readOnlyHint' => true],
            'run'         => static fn (array $args): array => Catalog::get((string) arg($args, 'id')),
        ],

        'seo_audit' => [
            'title'       => 'SEO-аудит',
            'description' => 'Проблемы, которые не ломают сборку, но стоят позиций: длина title и description, отсутствие перевода, страница вне меню, тонкий текст, дубли ключевых запросов.',
            'schema'      => schema(['only' => text('Ограничить одним сайтом.')]),
            'annotations' => ['readOnlyHint' => true],
            'run'         => static fn (array $args): array => ['audit' => Insight::audit($args['only'] ?? null)],
        ],

        'translations_report' => [
            'title'       => 'Переводы и hreflang',
            'description' => 'Какие страницы существуют в каких странах, сгруппировано по ключу страницы — так же, как их группирует hreflang. Показывает, где перевода не хватает.',
            'schema'      => schema(),
            'annotations' => ['readOnlyHint' => true],
            'run'         => static fn (): array => ['groups' => Insight::hreflang()],
        ],

        'history' => [
            'title'       => 'Что менялось',
            'description' => 'Последние коммиты: кто, когда, какие файлы. Это и есть история правок — и Pages CMS, и этот сервер пишут в неё. Откат делается git-командой по номеру коммита.',
            'schema'      => schema([
                'limit' => ['type' => 'integer', 'description' => 'Сколько коммитов показать, по умолчанию 20.'],
                'path'  => text('Ограничить путём, например "content/fr".'),
            ]),
            'annotations' => ['readOnlyHint' => true],
            'run'         => static function (array $args): array {
                $limit = max(1, min(200, (int) ($args['limit'] ?? 20)));
                // Real tabs, not "\t": git prints the format verbatim.
                $command = ['git', 'log', '-n', (string) $limit, '--name-only', '--date=iso', "--pretty=format:%h\t%an\t%ad\t%s"];
                if (!empty($args['path'])) { $command[] = '--'; $command[] = (string) $args['path']; }
                [$exit, $out, $err] = Builder::run($command);
                if ($exit !== 0) { throw new RuntimeException('git log: ' . trim($err)); }
                $commits = [];
                foreach (preg_split('/\n\n+/', trim($out)) as $block) {
                    $lines = explode("\n", trim($block));
                    $head = explode("\t", array_shift($lines) ?? '');
                    if (count($head) < 4) { continue; }
                    $commits[] = ['commit' => $head[0], 'author' => $head[1], 'date' => $head[2], 'message' => $head[3], 'files' => array_values(array_filter($lines))];
                }

                return ['commits' => $commits];
            },
        ],

        /* ---------------------------------------------------------- writing */

        'page_save' => [
            'title'       => 'Сохранить страницу',
            'description' => 'Пишет страницу в content/ и коммитит её. Переданные поля front matter накладываются на текущие, так что можно править одно поле. Статус не меняется — для этого есть page_publish. Без MCP_GIT_PUSH коммит остаётся локальным.',
            'schema'      => schema([
                'site'         => text('Код сайта.'),
                'page'         => text('Путь файла внутри content/<сайт>/, например "reviews/candy-ai.md". Тип страницы обязан соответствовать разделу.'),
                'front_matter' => ['type' => 'object', 'description' => 'Поля страницы: title, slug, type, translation_key, seo, intro и поля типа. Схему даёт page_types.', 'additionalProperties' => true],
                'body'         => text('Текст в Markdown, заголовки от ## и ниже: H1 уже задан полем title. HTML запрещён.'),
                'hash'         => text('Хеш из page_read. Без него запись не проверяет, менялся ли файл.'),
            ], ['site', 'page', 'front_matter', 'body']),
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false],
            'run'         => static function (array $args): array {
                $code = site($args);
                $page = (string) arg($args, 'page');
                $current = Content::load($code, $page, ($args['front_matter']['type'] ?? null));
                $type = (string) ($current['front_matter']['type'] ?? 'static');
                $fields = Model::fields($code, $type);
                $frontMatter = normaliseDates((array) arg($args, 'front_matter'), $fields)
                    + normaliseDates($current['front_matter'], $fields);
                // The status is a publishing decision, not a content field.
                $frontMatter['status'] = $current['front_matter']['status'] ?? 'draft';

                $saved = Content::save($code, $page, $frontMatter, (string) arg($args, 'body', ''), $args['hash'] ?? null);
                if (!$saved['ok']) { return $saved; }

                return $saved + ['git' => commit([relative(Content::path($code, $page))], 'content: update ' . $code . '/' . $page)];
            },
        ],

        'page_publish' => [
            'title'       => 'Опубликовать или снять страницу',
            'description' => 'publish — ставит статус «опубликовано» после строгой проверки; unpublish — возвращает страницу в черновики и убирает её с сайта. Согласования нет: публикует тот, кто пишет. На живой сайт изменение попадёт, когда коммит окажется в main: сервер сам соберёт и выложит.',
            'schema'      => schema([
                'site'   => text('Код сайта.'),
                'page'   => text('Путь файла внутри content/<сайт>/.'),
                'action' => ['type' => 'string', 'enum' => ['publish', 'unpublish'], 'description' => 'Действие.'],
            ], ['site', 'page', 'action']),
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true],
            'run'         => static function (array $args): array {
                $code = site($args);
                $page = (string) arg($args, 'page');
                $action = (string) arg($args, 'action');
                if (!in_array($action, ['publish', 'unpublish'], true)) { throw new InvalidArgumentException('action: publish или unpublish.'); }
                $doc = Content::load($code, $page);
                if (!$doc['exists']) { throw new RuntimeException('Страницы ещё нет. Сначала сохраните её через page_save.'); }
                $fm = $doc['front_matter'];
                $fm['status'] = $action === 'publish' ? 'published' : 'draft';

                $saved = Content::save($code, $page, $fm, $doc['body'], $doc['hash']);
                if (!$saved['ok']) { return $saved; }
                $message = ($action === 'publish' ? 'content: publish ' : 'content: unpublish ') . $code . '/' . $page;

                return $saved + ['status' => $fm['status'], 'git' => commit([relative(Content::path($code, $page))], $message)];
            },
        ],

        'product_save' => [
            'title'       => 'Сохранить продукт',
            'description' => 'Создаёт или изменяет продукт и его партнёрские ссылки в data/ и коммитит их. Цена и рейтинг попадут на сайт со следующей сборкой.',
            'schema'      => schema([
                'id'     => text('Идентификатор продукта, он же имя файла, например "candy-ai".'),
                'create' => ['type' => 'boolean', 'description' => 'true — создать новый продукт.'],
                'data'   => ['type' => 'object', 'description' => 'Поля продукта: name, website, logo, rating, price, features, geo (переопределения по странам) и affiliate {default, geo}.', 'additionalProperties' => true],
            ], ['id', 'data']),
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false],
            'run'         => static function (array $args): array {
                $id = (string) arg($args, 'id');
                $result = Catalog::save($id, (array) arg($args, 'data'), (bool) ($args['create'] ?? false));
                $paths = [relative(Network::path('data', 'products', $id . '.yml')), relative(Network::path('data', 'affiliates', $id . '.yml'))];

                return $result + ['git' => commit($paths, 'data: update product ' . $id)];
            },
        ],

        'site_save' => [
            'title'       => 'Создать или настроить сайт',
            'description' => 'Заводит новую страну (create: true — копия существующей, с каталогом контента и главной страницей) или меняет настройки существующей: домен, язык, hreflang, префиксы разделов, надписи интерфейса, меню, редиректы. Смена префиксов переписывает внутренние ссылки и заводит редиректы. Обычные надписи и меню проще править в Pages CMS.',
            'schema'      => schema([
                'id'     => text('Код сайта, например "es".'),
                'create' => ['type' => 'boolean', 'description' => 'true — создать новый сайт.'],
                'data'   => ['type' => 'object', 'description' => 'Настройки: from (с какого сайта копировать при создании), baseurl, title, description, language, locale, market, hreflang, routes, ui, navigation, redirects, enabled, staging.', 'additionalProperties' => true],
            ], ['id', 'data']),
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false],
            'run'         => static function (array $args): array {
                $id = (string) arg($args, 'id');
                $config = Sites::save($id, (array) arg($args, 'data'), (bool) ($args['create'] ?? false));
                $paths = [relative(Network::configFile($id)), relative(Network::path('content', $id))];

                return ['site' => $config, 'git' => commit($paths, 'config: update site ' . $id)];
            },
        ],

        /* ------------------------------------------------ building, checking */

        'validate' => [
            'title'       => 'Проверить сеть',
            'description' => 'Те же проверки, что и перед сборкой: бизнес-данные, схема Pages CMS и контент каждого сайта. Возвращает ошибки и предупреждения структурой. Ничего не меняет.',
            'schema'      => schema([
                'sites' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Коды сайтов; по умолчанию все включённые.'],
            ]),
            'annotations' => ['readOnlyHint' => true],
            'run'         => static function (array $args): array {
                $codes = [];
                foreach ((array) ($args['sites'] ?? Network::codes(true)) as $code) { $codes[] = site(['site' => $code]); }
                $report = ['Business data' => (new DataValidator())->validate(), 'Pages CMS schema' => (new CmsConfigValidator())->validate()];
                foreach ($codes as $code) { $report['Content ' . strtoupper($code)] = (new ContentValidator())->validate($code); }
                $errors = 0;
                $warnings = 0;
                foreach ($report as $result) {
                    $errors += count($result['errors']);
                    $warnings += count($result['warnings']);
                }

                return ['ok' => $errors === 0, 'errors' => $errors, 'warnings' => $warnings, 'report' => $report];
            },
        ],

        'build' => [
            'title'       => 'Собрать сайт локально',
            'description' => 'Собирает один сайт в dist/ через scripts/build и возвращает журнал сборки. Локальная проверка: на живой сайт не влияет. С drafts: true в сборку попадают черновики — только для просмотра.',
            'schema'      => schema([
                'site'   => text('Код сайта.'),
                'drafts' => ['type' => 'boolean', 'description' => 'Включить черновики.'],
            ], ['site']),
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true],
            'run'         => static function (array $args): array {
                $code = site($args);
                // A subprocess, not Builder::build directly: the CLI reports its
                // failures on stderr, and stderr of this process is not the log
                // the agent gets to read. Builder::run captures both streams.
                $command = [PHP_BINARY, Network::codeRoot() . '/scripts/build.php', $code];
                if (!empty($args['drafts'])) { $command[] = '--drafts'; }
                [$exit, $out, $err] = Builder::run($command);

                return ['ok' => $exit === 0, 'site' => $code, 'exit_code' => $exit, 'log' => trim($out . "\n" . $err)];
            },
        ],

        'backup' => [
            'title'       => 'Резервная копия',
            'description' => 'Шифрованный архив исходников и картинок. Git хранит текст, но не изображения и не спасает, если репозиторий недоступен.',
            'schema'      => schema(),
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false],
            'run'         => static fn (): array => Backup::create(),
        ],
    ];
}

/* ------------------------------------------------------------ JSON-RPC loop */

function send(array $message): void
{
    fwrite(STDOUT, json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
}

function reply(mixed $id, array $result): void
{
    send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
}

function fail(mixed $id, int $code, string $message): void
{
    send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
}

function toolList(): array
{
    $list = [];
    foreach (tools() as $name => $tool) {
        $list[] = [
            'name'        => $name,
            'title'       => $tool['title'],
            'description' => $tool['description'],
            'inputSchema' => $tool['schema'],
            'annotations' => ['title' => $tool['title']] + $tool['annotations'],
        ];
    }

    return ['tools' => $list];
}

function callTool(array $params): array
{
    $name = (string) ($params['name'] ?? '');
    $tools = tools();
    if (!isset($tools[$name])) {
        throw new InvalidArgumentException('Unknown tool: ' . $name);
    }
    try {
        $result = $tools[$name]['run']((array) ($params['arguments'] ?? []));
        $text = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        // A failed tool is a result, not a protocol error: the agent should see
        // the message and fix its input — the messages are written for that.
        return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
    }

    return ['content' => [['type' => 'text', 'text' => (string) $text]], 'isError' => false];
}

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') { continue; }

    try {
        $message = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        fail(null, -32700, 'Parse error');
        continue;
    }
    if (!is_array($message)) {
        fail(null, -32600, 'Invalid Request');
        continue;
    }

    $id = $message['id'] ?? null;
    $method = (string) ($message['method'] ?? '');
    $params = (array) ($message['params'] ?? []);

    // Notifications carry no id and must never be answered.
    if ($id === null && str_starts_with($method, 'notifications/')) { continue; }

    try {
        switch ($method) {
            case 'initialize':
                $requested = (string) ($params['protocolVersion'] ?? '');
                reply($id, [
                    'protocolVersion' => in_array($requested, MCP_PROTOCOL_VERSIONS, true) ? $requested : MCP_PROTOCOL_VERSIONS[0],
                    'capabilities'    => ['tools' => new stdClass()],
                    'serverInfo'      => ['name' => 'minicms', 'title' => 'Сеть сайтов', 'version' => MCP_SERVER_VERSION],
                    'instructions'    => 'Сеть статических сайтов: контент в content/<сайт>/, продукты в data/, настройки в config/. '
                        . 'Порядок работы: sites_list → page_types → pages_list → page_read → page_save → page_publish. '
                        . 'Хеш файла из page_read передавайте в page_save, иначе чужая правка будет затёрта. '
                        . 'Записи коммитятся в git; на живой сайт они попадают, когда коммит оказывается в main.',
                ]);
                break;

            case 'ping':
                reply($id, []);
                break;

            case 'tools/list':
                reply($id, toolList());
                break;

            case 'tools/call':
                reply($id, callTool($params));
                break;

            default:
                if ($id !== null) { fail($id, -32601, 'Method not found: ' . $method); }
        }
    } catch (Throwable $e) {
        if ($id !== null) { fail($id, -32602, $e->getMessage()); }
    }
}
