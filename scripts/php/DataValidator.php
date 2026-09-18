<?php

declare(strict_types=1);

namespace AiGf\Tools;

/**
 * Validates the shared business databases (products, affiliates, authors).
 *
 * The id of a record is its file name; every other reference in the project uses
 * that id, so the checks here are mostly about keeping ids and cross-references
 * honest.
 */
final class DataValidator
{
    private array $errors = [];
    private array $warnings = [];

    public function validate(): array
    {
        $this->errors = [];
        $this->warnings = [];

        $geoCodes = array_unique(array_merge(Network::codes(false), array_map(static fn ($id) => Network::geo($id)['site_identity']['market'], Network::codes(false))));
        $products = Network::products();
        $affiliates = Network::affiliates();
        $authors = Network::authors();

        // Models without product fields (e.g. services) may use an empty catalogue.

        foreach ($products as $id => $product) {
            $where = 'data/products/' . $id . '.yml';

            if (($product['slug'] ?? null) !== $id) {
                $this->error($where, \sprintf('slug must match the file name ("%s"), found "%s".', $id, $product['slug'] ?? 'null'));
            }
            if (trim((string) ($product['name'] ?? '')) === '') {
                $this->error($where, 'Missing name.');
            }
            if (isset($product['rating']) && (!is_numeric($product['rating']) || $product['rating'] < 0 || $product['rating'] > 5)) {
                $this->error($where, 'rating must be a number between 0 and 5.');
            }
            // A missing logo or screenshot is a broken image on a money page,
            // and the hero falls back to its branded panel only when the field
            // is absent — not when it points at a file that is not there.
            foreach (['logo', 'screenshot'] as $asset) {
                if (isset($product[$asset]) && !is_file(Network::path('static', ...explode('/', ltrim((string) $product[$asset], '/'))))) {
                    $this->error($where, \sprintf('%s file not found in static/: %s', $asset, $product[$asset]));
                }
            }
            foreach (array_keys((array) ($product['geo'] ?? [])) as $code) {
                if (!\in_array((string) $code, $geoCodes, true)) {
                    $this->error($where, \sprintf('Unknown GEO override "%s".', $code));
                }
            }
            if (!isset($affiliates[$id])) {
                $this->warn($where, 'No affiliate file: CTAs will link to the official website instead.');
            }
        }

        foreach ($affiliates as $id => $affiliate) {
            $where = 'data/affiliates/' . $id . '.yml';

            if (($affiliate['product'] ?? null) !== $id) {
                $this->error($where, \sprintf('product must match the file name ("%s"), found "%s".', $id, $affiliate['product'] ?? 'null'));
            }
            if (!isset($products[$id])) {
                $this->error($where, \sprintf('No product "%s" — an affiliate file must match a product.', $id));
            }

            $urls = [];
            if (isset($affiliate['default'])) {
                $urls['default'] = (string) $affiliate['default'];
            }
            foreach ((array) ($affiliate['geo'] ?? []) as $code => $entry) {
                if (!\in_array((string) $code, $geoCodes, true)) {
                    $this->error($where, \sprintf('Unknown GEO "%s".', $code));
                    continue;
                }
                if (!empty($entry['url'])) {
                    $urls[(string) $code] = (string) $entry['url'];
                }
            }
            if ($urls === []) {
                $this->error($where, 'No affiliate URL at all (neither `default` nor any GEO).');
            }
            foreach ($urls as $code => $url) {
                if (!preg_match('#^https://#i', $url)) {
                    $this->error($where, \sprintf('Affiliate URL for "%s" must be absolute and https.', $code));
                }
            }
            foreach (Network::codes(true) as $code) {
                if (!isset($urls[$code]) && !isset($urls['default'])) {
                    $this->warn($where, \sprintf('No link for enabled GEO "%s".', $code));
                }
            }
        }

        foreach ($authors as $id => $author) {
            $where = 'data/authors/' . $id . '.yml';

            if (($author['id'] ?? null) !== $id) {
                $this->error($where, \sprintf('id must match the file name ("%s"), found "%s".', $id, $author['id'] ?? 'null'));
            }
            if (trim((string) ($author['name'] ?? '')) === '') {
                $this->error($where, 'Missing name.');
            }
            if (isset($author['role']) && !\is_array($author['role'])) {
                $this->error($where, 'role must be a map of language code => role name.');
            }
        }

        return ['errors' => $this->errors, 'warnings' => $this->warnings];
    }

    private function error(string $where, string $message): void
    {
        $this->errors[] = ['where' => $where, 'message' => $message];
    }

    private function warn(string $where, string $message): void
    {
        $this->warnings[] = ['where' => $where, 'message' => $message];
    }
}
