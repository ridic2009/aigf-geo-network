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
            // The comparison table filters on these; "yes" typed as text would
            // pass every filter and quietly tell a reader something untrue.
            foreach (['free_tier', 'memory', 'voice', 'nsfw'] as $flag) {
                if (isset($product[$flag]) && !\is_bool($product[$flag])) {
                    $this->error($where, \sprintf('%s must be true or false.', $flag));
                }
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
            $this->checkPrices($where, $product);
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

    /**
     * A price is stored in the currency of the market that shows it. The engine
     * only attaches a symbol — it never converts — so a figure copied from one
     * market into another renders as a real price that nobody charges. A dollar
     * amount left in a yen slot becomes ¥13.
     *
     * Two signatures are worth catching, and only the first is certain enough
     * to stop a build.
     */
    private function checkPrices(string $where, array $product): void
    {
        $byAmount = [];

        foreach (Network::codes(false) as $code) {
            $override = (array) ($product['geo'][$code] ?? []);
            // The template merges one level deep, so a GEO that declares `price`
            // replaces the default object entirely — including dropping `amount`.
            $price = \array_key_exists('price', $override) ? (array) $override['price'] : (array) ($product['price'] ?? []);
            $amount = $price['amount'] ?? null;
            if (!is_numeric($amount)) {
                continue;
            }
            $currency = Network::geo((string) $code)['geo']['currency'] ?? [];
            $name = (string) ($currency['code'] ?? '?');
            $byAmount[(string) $amount][$name] = true;

            // A currency with no subunit cannot carry a fractional price, and no
            // monthly subscription costs thirteen of anything in that currency.
            if ((int) ($currency['decimals'] ?? 2) === 0) {
                if ((float) $amount !== floor((float) $amount)) {
                    $this->error($where, \sprintf(
                        'GEO "%s" bills in %s, which has no subunit, but the price is %s.',
                        $code, $name, $amount
                    ));
                } elseif ((float) $amount < 100) {
                    $this->error($where, \sprintf(
                        'GEO "%s" price of %s %s is not a price anyone charges — it reads like a figure left over from another currency.',
                        $code, $amount, $name
                    ));
                }
            }
        }

        // The same number under two different currencies is what copying a price
        // across markets looks like. It can be legitimate, so it only warns.
        foreach ($byAmount as $amount => $currencies) {
            if (\count($currencies) > 1) {
                $this->warn($where, \sprintf(
                    'Price %s is used for both %s — check it was converted rather than copied.',
                    $amount, implode(' and ', array_keys($currencies))
                ));
            }
        }
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
