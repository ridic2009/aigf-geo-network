<?php

declare(strict_types=1);

namespace AiGf\Tools;

/**
 * Post-build verification of the generated static output.
 *
 * This is the check that makes "canonical must equal the route" a build-time
 * guarantee rather than a convention: the expected canonical is derived from the
 * file's own location on disk and compared with what the HTML actually says.
 */
final class OutputValidator
{
    private array $errors = [];
    private array $warnings = [];

    public function validate(string $geoCode, string $distDir): array
    {
        $this->errors = [];
        $this->warnings = [];

        if (!is_dir($distDir)) {
            $this->error($geoCode, \sprintf('Output directory "%s" does not exist.', $distDir));

            return $this->result();
        }

        $baseUrl = Network::baseUrl($geoCode);
        $lang = (string) (Network::geo($geoCode)['geo']['hreflang'] ?? $geoCode);
        $htmlFiles = $this->files($distDir, 'html');

        if ($htmlFiles === []) {
            $this->error($geoCode, 'Build produced no HTML files.');

            return $this->result();
        }

        // A GEO being prepared has content but nothing published yet. Say so
        // once, instead of drowning the operator in downstream symptoms.
        if (!is_file($distDir . \DIRECTORY_SEPARATOR . 'index.html')) {
            $this->error($geoCode, \sprintf(
                'No published homepage: content/%s/index.md must have status "published". '
                . 'While a GEO is being prepared, set geo.enabled: false in config/geos/%s.yml so it is skipped by build-all.',
                $geoCode,
                $geoCode
            ));

            return $this->result();
        }

        foreach ($htmlFiles as $file) {
            $this->checkHtml($file, $distDir, $baseUrl, $lang);
        }

        $this->checkAssets($distDir, $htmlFiles);
        $this->checkSitemap($geoCode, $distDir, $baseUrl);
        $this->checkRobots($distDir, $baseUrl, Network::isStaging($geoCode));

        if (Network::isStaging($geoCode)) {
            $this->checkStagingIsNotIndexable($distDir, $htmlFiles);
        }

        return $this->result();
    }

    /**
     * Parses the page with DOMDocument rather than regexes: production output is
     * minified (unquoted, reordered attributes), so string matching would give
     * false failures.
     */
    private function checkHtml(string $file, string $distDir, string $baseUrl, string $lang): void
    {
        $relative = str_replace('\\', '/', substr($file, \strlen($distDir) + 1));
        $html = (string) file_get_contents($file);

        if (str_contains($html, '{{') || str_contains($html, '{%')) {
            $this->error($relative, 'Output contains an unrendered Twig expression ("{{" or "{%").');
        }

        $xpath = self::xpath($html);

        $h1Count = $xpath->query('//h1')->length;
        if ($h1Count !== 1) {
            $this->error($relative, \sprintf('Expected exactly one <h1>, found %d.', $h1Count));
        }

        if (trim(self::text($xpath, '//title')) === '') {
            $this->error($relative, 'Missing or empty <title>.');
        }

        if (trim(self::attr($xpath, '//meta[@name="description"]/@content')) === '') {
            $this->error($relative, 'Missing or empty meta description.');
        }

        $htmlLang = self::attr($xpath, '/html/@lang');
        if ($htmlLang === '') {
            $this->error($relative, 'Missing <html lang> attribute.');
        } elseif ($htmlLang !== $lang) {
            $this->error($relative, \sprintf('<html lang="%s"> does not match the GEO language "%s".', $htmlLang, $lang));
        }

        // canonical must equal the URL this very file is served at
        if (basename($file) === 'index.html') {
            $dir = trim(\dirname($relative), './');
            $expected = rtrim($baseUrl, '/') . '/' . ($dir === '' ? '' : $dir . '/');
            $canonical = self::attr($xpath, '//link[@rel="canonical"]/@href');

            if ($canonical === '') {
                $this->error($relative, 'Missing canonical link.');
            } elseif ($canonical !== $expected) {
                if (!str_starts_with($canonical, rtrim($baseUrl, '/'))) {
                    $this->warn($relative, \sprintf('Canonical points outside this site: %s (explicit override).', $canonical));
                } else {
                    $this->error($relative, \sprintf('Canonical mismatch: page is served at %s but declares %s.', $expected, $canonical));
                }
            }
        }

        foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
            json_decode($script->textContent, true);
            if (json_last_error() !== \JSON_ERROR_NONE) {
                $this->error($relative, 'Invalid JSON-LD: ' . json_last_error_msg());
            }
        }

        $hrefs = [];
        foreach ($xpath->query('//a/@href') as $attribute) {
            $hrefs[$attribute->value] = true;
        }
        foreach (array_keys($hrefs) as $href) {
            if (!str_starts_with($href, '/')) {
                continue; // external, mailto, anchor
            }
            if (str_ends_with($href, '.html') && $href !== '/404.html') {
                $this->error($relative, \sprintf('Link points at a .html file instead of a pretty URL: %s', $href));
            }
            $path = strtok($href, '#?');
            if ($path === false || preg_match('/\.(css|js|svg|png|jpe?g|webp|avif|ico|xml|txt|json|webmanifest)$/i', $path)) {
                continue; // assets are checked separately
            }
            if (!$this->resolves($distDir, $path)) {
                $this->error($relative, \sprintf('Broken internal link: %s', $href));
            }
        }
    }

    private function checkAssets(string $distDir, array $htmlFiles): void
    {
        foreach ($htmlFiles as $file) {
            $relative = str_replace('\\', '/', substr($file, \strlen($distDir) + 1));
            $xpath = self::xpath((string) file_get_contents($file));

            $assets = [];
            foreach ($xpath->query('//link[@rel="stylesheet"]/@href | //script/@src | //img/@src | //link[@rel="icon"]/@href') as $attribute) {
                $assets[$attribute->value] = true;
            }
            foreach (array_keys($assets) as $asset) {
                if (!str_starts_with($asset, '/')) {
                    continue; // absolute or remote URL
                }
                $path = $distDir . \DIRECTORY_SEPARATOR . str_replace('/', \DIRECTORY_SEPARATOR, ltrim((string) strtok($asset, '#?'), '/'));
                if (!is_file($path)) {
                    $this->error($relative, \sprintf('Missing static asset: %s', $asset));
                }
            }

            foreach ($xpath->query('//img') as $img) {
                /** @var \DOMElement $img */
                if (!$img->hasAttribute('alt')) {
                    $this->error($relative, \sprintf('An <img> has no alt attribute (src: %s).', $img->getAttribute('src')));
                }
                if (!$img->hasAttribute('width') || !$img->hasAttribute('height')) {
                    $this->warn($relative, \sprintf('An <img> has no width/height attributes (src: %s).', $img->getAttribute('src')));
                }
            }
        }
    }

    private static function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // The prefix forces libxml to read the document as UTF-8 regardless of
        // how the minifier wrote the charset meta tag.
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html, \LIBXML_NOERROR | \LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    private static function text(\DOMXPath $xpath, string $query): string
    {
        $nodes = $xpath->query($query);

        return $nodes !== false && $nodes->length > 0 ? (string) $nodes->item(0)?->textContent : '';
    }

    private static function attr(\DOMXPath $xpath, string $query): string
    {
        $nodes = $xpath->query($query);

        return $nodes !== false && $nodes->length > 0 ? (string) $nodes->item(0)?->nodeValue : '';
    }

    private function checkSitemap(string $geoCode, string $distDir, string $baseUrl): void
    {
        $file = $distDir . \DIRECTORY_SEPARATOR . 'sitemap.xml';
        if (!is_file($file)) {
            $this->error($geoCode, 'sitemap.xml was not generated.');

            return;
        }

        $xml = @simplexml_load_file($file);
        if ($xml === false) {
            $this->error('sitemap.xml', 'Sitemap is not valid XML.');

            return;
        }

        $locs = [];
        foreach ($xml->url as $url) {
            $locs[] = (string) $url->loc;
        }

        if ($locs === []) {
            $this->error('sitemap.xml', 'Sitemap contains no URLs.');
        }

        foreach ($locs as $loc) {
            if (!str_starts_with($loc, $baseUrl)) {
                $this->error('sitemap.xml', \sprintf('URL does not belong to this GEO: %s', $loc));
                continue;
            }
            $path = substr($loc, \strlen($baseUrl));
            if (!$this->resolves($distDir, '/' . $path)) {
                $this->error('sitemap.xml', \sprintf('Sitemap lists a URL with no generated file: %s', $loc));
            }
        }

        if (\count($locs) !== \count(array_unique($locs))) {
            $this->error('sitemap.xml', 'Sitemap contains duplicate URLs.');
        }

        // every indexable published page must be listed
        $expected = [];
        $pages = ContentScanner::scan($geoCode);
        foreach ($pages as $page) {
            $fm = $page['front_matter'];
            if (($fm['status'] ?? 'published') !== 'published') {
                continue;
            }
            if (($fm['indexing']['index'] ?? true) === false || isset($fm['canonical']['url'])) {
                continue;
            }
            $expected[] = $page['url'];
        }
        foreach (array_diff($expected, $locs) as $missing) {
            $this->error('sitemap.xml', \sprintf('Published, indexable page missing from sitemap: %s', $missing));
        }

        // nothing unpublished may leak into the sitemap or the output
        foreach ($pages as $page) {
            $fm = $page['front_matter'];
            $status = $fm['status'] ?? 'published';
            if ($status === 'published' && ($fm['indexing']['index'] ?? true) !== false) {
                continue;
            }
            if (\in_array($page['url'], $locs, true)) {
                $this->error('sitemap.xml', \sprintf('Non-indexable page present in sitemap: %s (status: %s).', $page['url'], $status));
            }
            if ($status !== 'published' && $this->resolves($distDir, '/' . $page['path'])) {
                $this->error($geoCode, \sprintf('Page with status "%s" was rendered to production output: /%s/', $status, $page['path']));
            }
        }
    }

    private function checkRobots(string $distDir, string $baseUrl, bool $staging): void
    {
        $file = $distDir . \DIRECTORY_SEPARATOR . 'robots.txt';
        if (!is_file($file)) {
            $this->error('robots.txt', 'robots.txt was not generated.');

            return;
        }
        $content = (string) file_get_contents($file);

        if ($staging) {
            if (!str_contains($content, 'Disallow: /')) {
                $this->error('robots.txt', 'Staging GEO: robots.txt must disallow everything.');
            }

            return;
        }

        $expected = 'Sitemap: ' . $baseUrl . 'sitemap.xml';
        if (!str_contains($content, $expected)) {
            $this->error('robots.txt', \sprintf('robots.txt does not reference this GEO sitemap (expected "%s").', $expected));
        }
    }

    /**
     * Belt and braces: a rehearsal host getting indexed would compete with the
     * real domain later, so every page must carry noindex.
     */
    private function checkStagingIsNotIndexable(string $distDir, array $htmlFiles): void
    {
        foreach ($htmlFiles as $file) {
            $relative = str_replace('\\', '/', substr($file, \strlen($distDir) + 1));
            $robots = self::attr(self::xpath((string) file_get_contents($file)), '//meta[@name="robots"]/@content');
            if (!str_contains($robots, 'noindex')) {
                $this->error($relative, \sprintf('Staging GEO: page is indexable (robots: "%s").', $robots));
            }
        }
    }

    private function resolves(string $distDir, string $href): bool
    {
        $path = trim($href, '/');
        $base = $distDir . \DIRECTORY_SEPARATOR . str_replace('/', \DIRECTORY_SEPARATOR, $path);

        if ($path === '') {
            return is_file($distDir . \DIRECTORY_SEPARATOR . 'index.html');
        }

        return is_file($base) || is_file($base . \DIRECTORY_SEPARATOR . 'index.html');
    }

    /** @return string[] */
    private function files(string $dir, string $extension): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && strtolower($file->getExtension()) === $extension) {
                $out[] = $file->getPathname();
            }
        }
        sort($out);

        return $out;
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
        return ['errors' => $this->errors, 'warnings' => $this->warnings];
    }
}
