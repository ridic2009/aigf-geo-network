<?php

declare(strict_types=1);

namespace AiGf\Tools;

/**
 * Pre-build content validation (see docs/validation.md).
 *
 * Errors block the build and therefore the deployment; warnings are printed but
 * do not fail CI. Everything checked here is checkable without rendering, so a
 * broken page is caught before Cecil ever runs.
 */
final class ContentValidator
{
    public const STATUSES = ['draft', 'published'];

    private array $errors = [];
    private array $warnings = [];

    public function validate(string $geoCode): array
    {
        $this->errors = [];
        $this->warnings = [];

        $pages = ContentScanner::scan($geoCode);
        $products = Network::products();
        $affiliates = Network::affiliates();
        $authors = Network::authors();
        $typeSections = Network::typeSections($geoCode);
        $ids = array_column($pages, 'id');

        if ($pages === []) {
            $this->error($geoCode, \sprintf('No content found in %s.', Network::contentDir($geoCode)));

            return $this->result();
        }

        $seenPaths = [];
        $hasHome = false;

        foreach ($pages as $page) {
            $where = $geoCode . '/' . $page['relative'];
            $fm = $page['front_matter'];

            if ($page['parse_error'] !== null) {
                $this->error($where, $page['parse_error']);
                continue;
            }

            /* ---- required fields ------------------------------------- */
            if (trim((string) ($fm['title'] ?? '')) === '') {
                $this->error($where, 'Missing required field: title.');
            }

            $type = (string) ($fm['type'] ?? '');
            if ($type === '') {
                $this->error($where, 'Missing required field: type.');
            } elseif (!\array_key_exists($type, $typeSections)) {
                $this->error($where, \sprintf('Unknown page type "%s". Allowed: %s.', $type, implode(', ', array_keys($typeSections))));
            } else {
                $expected = $typeSections[$type];
                if ($type === 'homepage') {
                    $hasHome = true;
                    if (!$page['is_home']) {
                        $this->error($where, 'type "homepage" is only allowed in index.md at the content root.');
                    }
                } elseif ($expected !== null && $page['section'] !== $expected) {
                    $this->error($where, \sprintf('type "%s" must live in the "%s/" directory (found in "%s").', $type, $expected, $page['section'] ?? 'root'));
                } elseif ($expected === null && $page['section'] !== null) {
                    $this->error($where, \sprintf('type "%s" must live at the content root (found in "%s/").', $type, $page['section']));
                }
            }

            $status = (string) ($fm['status'] ?? '');
            if ($status === '') {
                $this->error($where, 'Missing required field: status.');
            } elseif (!\in_array($status, self::STATUSES, true)) {
                $this->error($where, \sprintf('Invalid status "%s". Allowed: %s.', $status, implode(', ', self::STATUSES)));
            }
            $published = $status === 'published';

            /* ---- slug / routing -------------------------------------- */
            if (!$page['is_home'] && !$page['is_section_index']) {
                $slug = (string) ($fm['slug'] ?? '');
                if ($slug === '') {
                    $this->error($where, 'Missing required field: slug.');
                } elseif (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                    $this->error($where, \sprintf('Invalid slug "%s": use lowercase letters, digits and single hyphens only.', $slug));
                }
            }

            if ($published) {
                $path = $page['path'];
                if (isset($seenPaths[$path])) {
                    $this->error($where, \sprintf('Duplicate route "/%s/" — already produced by %s.', $path, $seenPaths[$path]));
                } else {
                    $seenPaths[$path] = $page['relative'];
                }
            }

            /* ---- translation key ------------------------------------- */
            if (($fm['translation_key'] ?? null) === null) {
                $this->warn($where, 'No translation_key: hreflang cannot link this page to its other GEO versions.');
            }

            /* ---- SEO ------------------------------------------------- */
            $seo = (array) ($fm['seo'] ?? []);
            if ($published) {
                if (trim((string) ($seo['title'] ?? '')) === '') {
                    $this->error($where, 'Missing seo.title (required for published pages).');
                } elseif (mb_strlen((string) $seo['title']) > 70) {
                    $this->warn($where, \sprintf('seo.title is %d characters — likely truncated in search results.', mb_strlen((string) $seo['title'])));
                }

                $description = trim((string) ($seo['description'] ?? ''));
                if ($description === '') {
                    $this->error($where, 'Missing seo.description (required for published pages).');
                } elseif (mb_strlen($description) < 50 || mb_strlen($description) > 175) {
                    $this->warn($where, \sprintf('seo.description is %d characters (recommended 50-175).', mb_strlen($description)));
                }

                if (trim((string) ($seo['primary_keyword'] ?? '')) === '') {
                    $this->warn($where, 'No seo.primary_keyword set.');
                }
            }

            foreach (['index', 'follow'] as $flag) {
                if (isset($fm['indexing'][$flag]) && !\is_bool($fm['indexing'][$flag])) {
                    $this->error($where, \sprintf('indexing.%s must be true or false.', $flag));
                }
            }

            if (isset($fm['canonical']) && !isset($fm['canonical']['url'])) {
                $this->error($where, 'canonical must be an object with a "url" key, e.g. canonical: { url: https://… }.');
            }

            /* ---- house style: a hyphen, never an em dash --------------- */
            $raw = (string) $page['body'] . json_encode($fm, \JSON_UNESCAPED_UNICODE);
            if (($dashes = substr_count($raw, "\u{2014}")) > 0) {
                $this->warn($where, \sprintf('%d em dash(es) "—": the sites use a hyphen " - " instead.', $dashes));
            }

            /* ---- one H1 rule ----------------------------------------- */
            if (preg_match('/^#\s+/m', (string) $page['body'])) {
                $this->error($where, 'The body contains a level-1 heading ("# …"). The page title is already the H1 — use "##" and below.');
            }

            /* ---- product references ---------------------------------- */
            $referenced = [];
            if (isset($fm['product'])) {
                $referenced[] = (string) $fm['product'];
            }
            foreach ((array) ($fm['products'] ?? []) as $pid) {
                $referenced[] = (string) $pid;
            }
            foreach ((array) ($fm['top_products'] ?? []) as $pid) {
                $referenced[] = (string) $pid;
            }
            foreach ((array) ($fm['ranking'] ?? []) as $i => $entry) {
                if (!\is_array($entry) || !isset($entry['product'])) {
                    $this->error($where, \sprintf('ranking[%d] must be an object with a "product" key.', $i));
                    continue;
                }
                $referenced[] = (string) $entry['product'];
            }

            $rules = Model::types($geoCode)[$type]['fields'] ?? [];
            foreach (array_merge(Model::validateFields($fm, $rules), Model::validateBlocks($fm['blocks'] ?? [])) as $issue) {
                $this->errors[] = $issue + ['where' => $where];
            }

            foreach (array_unique($referenced) as $pid) {
                if (!isset($products[$pid])) {
                    $this->error($where, \sprintf('Unknown product "%s" — no data/products/%s.yml.', $pid, $pid));
                    continue;
                }
                if ($published && !$this->hasAffiliate($affiliates, $pid, $geoCode)) {
                    $this->warn($where, \sprintf('No affiliate link for "%s" in GEO "%s": the CTA will fall back to the product website.', $pid, $geoCode));
                }
            }

            /* ---- authors --------------------------------------------- */
            foreach (['author', 'reviewer'] as $field) {
                $aid = $fm[$field] ?? null;
                if ($aid !== null && !isset($authors[(string) $aid])) {
                    $this->error($where, \sprintf('Unknown %s "%s" — not in data/authors.yml.', $field, $aid));
                }
            }

            /* ---- FAQ -------------------------------------------------- */
            foreach ((array) ($fm['faq'] ?? []) as $i => $item) {
                if (!\is_array($item)) {
                    $this->error($where, \sprintf('faq[%d] must be an object with "question" and "answer".', $i));
                    continue;
                }
                if (trim((string) ($item['question'] ?? '')) === '') {
                    $this->error($where, \sprintf('faq[%d] has an empty question.', $i));
                }
                if (trim((string) ($item['answer'] ?? '')) === '') {
                    $this->error($where, \sprintf('faq[%d] has an empty answer.', $i));
                }
            }

            /* ---- internal references --------------------------------- */
            foreach ((array) ($fm['related'] ?? []) as $rid) {
                if (!\in_array((string) $rid, $ids, true)) {
                    $this->error($where, \sprintf('related: unknown page id "%s".', $rid));
                }
            }
            if (isset($fm['hero_cta']) && !\in_array((string) $fm['hero_cta'], $ids, true)) {
                $this->error($where, \sprintf('hero_cta: unknown page id "%s".', $fm['hero_cta']));
            }
        }

        if (!$hasHome) {
            $this->error($geoCode, 'No homepage: content/' . $geoCode . '/index.md with type "homepage" is required.');
        }

        $this->validateInternalLinks($geoCode, $pages, $seenPaths);
        $this->validateNavigation($geoCode, $ids, $pages);

        foreach (Redirects::validate($geoCode) as $issue) { $this->errors[] = $issue; }
        return $this->result();
    }

    /**
     * Markdown links to "/something/" must resolve to a route this GEO produces.
     */
    private function validateInternalLinks(string $geoCode, array $pages, array $routes): void
    {
        $known = array_fill_keys(array_keys($routes), true);
        foreach (ContentScanner::generatedSections($geoCode, $pages) as $info) {
            $known[$info['path']] = true;
        }
        $known[''] = true; // homepage

        foreach ($pages as $page) {
            if (($page['front_matter']['status'] ?? 'published') !== 'published') {
                continue;
            }
            $where = $geoCode . '/' . $page['relative'];
            $haystack = $page['body'] . "\n" . json_encode($page['front_matter'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

            if (preg_match_all('~\]\((/[^)\s#?]*)~', $haystack, $m)) {
                foreach (array_unique($m[1]) as $link) {
                    $target = trim($link, '/');
                    if (str_starts_with($target, 'images/') || str_contains($target, '.')) {
                        continue; // assets are checked after the build
                    }
                    if (!isset($known[$target])) {
                        $this->error($where, \sprintf('Broken internal link "/%s/" — no page in GEO "%s" produces that URL.', $target, $geoCode));
                    }
                }
            }
        }
    }

    private function validateNavigation(string $geoCode, array $ids, array $pages): void
    {
        $resolved = Network::resolved($geoCode);
        $navigation = (array) ($resolved['navigation'] ?? []);
        $sections = array_keys(ContentScanner::generatedSections($geoCode, $pages));
        // Pages the engine generates itself (search, site map), declared in
        // config/common.yml -> pages.default rather than written in content/.
        foreach ((array) ($resolved['pages']['default'] ?? []) as $id => $definition) {
            if (($definition['published'] ?? false) === true) {
                $sections[] = (string) $id;
            }
        }
        $labels = (array) (Network::geo($geoCode)['ui']['nav'] ?? []);

        foreach ($navigation as $menu => $items) {
            foreach ((array) $items as $item) {
                $id = (string) ($item['id'] ?? '');
                $label = (string) ($item['label'] ?? '');

                if ($label !== '' && !isset($labels[$label])) {
                    $this->error('config/geos/' . $geoCode . '.yml', \sprintf('Menu "%s": missing ui.nav.%s label.', $menu, $label));
                }
                if ($id !== '' && !\in_array($id, $ids, true) && !\in_array($id, $sections, true)) {
                    $this->warn($geoCode, \sprintf('Menu "%s": page "%s" does not exist in this GEO — the item is skipped.', $menu, $id));
                }
            }
        }
    }

    private function hasAffiliate(array $affiliates, string $productId, string $geoCode): bool
    {
        $entry = $affiliates[$productId] ?? null;
        if ($entry === null) {
            return false;
        }

        return !empty($entry['geo'][Network::geo($geoCode)['site_identity']['market']]['url']) || !empty($entry['default']);
    }

    private function error(string $where, string $message): void
    {
        $this->errors[] = ['where' => $where, 'message' => $message];
    }

    private function warn(string $where, string $message): void
    {
        $this->warnings[] = ['where' => $where, 'message' => $message];
    }

    private function result(): array
    {
        return ['errors' => array_map([Diagnostics::class, 'enrich'], $this->errors), 'warnings' => array_map([Diagnostics::class, 'enrich'], $this->warnings)];
    }
}
