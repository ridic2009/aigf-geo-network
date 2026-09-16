<?php
declare(strict_types=1);
namespace AiGf\Tools;
use Symfony\Component\Yaml\Yaml;

final class StudioSites
{
    /** Normalises a redirect path to the /leading/and/trailing/ form the server matches. */
    private static function path(string $value): string
    {
        $value = trim($value);
        if ($value === '' || $value === '/') { return $value === '/' ? '/' : ''; }
        return '/' . trim($value, '/') . '/';
    }

    public static function save(array $user, string $id, array $input, bool $create = false): array
    {
        StudioAuth::requireAdmin($user);
        Network::assertId($id);
        return StudioStore::transaction(function (&$state) use ($user, $id, $input, $create) {
            foreach ($state['jobs'] as $job) { if ($job['state'] === 'publishing') { throw new \RuntimeException('Настройки можно менять после завершения текущей публикации.', 409); } }
            if ($create && Network::exists($id)) { throw new \RuntimeException('Такой ID сайта уже существует.', 409); }
            if (!$create && !Network::exists($id)) { throw new \InvalidArgumentException('Сайт не найден.'); }
            $source = (string) ($input['from'] ?? 'us');
            $config = Network::geo($create ? $source : $id);
            $oldPages = $create ? [] : ContentScanner::scan($id);
            $oldSections = $create ? [] : ContentScanner::generatedSections($id, $oldPages);
            $previous = $create ? null : Network::baseUrl($id);
            $url = rtrim((string) ($input['baseurl'] ?? $config['baseurl']), '/') . '/';
            $parts = parse_url($url);
            if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || ($parts['path'] ?? '/') !== '/' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['port'])
                || !preg_match('/^[a-z0-9.-]+$/D', $parts['host'] ?? '')) { throw new \InvalidArgumentException('Укажите домен сайта: https://example.com/. Каждый язык пока размещается на отдельном домене или поддомене.'); }
            foreach (Network::codes(false) as $other) { if ($other !== $id && Network::host($other) === $parts['host']) { throw new \InvalidArgumentException('Этот домен уже используется другим сайтом.'); } }
            $identity = $config['site_identity'];
            foreach (['brand', 'market', 'translation_group', 'theme', 'model'] as $key) {
                $value = trim((string) ($input[$key] ?? $identity[$key])); Network::assertId($value); $identity[$key] = $value;
            }
            $identity['id'] = $id;
            foreach (['language', 'locale'] as $key) {
                $value = (string) ($input[$key] ?? $identity[$key]);
                if (!preg_match('/^[a-zA-Z]{2,3}(?:[-_][a-zA-Z0-9]{2,8})*$/D', $value)) { throw new \InvalidArgumentException('Проверьте код языка/локали.'); }
                $identity[$key] = $value;
            }
            $hreflang = (string) ($input['hreflang'] ?? $config['geo']['hreflang']);
            if (!preg_match('/^[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8})*$/D', $hreflang)) { throw new \InvalidArgumentException('Проверьте hreflang.'); }
            foreach (Network::codes(false) as $other) {
                if ($other === $id) { continue; }
                $o = Network::geo($other);
                if ($o['site_identity']['translation_group'] === $identity['translation_group'] && $o['geo']['hreflang'] === $hreflang) { throw new \InvalidArgumentException('В этой группе переводов уже есть сайт с таким hreflang. Для другого бренда задайте отдельную группу.'); }
            }
            $config['site'] = $identity; unset($config['site_identity'], $config['extends_geo']);
            $config['geo']['hreflang'] = $hreflang;
            $config['geo']['code'] = $identity['market'];
            $config['geo']['country_code'] = strtoupper($identity['market']);
            $market = Network::presets()['geos'][$identity['market']] ?? null;
            if ($market && ($create || $identity['market'] !== Network::geo($id)['site_identity']['market'])) {
                $config['geo']['name'] = $market['name'];
                $config['geo']['currency'] = array_replace($config['geo']['currency'], [
                    'code' => $market['currency'], 'symbol' => $market['symbol'], 'decimals' => $market['decimals'] ?? 2,
                    'position' => $market['position'] ?? 'after',
                ]);
            }
            $config['baseurl'] = $url;
            foreach (['title', 'description'] as $key) { $config[$key] = trim((string) ($input[$key] ?? $config[$key] ?? '')); }
            if ($config['title'] === '') { throw new \InvalidArgumentException('Введите название сайта.'); }
            $config['geo']['staging'] = (bool) ($input['staging'] ?? ($create ? true : $config['geo']['staging'] ?? false));
            $config['geo']['enabled'] = (bool) ($input['enabled'] ?? ($create ? false : $config['geo']['enabled'] ?? true));
            if (!$config['geo']['staging'] && $parts['scheme'] !== 'https') { throw new \InvalidArgumentException('Для production нужен HTTPS.'); }
            if (isset($input['accent'])) {
                if (!preg_match('/^#[a-fA-F0-9]{6}$/D', $input['accent'])) { throw new \InvalidArgumentException('Неверный цвет.'); }
                $config['appearance']['tokens']['c-accent'] = $input['accent'];
            }
            if ($create) {
                $config['pages']['dir'] = 'content/' . $id;
                $config['navigation'] = ['main' => [], 'footer' => []];
                $config['redirects'] = []; unset($config['previous_domains']);
                $config['organization']['name'] = $config['title'];
                $config['organization']['legal_name'] = '';
                $config['organization']['email'] = '';
                $dir = Network::path('content', $id);
                if (file_exists($dir)) { throw new \RuntimeException('Каталог контента уже существует.'); }
            }
            if (isset($input['routes'])) {
                if (!is_array($input['routes'])) { throw new \InvalidArgumentException('Некорректная структура адресов.'); }
                foreach ($input['routes'] as $section => $prefix) {
                    Network::assertId((string) $section);
                    if (!is_string($prefix) || ($prefix !== '' && !preg_match('~^[a-z0-9_-]+(?:/[a-z0-9_-]+)*$~D', $prefix))) { throw new \InvalidArgumentException('Префикс раздела: латинские буквы, цифры, дефисы и /.'); }
                }
                $config['routes'] = array_replace($config['routes'] ?? [], $input['routes']);
            }
            if (isset($input['ui'])) {
                $walk = static function (array $base, array $values) use (&$walk): array {
                    foreach ($values as $key => $value) {
                        if (!array_key_exists($key, $base)) { throw new \InvalidArgumentException('Неизвестное название интерфейса.'); }
                        if (is_array($base[$key])) {
                            if (!is_array($value)) { throw new \InvalidArgumentException('Некорректные тексты интерфейса.'); }
                            $base[$key] = $walk($base[$key], $value);
                        } else {
                            if (!is_string($value) || strlen($value) > 4000) { throw new \InvalidArgumentException('Текст интерфейса слишком длинный.'); }
                            $base[$key] = $value;
                        }
                    }
                    return $base;
                };
                $config['ui'] = $walk($config['ui'], (array) $input['ui']);
            }
            if (isset($input['navigation'])) {
                $config['navigation'] = [];
                foreach (['main', 'footer'] as $menu) {
                    $items = $input['navigation'][$menu] ?? [];
                    if (!is_array($items) || count($items) > 50) { throw new \InvalidArgumentException('В меню допускается до 50 ссылок.'); }
                    $config['navigation'][$menu] = [];
                    foreach (array_values($items) as $weight => $item) {
                        if (!preg_match('~^[a-z0-9_-]+(?:/[a-z0-9_-]+)*$~D', $item['id'] ?? '') || trim($item['text'] ?? '') === '' || strlen($item['text']) > 200) {
                            throw new \InvalidArgumentException('Для пункта меню нужны ID страницы без .md и подпись (до 200 байт).');
                        }
                        $config['navigation'][$menu][] = ['id' => $item['id'], 'text' => trim($item['text']), 'weight' => $weight];
                    }
                }
            }
            if (isset($input['redirects'])) {
                if (!is_array($input['redirects']) || count($input['redirects']) > 500) { throw new \InvalidArgumentException('Не больше 500 редиректов.'); }
                $map = [];
                foreach ($input['redirects'] as $rule) {
                    $from = self::path((string) ($rule['from'] ?? ''));
                    $to = self::path((string) ($rule['to'] ?? ''));
                    if ($from === '' && $to === '') { continue; }
                    if (!Redirects::validPath($from) || !Redirects::validPath($to)) {
                        throw new \InvalidArgumentException('Адрес редиректа: внутренний путь вида /old/ — латиница, цифры, дефисы.');
                    }
                    if ($from === $to) { throw new \InvalidArgumentException('Редирект сам на себя: ' . $from); }
                    if (isset($map[$from])) { throw new \InvalidArgumentException('Старый адрес указан дважды: ' . $from); }
                    $map[$from] = $to;
                }
                $config['redirects'] = $map;
            }
            if ($previous !== null && parse_url($previous, PHP_URL_HOST) !== $parts['host']) {
                $config['previous_domains'] = array_values(array_unique(array_merge($config['previous_domains'] ?? [], [parse_url($previous, PHP_URL_HOST)])));
            }
            $config['previous_domains'] = array_values(array_filter($config['previous_domains'] ?? [], static fn ($host) => $host !== $parts['host']));
            $file = Network::path('config', 'sites', $id . '.yml');
            $original = is_file($file) ? file_get_contents($file) : null;
            if (!is_dir(dirname($file))) { mkdir(dirname($file), 0755, true); }
            file_put_contents($file, Yaml::dump($config, 20, 2)); Network::reset();
            $originals = []; $replacements = [];
            try {
                Model::types($id); Theme::css($id);
                foreach ($oldPages as $page) {
                    if (!isset(Model::types($id)[$page['front_matter']['type'] ?? 'static'])) {
                        throw new \InvalidArgumentException('Модель не поддерживает тип страницы ' . $page['relative'] . '. Сначала перенесите её в совместимый тип.');
                    }
                }
                if (isset($input['redirects'])) {
                    $issues = Redirects::validate($id);
                    if ($issues) { throw new \InvalidArgumentException($issues[0]['message']); }
                }
                if (!$create && isset($input['routes'])) {
                    $newSections = ContentScanner::generatedSections($id, ContentScanner::scan($id));
                    $sectionMoves = [];
                    foreach ($oldSections as $section => $oldSection) {
                        $from = '/' . $oldSection['path'] . '/';
                        $to = isset($newSections[$section]) ? '/' . $newSections[$section]['path'] . '/' : '/';
                        if ($from !== $to) { $sectionMoves[$from] = $to; }
                    }
                    $config['redirects'] = array_replace($config['redirects'] ?? [], $sectionMoves);
                    foreach ($newSections as $section) { unset($config['redirects']['/' . $section['path'] . '/']); }
                    file_put_contents($file, Yaml::dump($config, 20, 2)); Network::reset();
                    $replacements = UrlMigration::routes($id, $oldPages, $originals, $sectionMoves);
                    $errors = (new ContentValidator())->validate($id)['errors'];
                    if ($errors) { throw new \RuntimeException($errors[0]['message']); }
                }
            } catch (\Throwable $e) {
                foreach ($originals as $path => $body) { file_put_contents($path, $body); }
                if ($original === null) { unlink($file); } else { file_put_contents($file, $original); }
                Network::reset(); throw $e;
            }
            if ($replacements) {
                foreach ($state['documents'] as &$doc) {
                    if ($doc['site'] !== $id) { continue; }
                    $doc['body'] = UrlMigration::rewrite($doc['body'], $replacements);
                    $doc['front_matter'] = UrlMigration::rewriteValues($doc['front_matter'], $replacements);
                    $path = realpath(StudioContent::path($id, $doc['page']));
                    if (isset($originals[$path]) && $doc['base_hash'] === hash('sha256', $originals[$path])) {
                        $doc['base_hash'] = hash_file('sha256', $path);
                        [$fm] = ContentScanner::parse($path); $doc['front_matter']['aliases'] = $fm['aliases'] ?? [];
                    }
                    $doc['revision']++; $doc['state'] = 'draft'; unset($doc['approved_revision'], $doc['approved_by']);
                    $doc['history'][] = ['at' => gmdate('c'), 'actor' => $user['login'], 'action' => 'routes_changed', 'revision' => $doc['revision']];
                } unset($doc);
            }
            if ($create) {
                mkdir($dir, 0755, true);
                foreach (Model::types($id) as $type) {
                    if (!empty($type['section'])) {
                        $sectionDir = $dir . '/' . $type['section'];
                        if (!is_dir($sectionDir)) { mkdir($sectionDir, 0755, true); }
                        file_put_contents($sectionDir . '/.gitkeep', '');
                    }
                }
                $fm = ['title' => $config['title'], 'type' => 'homepage', 'status' => 'draft', 'translation_key' => 'index', 'seo' => ['title' => $config['title'], 'description' => $config['description']], 'date' => gmdate('Y-m-d')];
                file_put_contents($dir . '/index.md', StudioContent::markdown($fm, ''));
            }
            StudioHistory::recordMany(array_merge([$file], array_keys($originals)), $user['login'],
                $create ? 'site.created' : 'site.updated', 'Настройки сайта ' . $id);
            StudioStore::audit($state, $user['login'], $create ? 'site.created' : 'site.updated', ['site' => $id]);
            return Network::geo($id);
        });
    }
}
