<?php

declare(strict_types=1);

namespace AiGf\Tools;

use Symfony\Component\Yaml\Yaml;

/**
 * Reads the network definition from config/.
 *
 * config/geos/<code>.yml is the source of truth for a GEO. `baseurl` is the only
 * place a domain is written down: the host, the output directory and the Nginx
 * vhost name are all derived from it.
 */
final class Network
{
    private static ?array $common = null;
    private static array $geoCache = [];

    public static function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    public static function path(string ...$parts): string
    {
        return self::root() . \DIRECTORY_SEPARATOR . implode(\DIRECTORY_SEPARATOR, $parts);
    }

    public static function common(): array
    {
        if (self::$common === null) {
            self::$common = Yaml::parseFile(self::path('config', 'common.yml')) ?? [];
        }

        return self::$common;
    }

    /** Scaffolding presets (config/network.yml) — not used at build time. */
    public static function presets(): array
    {
        $file = self::path('config', 'network.yml');

        return is_file($file) ? (Yaml::parseFile($file) ?? []) : [];
    }

    /**
     * @return string[] GEO codes that have a config file, sorted
     */
    public static function codes(bool $onlyEnabled = true): array
    {
        $codes = [];
        foreach (glob(self::path('config', 'geos', '*.yml')) ?: [] as $file) {
            $code = basename($file, '.yml');
            if (str_starts_with($code, '_')) {
                continue; // templates
            }
            if ($onlyEnabled && !self::isEnabled($code)) {
                continue;
            }
            $codes[] = $code;
        }
        sort($codes);

        return $codes;
    }

    public static function exists(string $code): bool
    {
        return is_file(self::path('config', 'geos', $code . '.yml'));
    }

    public static function geo(string $code): array
    {
        if (!isset(self::$geoCache[$code])) {
            $file = self::path('config', 'geos', $code . '.yml');
            if (!is_file($file)) {
                throw new \RuntimeException(\sprintf('Unknown GEO "%s": config/geos/%s.yml does not exist.', $code, $code));
            }
            self::$geoCache[$code] = Yaml::parseFile($file) ?? [];
        }

        return self::$geoCache[$code];
    }

    public static function isEnabled(string $code): bool
    {
        $geo = self::geo($code);

        return ($geo['geo']['enabled'] ?? true) !== false;
    }

    public static function baseUrl(string $code): string
    {
        $baseurl = (string) (self::geo($code)['baseurl'] ?? '');
        if ($baseurl === '') {
            throw new \RuntimeException(\sprintf('GEO "%s" has no `baseurl`.', $code));
        }

        return rtrim($baseurl, '/') . '/';
    }

    /** Host derived from baseurl — used for dist/<host>/ and the Nginx vhost. */
    public static function host(string $code): string
    {
        $host = parse_url(self::baseUrl($code), \PHP_URL_HOST);
        if (!\is_string($host) || $host === '') {
            throw new \RuntimeException(\sprintf('GEO "%s" has an invalid `baseurl`.', $code));
        }

        return $host;
    }

    public static function contentDir(string $code): string
    {
        $dir = self::geo($code)['pages']['dir'] ?? ('content/' . $code);

        return self::root() . \DIRECTORY_SEPARATOR . str_replace('/', \DIRECTORY_SEPARATOR, (string) $dir);
    }

    /** Routing table: common defaults overridden by the GEO. */
    public static function routes(string $code): array
    {
        $common = self::common()['routes'] ?? [];
        $geo = self::geo($code)['routes'] ?? [];

        return array_replace((array) $common, (array) $geo);
    }

    /** section => localized label, used for section index pages. */
    public static function sectionLabels(string $code): array
    {
        return (array) (self::geo($code)['ui']['sections'] ?? []);
    }

    public static function pageTypes(): array
    {
        return (array) (self::common()['page_types'] ?? []);
    }

    /** page type => section (null for root-level types). */
    public static function typeSections(): array
    {
        $map = [];
        foreach (self::pageTypes() as $type => $def) {
            $map[$type] = $def['section'] ?? null;
        }

        return $map;
    }

    public static function products(): array
    {
        return self::loadDir(self::path('data', 'products'));
    }

    public static function affiliates(): array
    {
        return self::loadDir(self::path('data', 'affiliates'));
    }

    public static function authors(): array
    {
        return self::loadDir(self::path('data', 'authors'));
    }

    private static function loadDir(string $dir): array
    {
        $out = [];
        foreach (glob($dir . \DIRECTORY_SEPARATOR . '*.yml') ?: [] as $file) {
            $out[basename($file, '.yml')] = Yaml::parseFile($file) ?? [];
        }

        return $out;
    }
}
