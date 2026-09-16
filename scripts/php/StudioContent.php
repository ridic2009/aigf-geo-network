<?php
declare(strict_types=1);
namespace AiGf\Tools;
use Symfony\Component\Yaml\Yaml;

final class StudioContent
{
    public static function key(string $site, string $page): string { return hash('sha256', $site . '/' . $page); }
    public static function path(string $site, string $page): string
    {
        if (!preg_match('~^(?:[a-z0-9_-]+/)*[a-z0-9_-]+\.md$~D', $page)) { throw new \InvalidArgumentException('Некорректный путь страницы.'); }
        $dir = Network::contentDir($site);
        $root = realpath(Network::root());
        $absolute = $dir . '/' . $page;
        $parent = dirname($absolute);
        while (!is_dir($parent)) { $parent = dirname($parent); }
        if (!$root || !str_starts_with(str_replace('\\', '/', realpath($parent)), str_replace('\\', '/', $root) . '/content/')) {
            throw new \InvalidArgumentException('Каталог контента должен находиться внутри content/.');
        }
        if (is_link($absolute)) { throw new \InvalidArgumentException('Символические ссылки не поддерживаются.'); }
        return $absolute;
    }

    public static function load(string $site, string $page, ?string $type = null): array
    {
        $path = self::path($site, $page);
        if (is_file($path)) {
            [$fm, $body, $error] = ContentScanner::parse($path);
            if ($error) { throw new \RuntimeException($error); }
        } else {
            $type ??= 'static';
            if (!isset(Model::types($site)[$type])) { throw new \InvalidArgumentException('Неизвестный тип страницы.'); }
            $fm = ['title' => '', 'slug' => basename($page, '.md'), 'type' => $type, 'status' => 'draft', 'translation_key' => basename($page, '.md'), 'seo' => ['title' => '', 'description' => ''], 'date' => gmdate('Y-m-d'), 'indexing' => ['index' => true, 'follow' => true]];
            $body = '';
        }
        return ['site' => $site, 'page' => $page, 'front_matter' => $fm, 'body' => $body,
            'revision' => 0, 'state' => 'draft', 'owner' => null, 'base_hash' => is_file($path) ? hash_file('sha256', $path) : null,
            'source_status' => $fm['status'] ?? 'draft', 'published_revision' => null, 'history' => [], 'errors' => []];
    }

    public static function get(array $user, string $site, string $page, ?string $type = null): array
    {
        StudioAuth::requireSite($user, $site);
        return StudioStore::transaction(fn (&$s) => $s['documents'][self::key($site, $page)] ?? self::load($site, $page, $type));
    }

    public static function issues(string $site, string $page, array $fm, string $body, bool $strict): array
    {
        $type = (string) ($fm['type'] ?? '');
        $types = Model::types($site);
        if (!isset($types[$type])) { return [Diagnostics::enrich(['where' => "$site/$page", 'field' => 'type', 'message' => 'Выберите тип страницы.'])]; }
        $errors = Model::validateFields($fm, Model::fields($site, $type), '', $strict);
        $errors = array_merge($errors, Model::validateBlocks($fm['blocks'] ?? []));
        if ($page !== 'index.md' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', (string) ($fm['slug'] ?? ''))) { $errors[] = ['field' => 'slug', 'message' => 'В адресе допустимы строчные латинские буквы, цифры и дефисы.']; }
        $section = $types[$type]['section'] ?? null;
        if (($type === 'homepage') !== ($page === 'index.md') || ($section !== null && !str_starts_with($page, $section . '/')) || ($section === null && str_contains($page, '/'))) {
            $errors[] = ['field' => 'type', 'message' => 'Тип страницы не соответствует выбранному разделу. Создайте её в нужном разделе.'];
        }
        if (preg_match('/^#\s/m', $body)) { $errors[] = ['field' => 'body', 'message' => 'Заголовок H1 уже задан названием. Используйте H2 (##) и ниже.']; }
        // Never let an author inject scripts, HTML attributes or executable Markdown URLs.
        $text = $body . json_encode($fm, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $decoded = html_entity_decode(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = preg_replace('/[\x00-\x20]/', '', $decoded);
        if (preg_match('/<\s*\/?[a-z!]|(?:javascript|vbscript|data)\s*:/i', $decoded)) { $errors[] = ['field' => 'body', 'message' => 'Используйте Markdown и готовые блоки. HTML и исполняемые ссылки запрещены.']; }
        foreach ($errors as &$issue) { $issue = Diagnostics::enrich($issue + ['where' => "$site/$page"]); }
        return $errors;
    }

    public static function save(array $user, string $site, string $page, array $payload): array
    {
        StudioAuth::requireSite($user, $site);
        return StudioStore::transaction(function (&$state) use ($user, $site, $page, $payload) {
            $key = self::key($site, $page);
            $doc = $state['documents'][$key] ?? self::load($site, $page, $payload['front_matter']['type'] ?? null);
            self::editable($user, $doc);
            if ((int) ($payload['revision'] ?? -1) !== $doc['revision']) { throw new \RuntimeException('Страница уже изменена. Обновите её перед сохранением.', 409); }
            $fm = $payload['front_matter'] ?? [];
            $allowed = array_keys(Model::fields($site, (string) ($doc['front_matter']['type'] ?? 'static')));
            $allowed = array_merge($allowed, ['blocks', 'image', 'image_alt', 'breadcrumb_title']);
            // Only declared content fields can change; status, layout, paths and aliases are server-owned.
            $fm = array_replace($doc['front_matter'], array_intersect_key($fm, array_flip($allowed)));
            $fm = self::omitEmpty($fm);
            $fm['status'] = 'draft';
            $body = (string) ($payload['body'] ?? '');
            if (strlen($body) + strlen(json_encode($fm)) > 1000000) { throw new \InvalidArgumentException('Материал превышает 1 МБ.'); }
            $issues = self::issues($site, $page, $fm, $body, false);
            if ($issues) { return ['ok' => false, 'errors' => $issues]; }
            $doc['front_matter'] = $fm; $doc['body'] = $body;
            $doc['owner'] ??= $user['login'];
            $doc['revision']++; $doc['state'] = 'draft'; $doc['errors'] = [];
            unset($doc['approved_by'], $doc['approved_revision']);
            $doc['history'][] = ['at' => gmdate('c'), 'actor' => $user['login'], 'action' => 'saved', 'revision' => $doc['revision']];
            $state['documents'][$key] = $doc;
            StudioStore::audit($state, $user['login'], 'document.saved', ['site' => $site, 'page' => $page, 'revision' => $doc['revision']]);
            return ['ok' => true, 'document' => $doc];
        });
    }

    private static function editable(array $user, array $doc): void
    {
        if ($doc['state'] === 'publishing') { throw new \RuntimeException('Дождитесь окончания публикации.', 409); }
        if ($user['role'] === 'author' && $doc['owner'] !== null && $doc['owner'] !== $user['login']) { throw new \RuntimeException('Автор может изменять только свои материалы.', 403); }
    }

    public static function transition(array $user, string $site, string $page, string $action, int $revision): array
    {
        StudioAuth::requireSite($user, $site, in_array($action, ['approve', 'publish', 'reject'], true));
        return StudioStore::transaction(function (&$state) use ($user, $site, $page, $action, $revision) {
            $key = self::key($site, $page);
            $doc = $state['documents'][$key] ?? null;
            if (!$doc) { throw new \RuntimeException('Сначала сохраните материал.'); }
            self::editable($user, $doc);
            if ($doc['revision'] !== $revision) { throw new \RuntimeException('Ревизия изменилась. Откройте актуальную версию.', 409); }
            $allowed = ['submit' => ['draft', 'publication_failed'], 'approve' => ['review'], 'reject' => ['review', 'approved', 'publication_failed'], 'publish' => ['approved', 'publication_failed']];
            if (!isset($allowed[$action]) || !in_array($doc['state'], $allowed[$action], true)) { throw new \RuntimeException('Этот переход состояния недоступен.', 409); }
            if ($action !== 'reject') {
                $issues = self::issues($site, $page, $doc['front_matter'], $doc['body'], true);
                if ($issues) { return ['ok' => false, 'errors' => $issues]; }
            }
            if ($action === 'submit') { $doc['state'] = 'review'; }
            if ($action === 'approve') { $doc['state'] = 'approved'; $doc['approved_by'] = $user['login']; $doc['approved_revision'] = $revision; }
            if ($action === 'reject') { $doc['state'] = 'draft'; unset($doc['approved_by'], $doc['approved_revision']); }
            if ($action === 'publish') {
                if (($doc['approved_revision'] ?? -1) !== $revision) { throw new \RuntimeException('Эта ревизия ещё не одобрена.', 409); }
                foreach ($state['jobs'] as $job) { if ($job['key'] === $key && in_array($job['state'], ['queued', 'publishing'], true)) { throw new \RuntimeException('Материал уже в очереди.', 409); } }
                $id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
                $state['jobs'][$id] = ['id' => $id, 'key' => $key, 'document' => $doc, 'state' => 'queued', 'created_at' => gmdate('c'), 'requested_by' => $user['login']];
                $doc['job'] = $id; $doc['state'] = 'approved';
            }
            $doc['history'][] = ['at' => gmdate('c'), 'actor' => $user['login'], 'action' => $action, 'revision' => $revision];
            $state['documents'][$key] = $doc;
            StudioStore::audit($state, $user['login'], 'document.' . $action, ['site' => $site, 'page' => $page, 'revision' => $revision]);
            return ['ok' => true, 'document' => $doc];
        });
    }

    public static function markdown(array $fm, string $body): string
    {
        return "---\n" . Yaml::dump($fm, 20, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK) . "---\n\n" . $body;
    }

    private static function omitEmpty(array $fields): array
    {
        foreach ($fields as $key => $value) {
            // Blank optional form inputs must behave like absent YAML fields.
            // Preserve list positions so an empty FAQ answer still gets a precise error.
            if (is_array($value)) {
                $fields[$key] = self::omitEmpty($value);
                if (!is_int($key) && $fields[$key] === []) { unset($fields[$key]); }
            }
            elseif (!is_int($key) && ($value === '' || $value === null)) { unset($fields[$key]); }
        }
        return $fields;
    }
}
