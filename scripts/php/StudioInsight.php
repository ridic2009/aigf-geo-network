<?php
declare(strict_types=1);
namespace AiGf\Tools;

/**
 * One pass over the whole network, for the two questions an SEO actually asks:
 * "where are the gaps between countries" and "what is wrong with what we have".
 *
 * This deliberately does not repeat ContentValidator, which already fails the
 * build on missing fields, bad slugs, duplicate routes, broken internal links
 * and unknown product or author references. What it adds is everything that is
 * only visible across pages: duplicate titles, thin pages, pages nothing links
 * to, and translations that exist in one market and not another.
 *
 * Findings are advisory. Nothing here blocks a publication — an orphan page or
 * a short one can be perfectly deliberate.
 */
final class StudioInsight
{
    /** Languages written without spaces, where a word count means nothing. */
    private const DENSE_LANGUAGES = ['ja', 'zh', 'ko', 'th'];

    /**
     * Every page of every site the user may see, with the derived facts the
     * other two methods need.
     *
     * @return array{sites:list<array<string,mixed>>,pages:list<array<string,mixed>>}
     */
    public static function index(array $user): array
    {
        $sites = [];
        $pages = [];
        foreach (Network::codes(false) as $id) {
            try { StudioAuth::requireSite($user, $id); } catch (\Throwable) { continue; }
            $config = Network::geo($id);
            $identity = $config['site_identity'];
            $language = strtolower(substr((string) ($identity['language'] ?? 'en'), 0, 2));
            $sites[] = [
                'id' => $id,
                'title' => $config['title'],
                'market' => $identity['market'] ?? $id,
                'language' => $identity['language'] ?? '',
                'group' => $identity['translation_group'] ?? $identity['brand'] ?? $id,
                'staging' => (bool) ($config['geo']['staging'] ?? false),
                'enabled' => (bool) ($config['geo']['enabled'] ?? true),
            ];
            $menu = [];
            foreach (Network::resolved($id)['navigation'] ?? [] as $items) {
                foreach ((array) $items as $item) { $menu[(string) ($item['id'] ?? '')] = true; }
            }
            foreach (ContentScanner::scan($id) as $page) {
                $pages[] = self::page($id, $language, $sites[count($sites) - 1]['group'], $page, $menu);
            }
        }
        return ['sites' => $sites, 'pages' => $pages];
    }

    /**
     * Which pages exist in which market, grouped the way hreflang groups them:
     * by translation_group, then by translation_key.
     */
    public static function hreflang(array $user): array
    {
        ['sites' => $sites, 'pages' => $pages] = self::index($user);
        $byGroup = [];
        foreach ($sites as $site) { $byGroup[$site['group']]['sites'][$site['id']] = $site; }

        foreach ($pages as $page) {
            $group = $page['group'];
            $key = $page['translation_key'];
            if ($key === '') {
                $byGroup[$group]['untranslatable'][] = ['site' => $page['site'], 'page' => $page['page'], 'title' => $page['title']];
                continue;
            }
            $byGroup[$group]['keys'][$key]['key'] = $key;
            $byGroup[$group]['keys'][$key]['title'] ??= $page['title'];
            $byGroup[$group]['keys'][$key]['sites'][$page['site']] = [
                'page' => $page['page'], 'status' => $page['status'], 'title' => $page['title'], 'url' => $page['url'],
            ];
        }

        $out = [];
        foreach ($byGroup as $group => $data) {
            $siteIds = array_keys($data['sites'] ?? []);
            $keys = array_values($data['keys'] ?? []);
            foreach ($keys as &$row) {
                // A staging site is excluded from hreflang, so a hole there is
                // not a hole a search engine will ever see.
                $row['missing'] = array_values(array_filter($siteIds, static fn ($id) => !isset($row['sites'][$id]) && empty($data['sites'][$id]['staging'])));
                $row['published'] = count(array_filter($row['sites'], static fn ($s) => $s['status'] === 'published'));
            } unset($row);
            usort($keys, static fn ($a, $b) => count($b['missing']) <=> count($a['missing']) ?: strcmp($a['key'], $b['key']));
            $out[] = [
                'group' => $group,
                'sites' => array_values($data['sites'] ?? []),
                'keys' => $keys,
                'untranslatable' => $data['untranslatable'] ?? [],
            ];
        }
        usort($out, static fn ($a, $b) => strcmp($a['group'], $b['group']));
        return $out;
    }

    /**
     * Cross-page problems, newest concern first.
     *
     * @return array{findings:list<array<string,mixed>>,checked:int,sites:list<string>}
     */
    public static function audit(array $user, ?string $only = null): array
    {
        ['sites' => $sites, 'pages' => $pages] = self::index($user);
        if ($only !== null && $only !== '') {
            $pages = array_values(array_filter($pages, static fn ($p) => $p['site'] === $only));
            $sites = array_values(array_filter($sites, static fn ($s) => $s['id'] === $only));
        }
        $findings = [];
        $add = static function (string $level, string $code, string $message, array $page, ?string $field = null) use (&$findings): void {
            $findings[] = [
                'level' => $level, 'code' => $code, 'message' => $message,
                'site' => $page['site'], 'page' => $page['page'], 'title' => $page['title'],
                'status' => $page['status'], 'field' => $field,
            ];
        };

        // Duplicates are only duplicates inside one site: two countries sharing
        // a title is normal, two pages of one site sharing it is not.
        foreach ($sites as $site) {
            $ofSite = array_values(array_filter($pages, static fn ($p) => $p['site'] === $site['id']));
            $live = array_values(array_filter($ofSite, static fn ($p) => $p['status'] === 'published'));
            foreach (['seo_title' => 'seo.title', 'seo_description' => 'seo.description'] as $key => $field) {
                $seen = [];
                foreach ($ofSite as $page) {
                    $value = trim((string) $page[$key]);
                    if ($value === '') { continue; }
                    $seen[mb_strtolower($value)][] = $page;
                }
                foreach ($seen as $group) {
                    if (count($group) < 2) { continue; }
                    foreach ($group as $page) {
                        $others = array_values(array_filter($group, static fn ($p) => $p['page'] !== $page['page']));
                        $add('error', 'duplicate', sprintf('Такой же %s уже у %d стр.: %s.', $field, count($others),
                            implode(', ', array_map(static fn ($p) => $p['page'], array_slice($others, 0, 3)))), $page, $field);
                    }
                }
            }

            // Anything published that nothing points at is invisible to a
            // crawler that starts from the homepage.
            $linked = [];
            foreach ($live as $page) {
                foreach ($page['links'] as $target) { $linked[$target] = true; }
                if ($page['in_menu']) { $linked['/' . $page['path'] . '/'] = true; }
            }
            foreach ($live as $page) {
                if ($page['is_home'] || $page['is_section_index']) { continue; }
                if (!isset($linked['/' . $page['path'] . '/'])) {
                    $add('warning', 'orphan', 'На страницу не ведёт ни одна внутренняя ссылка и её нет в меню.', $page);
                }
            }
        }

        foreach ($pages as $page) {
            if ($page['thin']) {
                $add('warning', 'thin', $page['dense']
                    ? sprintf('В тексте %d знаков — мало для страницы этого типа.', $page['chars'])
                    : sprintf('В тексте %d слов — мало для страницы этого типа.', $page['words']), $page, 'body');
            }
            foreach ($page['images'] as $image) {
                if (trim((string) $image['alt']) === '') {
                    $add('warning', 'alt', 'Изображение без описания alt: ' . $image['src'], $page, 'body');
                }
            }
            if ($page['noindex'] && $page['status'] === 'published' && $page['in_menu']) {
                $add('error', 'noindex', 'Страница закрыта от индексации, но стоит в меню сайта.', $page, 'indexing');
            }
        }

        // Published problems first: they are live right now.
        $weight = ['error' => 0, 'warning' => 1];
        usort($findings, static fn ($a, $b) => ($b['status'] === 'published') <=> ($a['status'] === 'published')
            ?: $weight[$a['level']] <=> $weight[$b['level']]
            ?: strcmp($a['site'], $b['site']) ?: strcmp($a['page'], $b['page']));

        return ['findings' => $findings, 'checked' => count($pages), 'sites' => array_column($sites, 'id')];
    }

    // ---------------------------------------------------------------- internals

    private static function page(string $site, string $language, string $group, array $record, array $menu): array
    {
        $fm = $record['front_matter'];
        $body = (string) $record['body'];
        $dense = in_array($language, self::DENSE_LANGUAGES, true);
        $text = self::plain($body);
        $words = $text === '' ? 0 : count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $chars = mb_strlen($text);
        $type = (string) ($fm['type'] ?? 'static');

        // A homepage or a section index is a hub, not an article: judging it by
        // body length would flag every correctly built site.
        $floor = ['review' => 400, 'comparison' => 350, 'guide' => 400, 'ranking' => 250][$type] ?? 0;
        $thin = $floor > 0 && ($dense ? $chars < $floor * 2 : $words < $floor);

        return [
            'site' => $site,
            'group' => $group,
            'page' => $record['relative'],
            'id' => $record['id'],
            'title' => (string) ($fm['title'] ?? $record['relative']),
            'type' => $type,
            'status' => (string) ($fm['status'] ?? 'draft'),
            'path' => $record['path'],
            'url' => $record['url'],
            'is_home' => $record['is_home'],
            'is_section_index' => $record['is_section_index'],
            'in_menu' => isset($menu[$record['id']]),
            'translation_key' => (string) ($fm['translation_key'] ?? ''),
            'seo_title' => (string) ($fm['seo']['title'] ?? ''),
            'seo_description' => (string) ($fm['seo']['description'] ?? ''),
            'noindex' => ($fm['indexing']['index'] ?? true) === false,
            'indexable' => ($fm['status'] ?? '') === 'published' && ($fm['indexing']['index'] ?? true) !== false,
            'words' => $words,
            'chars' => $chars,
            'dense' => $dense,
            'thin' => $thin,
            'links' => self::links($body, $fm),
            'images' => self::images($body, $fm),
        ];
    }

    /** Markdown stripped down to readable text, so a word count means words. */
    private static function plain(string $body): string
    {
        $text = preg_replace('~```.*?```~su', ' ', $body) ?? $body;
        $text = preg_replace('~!\[[^\]]*\]\([^)]*\)~u', ' ', $text) ?? $text;
        $text = preg_replace('~\[([^\]]*)\]\([^)]*\)~u', '$1', $text) ?? $text;
        $text = preg_replace('~^\s{0,3}#{1,6}\s+~mu', '', $text) ?? $text;
        $text = preg_replace('~[*_`>|-]+~u', ' ', $text) ?? $text;
        return trim(preg_replace('~\s+~u', ' ', $text) ?? $text);
    }

    /** @return list<string> internal destinations, as "/path/" */
    private static function links(string $body, array $fm): array
    {
        $out = [];
        if (preg_match_all('~\]\((/[^)\s]*)\)~u', $body, $matches)) {
            foreach ($matches[1] as $href) { $out[] = self::normalise($href); }
        }
        // Block URLs and related-page ids are links too, just structured ones.
        array_walk_recursive($fm, static function ($value, $key) use (&$out): void {
            if (!is_string($value) || $value === '') { return; }
            if (str_starts_with($value, '/')) { $out[] = self::normalise($value); }
        });
        return array_values(array_unique($out));
    }

    /** @return list<array{src:string,alt:string}> */
    private static function images(string $body, array $fm): array
    {
        $out = [];
        if (preg_match_all('~!\[([^\]]*)\]\(([^)\s]+)~u', $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) { $out[] = ['alt' => $match[1], 'src' => $match[2]]; }
        }
        if (!empty($fm['image'])) { $out[] = ['alt' => (string) ($fm['image_alt'] ?? ''), 'src' => (string) $fm['image']]; }
        foreach ((array) ($fm['blocks'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'image' && !empty($block['src'])) {
                $out[] = ['alt' => (string) ($block['alt'] ?? ''), 'src' => (string) $block['src']];
            }
        }
        return $out;
    }

    private static function normalise(string $href): string
    {
        $path = strtok($href, '?#');
        return '/' . trim((string) $path, '/') . '/';
    }
}
