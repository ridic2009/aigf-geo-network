<?php

declare(strict_types=1);

namespace AiGf\Engine\Routing;

/**
 * THE single source of truth for URLs across the whole network.
 *
 * A URL is always: <GEO route prefix for the section> + <page slug>.
 * Nothing else in the project is allowed to compose a URL by hand — the Cecil
 * generator (engine/php/Generator/Router.php), the hreflang map builder and the
 * validator all go through this class, so canonical / sitemap / menu / breadcrumb
 * URLs cannot drift apart.
 *
 * `routes` comes from config/common.yml and is overridden per GEO in
 * config/geos/<geo>.yml:
 *
 *   routes:
 *     reviews: erfahrungen   # /erfahrungen/<slug>/
 *     rankings: ''           # /<slug>/          (root level, no section index)
 */
final class RouteResolver
{
    /** @var array<string,string> section => URL prefix */
    private array $routes;

    /**
     * @param array<string,string|null> $routes
     */
    public function __construct(array $routes)
    {
        $normalized = [];
        foreach ($routes as $section => $prefix) {
            $normalized[(string) $section] = trim((string) ($prefix ?? ''), '/');
        }
        $this->routes = $normalized;
    }

    /**
     * Returns the URL prefix of a section.
     * Falls back to the section name when the section is not configured.
     */
    public function prefix(?string $section): string
    {
        if ($section === null || $section === '') {
            return '';
        }

        return $this->routes[$section] ?? $section;
    }

    /**
     * Is the section published at the site root (no prefix, no section index page)?
     */
    public function isRootSection(?string $section): bool
    {
        return $section !== null && $section !== '' && $this->prefix($section) === '';
    }

    /**
     * Builds the final path of a content page.
     *
     * @param string|null $section   Logical section (content sub-directory), e.g. "reviews"
     * @param string      $slug      Localized slug from front matter
     * @param string|null $subFolder Extra folders between the section and the page, e.g. "advanced"
     */
    public function pagePath(?string $section, string $slug, ?string $subFolder = null): string
    {
        $segments = [];

        $prefix = $this->prefix($section);
        if ($prefix !== '') {
            $segments[] = $prefix;
        }
        if ($subFolder !== null && trim($subFolder, '/') !== '') {
            $segments[] = trim($subFolder, '/');
        }
        $segments[] = trim($slug, '/');

        return implode('/', array_filter($segments, static fn ($s) => $s !== ''));
    }

    /**
     * Builds the path of a section index page, or null when the section has no
     * index page (root-level sections such as rankings).
     */
    public function sectionPath(string $section): ?string
    {
        $prefix = $this->prefix($section);

        return $prefix === '' ? null : $prefix;
    }

    /**
     * Absolute URL for a path, given a GEO base URL.
     * Always produces a trailing-slash "pretty" URL, like Cecil does.
     */
    public function absoluteUrl(string $baseUrl, string $path): string
    {
        $base = rtrim($baseUrl, '/') . '/';
        $path = trim($path, '/');

        return $path === '' ? $base : $base . $path . '/';
    }

    /**
     * @return array<string,string>
     */
    public function all(): array
    {
        return $this->routes;
    }
}
