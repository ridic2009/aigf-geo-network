<?php
declare(strict_types=1);
namespace AiGf\Tools;

use Symfony\Component\Yaml\Yaml;

/**
 * The product and affiliate database, edited as one thing.
 *
 * On disk these are two files per product — data/products/<id>.yml holds what
 * the reader sees, data/affiliates/<id>.yml holds where the money link goes —
 * because templates resolve them through different helpers and the affiliate
 * file can be swapped for a redirect service. For whoever edits them they are
 * one record: a product, and per market its price, availability, wording and
 * partner link.
 *
 * The catalogue is shared by every site in the network, so writing to it is
 * admin-only: a price typo here is a price typo on all 25 domains.
 */
final class StudioCatalog
{
    private const PERIODS = ['month', 'year', 'week', 'once'];
    private const AVAILABILITY = ['available', 'limited', 'unavailable'];

    /** Market code => the sites publishing it. Products are priced per market, not per site. */
    public static function markets(): array
    {
        $markets = [];
        foreach (Network::codes(false) as $site) {
            $config = Network::geo($site);
            $code = (string) ($config['geo']['code'] ?? $config['site_identity']['market'] ?? $site);
            $markets[$code]['code'] = $code;
            $markets[$code]['name'] = $config['geo']['name'] ?? strtoupper($code);
            $markets[$code]['currency'] = $config['geo']['currency']['code'] ?? '';
            $markets[$code]['sites'][] = ['id' => $site, 'title' => $config['title']];
        }
        ksort($markets);
        return $markets;
    }

    public static function list(array $user): array
    {
        $products = Network::products();
        $affiliates = Network::affiliates();
        $usage = self::usage($user);
        $rows = [];
        foreach ($products as $id => $product) {
            $links = 0;
            if (isset($affiliates[$id])) {
                $links = count($affiliates[$id]['geo'] ?? []) + (($affiliates[$id]['default'] ?? '') !== '' ? 1 : 0);
            }
            $rows[] = [
                'id' => $id,
                'name' => $product['name'] ?? $id,
                'rating' => $product['rating'] ?? null,
                'markets' => array_keys($product['geo'] ?? []),
                'links' => $links,
                'used_by' => $usage[$id] ?? [],
            ];
        }
        usort($rows, static fn ($a, $b) => strcmp($a['name'], $b['name']));
        return $rows;
    }

    public static function get(array $user, string $id): array
    {
        Network::assertId($id);
        $products = Network::products();
        if (!isset($products[$id])) { throw new \RuntimeException('Товар не найден.', 404); }
        return [
            'id' => $id,
            'product' => $products[$id],
            'affiliate' => Network::affiliates()[$id] ?? ['default' => '', 'geo' => []],
            'used_by' => self::usage($user)[$id] ?? [],
        ];
    }

    /**
     * Which pages mention a product. Used to warn before a rename and to refuse
     * deleting something a published page still points at.
     *
     * @return array<string, list<array{site:string,page:string,title:string}>>
     */
    public static function usage(?array $user = null): array
    {
        $ids = array_keys(Network::products());
        $found = array_fill_keys($ids, []);
        foreach (Network::codes(false) as $site) {
            if ($user !== null) {
                try { StudioAuth::requireSite($user, $site); } catch (\Throwable) { continue; }
            }
            foreach (ContentScanner::scan($site) as $page) {
                $mentioned = [];
                array_walk_recursive($page['front_matter'], static function ($value) use (&$mentioned, $ids) {
                    if (is_string($value) && in_array($value, $ids, true)) { $mentioned[$value] = true; }
                });
                foreach (array_keys($mentioned) as $productId) {
                    $found[$productId][] = ['site' => $site, 'page' => $page['relative'], 'title' => $page['front_matter']['title'] ?? $page['relative']];
                }
            }
        }
        return $found;
    }

    public static function save(array $user, string $id, array $input, bool $create = false): array
    {
        StudioAuth::requireAdmin($user);
        Network::assertId($id);
        $productFile = Network::path('data', 'products', $id . '.yml');
        $affiliateFile = Network::path('data', 'affiliates', $id . '.yml');
        if ($create && is_file($productFile)) { throw new \RuntimeException('Товар с таким ID уже существует.', 409); }
        if (!$create && !is_file($productFile)) { throw new \RuntimeException('Товар не найден.', 404); }

        $markets = self::markets();
        $product = self::product($id, $input, $markets);
        $affiliate = self::affiliate($id, (array) ($input['affiliate'] ?? []), $markets);

        return StudioStore::transaction(function (&$state) use ($user, $id, $product, $affiliate, $productFile, $affiliateFile, $create) {
            $previousProduct = is_file($productFile) ? file_get_contents($productFile) : null;
            $previousAffiliate = is_file($affiliateFile) ? file_get_contents($affiliateFile) : null;
            foreach ([dirname($productFile), dirname($affiliateFile)] as $dir) {
                if (!is_dir($dir)) { mkdir($dir, 0755, true); }
            }
            file_put_contents($productFile, Yaml::dump($product, 10, 2));
            // No destination at all: drop the file rather than leave an empty
            // one, so the helper falls back to the official website.
            if ($affiliate === null) {
                if (is_file($affiliateFile)) { unlink($affiliateFile); }
            } else {
                file_put_contents($affiliateFile, Yaml::dump($affiliate, 10, 2));
            }
            Network::reset();
            try {
                Network::products();
                Network::affiliates();
            } catch (\Throwable $e) {
                if ($previousProduct === null) { @unlink($productFile); } else { file_put_contents($productFile, $previousProduct); }
                if ($previousAffiliate === null) { @unlink($affiliateFile); } else { file_put_contents($affiliateFile, $previousAffiliate); }
                Network::reset();
                throw $e;
            }
            StudioHistory::recordMany([$productFile, $affiliateFile], $user['login'],
                $create ? 'product.created' : 'product.updated', 'Товар ' . $id);
            StudioStore::audit($state, $user['login'], $create ? 'product.created' : 'product.updated', ['product' => $id]);
            return self::get($user, $id);
        });
    }

    public static function delete(array $user, string $id): void
    {
        StudioAuth::requireAdmin($user);
        Network::assertId($id);
        $used = self::usage()[$id] ?? [];
        if ($used) {
            throw new \RuntimeException(sprintf(
                'Товар используется на %d стр.: %s. Уберите ссылки на него, потом удаляйте.',
                count($used),
                implode(', ', array_map(static fn ($u) => $u['site'] . '/' . $u['page'], array_slice($used, 0, 3)))
            ), 409);
        }
        StudioStore::transaction(function (&$state) use ($user, $id) {
            foreach ([Network::path('data', 'products', $id . '.yml'), Network::path('data', 'affiliates', $id . '.yml')] as $file) {
                if (is_file($file)) { unlink($file); }
            }
            Network::reset();
            StudioHistory::recordMany([Network::path('data', 'products', $id . '.yml'), Network::path('data', 'affiliates', $id . '.yml')],
                $user['login'], 'product.deleted', 'Товар ' . $id . ' удалён');
            StudioStore::audit($state, $user['login'], 'product.deleted', ['product' => $id]);
        });
    }

    /** Builds the product record, rejecting anything the templates cannot render. */
    private static function product(string $id, array $input, array $markets): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 200) { throw new \InvalidArgumentException('Укажите название товара (до 200 символов).'); }

        $product = [
            'name' => $name,
            'slug' => $id,
            'website' => self::url($input['website'] ?? '', 'Сайт товара'),
            'logo' => self::path($input['logo'] ?? ''),
            'category' => self::token((string) ($input['category'] ?? 'SoftwareApplication'), 'Категория'),
            'platforms' => self::line($input['platforms'] ?? '', 200, 'Платформы'),
            'rating' => self::rating($input['rating'] ?? null),
            'free_tier' => (bool) ($input['free_tier'] ?? false),
            'launched' => self::year($input['launched'] ?? null),
            'features' => self::features($input['features'] ?? []),
            'price' => self::price($input['price'] ?? [], 'Цена'),
        ];

        $geo = [];
        foreach ((array) ($input['geo'] ?? []) as $code => $values) {
            $code = (string) $code;
            if (!isset($markets[$code])) { throw new \InvalidArgumentException('Неизвестный рынок: ' . $code . '.'); }
            if (!is_array($values)) { throw new \InvalidArgumentException('Некорректные данные рынка ' . $code . '.'); }
            $availability = (string) ($values['availability'] ?? 'available');
            if (!in_array($availability, self::AVAILABILITY, true)) { throw new \InvalidArgumentException('Доступность: available, limited или unavailable.'); }
            $entry = [
                'availability' => $availability,
                'tagline' => self::line($values['tagline'] ?? '', 500, 'Короткое описание'),
                'best_for' => self::line($values['best_for'] ?? '', 200, 'Чем хорош'),
            ];
            $price = self::price($values['price'] ?? [], 'Цена для ' . strtoupper($code));
            if ($price !== null) { $entry['price'] = $price; }
            $geo[$code] = $entry;
        }
        ksort($geo);
        $product['geo'] = $geo;

        // Empty keys would still be dumped into the YAML; leave them out.
        return array_filter($product, static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    /** @return array{product:string,default:string,geo:array}|null null when there is nothing to store. */
    private static function affiliate(string $id, array $input, array $markets): ?array
    {
        $default = self::url($input['default'] ?? '', 'Партнёрская ссылка');
        $geo = [];
        foreach ((array) ($input['geo'] ?? []) as $code => $values) {
            $code = (string) $code;
            if (!isset($markets[$code])) { throw new \InvalidArgumentException('Неизвестный рынок: ' . $code . '.'); }
            $url = self::url(is_array($values) ? ($values['url'] ?? '') : $values, 'Партнёрская ссылка ' . strtoupper($code));
            if ($url !== '') { $geo[$code] = ['url' => $url]; }
        }
        ksort($geo);
        if ($default === '' && !$geo) { return null; }
        return ['product' => $id, 'default' => $default, 'geo' => $geo];
    }

    private static function url(mixed $value, string $label): string
    {
        $value = trim((string) $value);
        if ($value === '') { return ''; }
        if (mb_strlen($value) > 2000) { throw new \InvalidArgumentException($label . ': ссылка слишком длинная.'); }
        $scheme = parse_url($value, PHP_URL_SCHEME);
        if (!filter_var($value, FILTER_VALIDATE_URL) || !in_array(strtolower((string) $scheme), ['http', 'https'], true)) {
            throw new \InvalidArgumentException($label . ': нужен полный адрес, начиная с https://.');
        }
        return $value;
    }

    private static function path(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') { return ''; }
        if (!preg_match('~^/[\w./-]+$~D', $value) || str_contains($value, '..')) {
            throw new \InvalidArgumentException('Логотип: путь внутри сайта, например /images/products/name.svg.');
        }
        return $value;
    }

    private static function token(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') { return ''; }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]{1,60}$/D', $value)) { throw new \InvalidArgumentException($label . ': латинские буквы без пробелов, например SoftwareApplication.'); }
        return $value;
    }

    private static function line(mixed $value, int $max, string $label): string
    {
        $value = trim((string) $value);
        if (mb_strlen($value) > $max) { throw new \InvalidArgumentException($label . ': не длиннее ' . $max . ' символов.'); }
        return $value;
    }

    private static function rating(mixed $value): ?float
    {
        if ($value === null || $value === '') { return null; }
        if (!is_numeric($value)) { throw new \InvalidArgumentException('Рейтинг: число от 0 до 5.'); }
        $rating = round((float) $value, 1);
        if ($rating < 0 || $rating > 5) { throw new \InvalidArgumentException('Рейтинг: число от 0 до 5.'); }
        return $rating;
    }

    private static function year(mixed $value): ?int
    {
        if ($value === null || $value === '') { return null; }
        if (!is_numeric($value) || (int) $value < 1990 || (int) $value > (int) gmdate('Y') + 1) {
            throw new \InvalidArgumentException('Год запуска: от 1990 до ' . ((int) gmdate('Y') + 1) . '.');
        }
        return (int) $value;
    }

    /** @return list<string> */
    private static function features(mixed $value): array
    {
        if (!is_array($value)) { throw new \InvalidArgumentException('Возможности: список строк.'); }
        if (count($value) > 50) { throw new \InvalidArgumentException('Не больше 50 возможностей.'); }
        $out = [];
        foreach ($value as $item) {
            $item = self::line($item, 200, 'Возможность');
            if ($item !== '') { $out[] = $item; }
        }
        return $out;
    }

    /** @return array{amount:float,period:string}|null */
    private static function price(mixed $value, string $label): ?array
    {
        if (!is_array($value)) { return null; }
        $amount = $value['amount'] ?? null;
        if ($amount === null || $amount === '') { return null; }
        if (!is_numeric($amount) || (float) $amount < 0 || (float) $amount > 1000000) { throw new \InvalidArgumentException($label . ': число от 0.'); }
        $period = (string) ($value['period'] ?? 'month');
        if (!in_array($period, self::PERIODS, true)) { throw new \InvalidArgumentException($label . ': период month, year, week или once.'); }
        return ['amount' => round((float) $amount, 2), 'period' => $period];
    }
}
