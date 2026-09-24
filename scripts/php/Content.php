<?php
declare(strict_types=1);
namespace AiGf\Tools;
use Symfony\Component\Yaml\Yaml;

/**
 * A page on disk: where it lives, how it is written back, and what is wrong
 * with it before anything is written at all.
 *
 * Pages CMS commits Markdown files and the build reads them; this is the same
 * contract for anything else that touches `content/` — the MCP server, the CLI,
 * an import script. No workspace, no revisions, no state: the repository is the
 * state, and `git` is the history.
 */
final class Content
{
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

    /**
     * An existing page, or the skeleton of one that does not exist yet.
     */
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
            'exists' => is_file($path), 'hash' => is_file($path) ? hash_file('sha256', $path) : null];
    }

    /**
     * Writes the page back. `hash` is the one `load()` returned: a page that
     * changed underneath — an editor in Pages CMS, a `git pull` — is refused
     * rather than overwritten.
     */
    public static function save(string $site, string $page, array $frontMatter, string $body, ?string $hash = null): array
    {
        $path = self::path($site, $page);
        $current = is_file($path) ? hash_file('sha256', $path) : null;
        if ($hash !== null && $hash !== $current) {
            throw new \RuntimeException('Страница изменилась с момента чтения. Перечитайте её и повторите.', 409);
        }
        $frontMatter = self::omitEmpty($frontMatter);
        $issues = self::issues($site, $page, $frontMatter, $body, ($frontMatter['status'] ?? 'draft') === 'published');
        if ($issues) { return ['ok' => false, 'errors' => $issues]; }
        if (!is_dir(dirname($path))) { mkdir(dirname($path), 0755, true); }
        file_put_contents($path, self::markdown($frontMatter, $body));

        return ['ok' => true, 'page' => $page, 'site' => $site, 'hash' => hash_file('sha256', $path)];
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

    public static function markdown(array $fm, string $body): string
    {
        $yaml = Yaml::dump($fm, 20, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        // A literal block as the last value ("faq:", "verdict:") is dumped with
        // strip chomping and no closing newline; without this the delimiter is
        // glued to the last line and the page loses its front matter entirely.
        if (!str_ends_with($yaml, "\n")) { $yaml .= "\n"; }
        return "---\n" . $yaml . "---\n\n" . $body;
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
