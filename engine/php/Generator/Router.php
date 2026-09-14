<?php

declare(strict_types=1);

namespace AiGf\Engine\Generator;

use AiGf\Engine\Routing\RouteResolver;
use Cecil\Collection\Page\Page;
use Cecil\Collection\Page\Type;
use Cecil\Generator\AbstractGenerator;
use Cecil\Generator\GeneratorInterface;

/**
 * Applies the network routing rules to every page.
 *
 * Why a generator and not Cecil's native `pages.paths`?
 * `pages.paths` is applied during the *Create* step, before front matter is
 * parsed, so a localized `slug:` would silently reset the path back to
 * "<folder>/<slug>". Running at priority 45 (after front matter conversion and
 * after Cecil's Section generator) guarantees that the resolved path is the one
 * used by url(), canonical, sitemap, menus and breadcrumbs — one source, no drift.
 *
 * Content directories stay identical in every GEO ("reviews/", "compare/"…),
 * which keeps page IDs — and therefore navigation config and translation keys —
 * stable across the whole network. Only the prefix and the slug are localized.
 */
final class Router extends AbstractGenerator implements GeneratorInterface
{
    public function generate(): void
    {
        $resolver = new RouteResolver((array) $this->config->get('routes'));
        $sectionLabels = (array) ($this->config->get('ui')['sections'] ?? []);

        /** @var Page $page */
        foreach ($this->builder->getPages() ?? [] as $page) {
            if ($page->getType() === Type::SECTION->value) {
                $this->routeSection($page, $resolver, $sectionLabels);
                continue;
            }

            if ($page->isVirtual() || $page->getType() !== Type::PAGE->value) {
                continue; // homepage, robots, sitemap, 404, redirects
            }
            if ($page->isSectionIndex()) {
                continue; // handled as a section page above
            }

            $section = $page->getSection();
            $page->setPath($resolver->pagePath(
                $section,
                $page->getSlug(),
                $this->subFolder($page->getFolder(), $section)
            ));

            if ($page->getVariable('translation_key') === null) {
                $page->setVariable('translation_key', $page->getId());
            }
        }
    }

    /**
     * Section index pages: /reviews/ -> /erfahrungen/.
     * Sections routed to the root (prefix "") have no index page at all.
     */
    private function routeSection(Page $page, RouteResolver $resolver, array $sectionLabels): void
    {
        $section = $page->getSection();
        if ($section === null) {
            return;
        }

        $path = $resolver->sectionPath($section);
        if ($path === null) {
            // e.g. rankings live at the site root: an index page would collide with the homepage
            $page->setVariable('published', false);

            return;
        }

        $page->setPath($path);

        if ($page->getVariable('translation_key') === null) {
            $page->setVariable('translation_key', $section);
        }
        // localized title for auto-generated section pages (no index.md in content)
        if (isset($sectionLabels[$section]) && $this->isAutoTitle($page, $section)) {
            $page->setVariable('title', (string) $sectionLabels[$section]);
        }
        if ($page->getVariable('type') === null) {
            $page->setVariable('type', 'section');
        }
    }

    /**
     * True when the title is the one Cecil derived from the directory name.
     */
    private function isAutoTitle(Page $page, string $section): bool
    {
        $title = (string) $page->getVariable('title');

        return $title === $section || $title === ucfirst($section) || $title === 'index';
    }

    /**
     * Extra folders between the section directory and the page, e.g.
     * "guides/advanced/foo.md" -> "advanced".
     */
    private function subFolder(?string $folder, ?string $section): ?string
    {
        if ($folder === null || $section === null) {
            return null;
        }
        if ($folder === $section) {
            return null;
        }
        if (str_starts_with($folder, $section . '/')) {
            return substr($folder, \strlen($section) + 1);
        }

        return null;
    }
}
