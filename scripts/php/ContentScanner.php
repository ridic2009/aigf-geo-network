<?php

declare(strict_types=1);

namespace AiGf\Tools;

use AiGf\Engine\Routing\RouteResolver;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads a GEO content tree and resolves every page the same way the Cecil build
 * does — through AiGf\Engine\Routing\RouteResolver. The validator and the
 * hreflang map builder both consume this, so "what URL will this page get?" has
 * exactly one answer in the whole project.
 */
final class ContentScanner
{
    /**
     * @return array<int, array<string,mixed>> page records
     */
    public static function scan(string $geoCode): array
    {
        $dir = Network::contentDir($geoCode);
        if (!is_dir($dir)) {
            return [];
        }

        $resolver = new RouteResolver(Network::routes($geoCode));
        $baseUrl = Network::baseUrl($geoCode);
        $pages = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || !\in_array(strtolower($file->getExtension()), ['md', 'markdown'], true)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), \strlen($dir) + 1));
            $record = self::record($geoCode, $file->getPathname(), $relative, $resolver, $baseUrl);
            if ($record !== null) {
                $pages[] = $record;
            }
        }

        usort($pages, static fn ($a, $b) => strcmp($a['id'], $b['id']));

        return $pages;
    }

    private static function record(
        string $geoCode,
        string $absolute,
        string $relative,
        RouteResolver $resolver,
        string $baseUrl
    ): ?array {
        [$frontMatter, $body, $error] = self::parse($absolute);

        $withoutExt = preg_replace('/\.(md|markdown)$/i', '', $relative) ?? $relative;
        $segments = explode('/', $withoutExt);
        $fileName = array_pop($segments);
        $folder = implode('/', $segments);
        $section = $segments[0] ?? null;
        $isSectionIndex = $fileName === 'index' && $folder !== '';
        $isHome = $fileName === 'index' && $folder === '';

        $id = $isHome ? 'index' : ($isSectionIndex ? $folder : $withoutExt);

        $slug = (string) ($frontMatter['slug'] ?? $fileName);

        if ($isHome) {
            $path = '';
        } elseif ($isSectionIndex) {
            $path = $resolver->sectionPath((string) $section) ?? '';
        } else {
            $subFolder = null;
            if ($section !== null && $folder !== $section && str_starts_with($folder, $section . '/')) {
                $subFolder = substr($folder, \strlen($section) + 1);
            }
            $path = $resolver->pagePath($section, $slug, $subFolder);
        }

        return [
            'geo'             => $geoCode,
            'file'            => $absolute,
            'relative'        => $relative,
            'id'              => $id,
            'section'         => $isHome ? null : $section,
            'slug'            => $slug,
            'path'            => $path,
            'url'             => $resolver->absoluteUrl($baseUrl, $path),
            'is_home'         => $isHome,
            'is_section_index' => $isSectionIndex,
            'front_matter'    => $frontMatter,
            'body'            => $body,
            'parse_error'     => $error,
        ];
    }

    /**
     * @return array{0: array<string,mixed>, 1: string, 2: ?string}
     */
    public static function parse(string $file): array
    {
        $raw = (string) file_get_contents($file);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $normalized = str_replace("\r\n", "\n", $raw);

        if (!preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $normalized, $m)) {
            return [[], $normalized, 'Missing YAML front matter (a page must start with a "---" block).'];
        }

        try {
            $data = Yaml::parse($m[1]) ?? [];
        } catch (\Throwable $e) {
            return [[], $m[2], 'Invalid YAML front matter: ' . $e->getMessage()];
        }

        if (!\is_array($data)) {
            return [[], $m[2], 'Front matter must be a YAML mapping.'];
        }

        return [$data, $m[2], null];
    }

    /**
     * Section index pages Cecil generates automatically (a section with at least
     * one published page and a non-empty route prefix).
     *
     * @param array<int, array<string,mixed>> $pages
     *
     * @return array<string, array{section: string, path: string, url: string}>
     */
    public static function generatedSections(string $geoCode, array $pages): array
    {
        $resolver = new RouteResolver(Network::routes($geoCode));
        $baseUrl = Network::baseUrl($geoCode);
        $sections = [];

        foreach ($pages as $page) {
            $section = $page['section'];
            if ($section === null || $page['is_section_index']) {
                continue;
            }
            if (($page['front_matter']['status'] ?? 'published') !== 'published') {
                continue;
            }
            $path = $resolver->sectionPath($section);
            if ($path === null) {
                continue; // routed to the site root: no section index page
            }
            $sections[$section] = [
                'section' => $section,
                'path'    => $path,
                'url'     => $resolver->absoluteUrl($baseUrl, $path),
            ];
        }

        return $sections;
    }
}
