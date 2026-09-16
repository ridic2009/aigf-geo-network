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
        return getenv('MINICMS_ROOT') ?: self::codeRoot();
    }

    public static function codeRoot(): string { return \dirname(__DIR__, 2); }

    public static function reset(): void { self::$common = null; self::$geoCache = []; }

    public static function assertId(string $id): void
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $id)) {
            throw new \InvalidArgumentException('Некорректный ID: ' . $id);
        }
    }

    public static function configFile(string $id): string
    {
        self::assertId($id);
        $site = self::path('config', 'sites', $id . '.yml');
        return is_file($site) ? $site : self::path('config', 'geos', $id . '.yml');
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
        foreach (array_merge(glob(self::path('config', 'geos', '*.yml')) ?: [], glob(self::path('config', 'sites', '*.yml')) ?: []) as $file) {
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

        return array_values(array_unique($codes));
    }

    public static function exists(string $code): bool
    {
        return is_file(self::configFile($code));
    }

    public static function geo(string $code): array
    {
        if (!isset(self::$geoCache[$code])) {
            $file = self::configFile($code);
            if (!is_file($file)) {
                throw new \RuntimeException(\sprintf('Unknown GEO "%s": config/geos/%s.yml does not exist.', $code, $code));
            }
            $config = Yaml::parseFile($file) ?? [];
            if (isset($config['extends_geo'])) {
                self::assertId((string) $config['extends_geo']);
                $base = Yaml::parseFile(self::path('config', 'geos', $config['extends_geo'] . '.yml')) ?? [];
                $config = self::merge($base, $config);
                unset($config['extends_geo']);
            }
            $identity = $config['site'] ?? [];
            $identity = array_replace([
                'id' => $code, 'brand' => 'aigf', 'market' => $config['geo']['code'] ?? $code,
                'language' => $config['language'] ?? 'en', 'locale' => $config['languages'][0]['locale'] ?? 'en_US',
                'translation_group' => 'aigf', 'model' => 'reviews', 'theme' => 'classic',
            ], $identity);
            $identity['id'] = $code;
            $config['site_identity'] = $identity;
            $config['site'] = $identity;
            $config['geo']['code'] = $identity['market'];
            $config['language'] = $identity['language'];
            $config['languages'][0]['code'] = $identity['language'];
            $config['languages'][0]['locale'] = $identity['locale'];
            $config['pages']['dir'] ??= 'content/' . $code;
            self::$geoCache[$code] = $config;
        }

        return self::$geoCache[$code];
    }

    /** Replace lists, recursively merge mappings (numeric list merging duplicates menu entries). */
    public static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $base[$key] = is_array($value) && !array_is_list($value) && isset($base[$key]) && is_array($base[$key])
                ? self::merge($base[$key], $value) : $value;
        }
        return $base;
    }

    public static function resolved(string $id): array
    {
        $config = self::merge(self::common(), self::geo($id));
        $config['page_types'] = Model::types($id);
        $config['block_types'] = Model::blocks();
        $config['theme_asset'] = Theme::asset($id);
        return $config;
    }

    public static function buildConfig(string $id): string
    {
        $relative = '.cecil/generated/sites/' . $id . '.yml';
        $path = self::path(...explode('/', $relative));
        if (!is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
        $config = self::resolved($id);
        $asset = $config['theme_asset'];
        file_put_contents(dirname($path) . '/' . $asset, Theme::css($id));
        // Cecil copies this generated asset in both build and live-preview modes.
        $config['static']['mounts']['../.cecil/generated/sites/' . $asset] = $asset;
        file_put_contents($path, Yaml::dump($config, 20, 2));
        return $relative;
    }

    /**
     * A staging GEO runs on a temporary host (a preview server, an sslip.io
     * name). It is served exactly like production but must never be indexable.
     */
    public static function isStaging(string $code): bool
    {
        return (self::geo($code)['geo']['staging'] ?? false) === true;
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

    public static function pageTypes(?string $site = null): array
    {
        return $site === null ? (array) (self::common()['page_types'] ?? []) : Model::types($site);
    }

    /** page type => section (null for root-level types). */
    public static function typeSections(?string $site = null): array
    {
        $map = [];
        foreach (self::pageTypes($site) as $type => $def) {
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
