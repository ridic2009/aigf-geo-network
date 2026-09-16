<?php

declare(strict_types=1);

/**
 * scripts/cms-config — regenerates .pages.yml (the Pages CMS schema).
 *
 * The CMS schema is derived from the GEO list and the page-type model, so a new
 * GEO or a new product attribute is a regeneration, not a hand-edit of a
 * 1000-line YAML file. Run it after `scripts/new-geo` and commit the result.
 *
 * --lang=ru translates every label and hint through config/cms-labels.ru.yml.
 * Pages CMS has no interface localization of its own, but the schema is where
 * almost all of the text an editor reads comes from, so this covers most of it.
 * Strings without a translation stay English and are reported.
 */

require __DIR__ . '/../vendor/autoload.php';

use AiGf\Tools\Cli;
use AiGf\Tools\Network;
use Symfony\Component\Yaml\Yaml;

[$args, $options] = Cli::parse($argv);
$codes = $args === [] ? Network::codes(false) : Cli::resolveGeos($args);
$lang = strtolower((string) ($options['lang'] ?? 'en'));

const SLUG_PATTERN = '^[a-z0-9]+(?:-[a-z0-9]+)*$';

/* ---------------------------------------------------------------- helpers */

function statusField(): array
{
    return [
        'type'        => 'select',
        'label'       => 'Status',
        'description' => 'Draft and Review pages are never published to the live site.',
        'default'     => 'draft',
        'required'    => true,
        'options'     => [
            'values' => [
                ['value' => 'draft', 'label' => 'Draft — work in progress'],
                ['value' => 'review', 'label' => 'Review — waiting for SEO approval'],
                ['value' => 'published', 'label' => 'Published — live'],
            ],
        ],
    ];
}

function seoComponent(): array
{
    return [
        'type'        => 'object',
        'label'       => 'SEO',
        'description' => 'Canonical URL, hreflang and language tags are generated automatically — you only fill in the fields below.',
        'fields'      => [
            ['name' => 'title', 'label' => 'SEO title', 'type' => 'string', 'required' => true, 'description' => 'Shown in Google. Aim for 50-60 characters.'],
            ['name' => 'description', 'label' => 'Meta description', 'type' => 'text', 'required' => true, 'description' => 'Shown under the title in Google. Aim for 120-160 characters.'],
            ['name' => 'primary_keyword', 'label' => 'Primary keyword', 'type' => 'string', 'description' => 'The main search term this page targets.'],
            ['name' => 'og_title', 'label' => 'Social title', 'type' => 'string', 'description' => 'Optional. Defaults to the SEO title.'],
            ['name' => 'og_description', 'label' => 'Social description', 'type' => 'text', 'description' => 'Optional. Defaults to the meta description.'],
            ['name' => 'og_image', 'label' => 'Social image', 'type' => 'image', 'options' => ['media' => 'content'], 'description' => 'Optional. Used when the page is shared on social networks.'],
        ],
    ];
}

function indexingComponent(): array
{
    return [
        'type'        => 'object',
        'label'       => 'Search engine indexing',
        'description' => 'Leave both switched on unless you have a reason not to.',
        'fields'      => [
            ['name' => 'index', 'label' => 'Allow indexing', 'type' => 'boolean', 'default' => true],
            ['name' => 'follow', 'label' => 'Follow links', 'type' => 'boolean', 'default' => true],
        ],
    ];
}

function faqComponent(): array
{
    return [
        'type'        => 'object',
        'label'       => 'FAQ',
        'description' => 'Questions and answers. Shown on the page and used for FAQ structured data — you never write code for this.',
        'list'        => [
            'collapsible' => ['collapsed' => true, 'summary' => '{fields.question}'],
        ],
        'fields' => [
            ['name' => 'question', 'label' => 'Question', 'type' => 'string', 'required' => true],
            ['name' => 'answer', 'label' => 'Answer', 'type' => 'text', 'required' => true],
        ],
    ];
}

function canonicalComponent(): array
{
    return [
        'type'        => 'object',
        'label'       => 'Advanced: canonical override',
        'description' => 'Leave empty. The canonical URL is generated from the domain and the page URL. Only fill this in when this page must point at a different URL.',
        'fields'      => [
            ['name' => 'url', 'label' => 'Canonical URL', 'type' => 'string'],
        ],
    ];
}

function productReference(string $name, string $label, bool $multiple = false, ?string $description = null): array
{
    $field = [
        'name'    => $name,
        'label'   => $label,
        'type'    => 'reference',
        'options' => [
            'collection' => 'products',
            'value'      => '{fields.slug}',
            'label'      => '{fields.name}',
            'search'     => 'fields.name,fields.slug',
        ],
    ];
    if ($multiple) {
        $field['options']['multiple'] = true;
    }
    if ($description !== null) {
        $field['description'] = $description;
    }

    return $field;
}

function authorReference(string $name, string $label, string $description): array
{
    return [
        'name'        => $name,
        'label'       => $label,
        'description' => $description,
        'type'        => 'reference',
        'options'     => [
            'collection' => 'authors',
            'value'      => '{fields.id}',
            'label'      => '{fields.name}',
            'search'     => 'fields.name,fields.id',
        ],
    ];
}

/**
 * Fields shared by every editorial page type.
 */
function baseFields(string $type, bool $withSlug = true): array
{
    $fields = [
        ['name' => 'title', 'label' => 'Title', 'type' => 'string', 'required' => true, 'description' => 'The heading shown at the top of the page.'],
    ];

    if ($withSlug) {
        $fields[] = [
            'name'        => 'slug',
            'label'       => 'URL',
            'type'        => 'string',
            'required'    => true,
            'description' => 'The address of this page in this country, e.g. "candy-ai-erfahrungen". Lowercase letters, numbers and hyphens only.',
            'pattern'     => ['regex' => SLUG_PATTERN, 'message' => 'Use lowercase letters, numbers and single hyphens.'],
        ];
    }

    $fields[] = ['name' => 'status', 'component' => 'status'];
    $fields[] = ['name' => 'type', 'type' => 'string', 'default' => $type, 'hidden' => true];
    $fields[] = [
        'name'        => 'translation_key',
        'label'       => 'Page key',
        'type'        => 'string',
        'required'    => true,
        'description' => 'The same key in every country — this is what links this page to its translations (hreflang). It also becomes the file name.',
        'pattern'     => ['regex' => SLUG_PATTERN, 'message' => 'Use lowercase letters, numbers and single hyphens.'],
    ];

    return $fields;
}

function metaFields(): array
{
    return [
        ['name' => 'date', 'label' => 'First published', 'type' => 'date'],
        ['name' => 'updated', 'label' => 'Last updated', 'type' => 'date', 'description' => 'Shown on the page and used as the sitemap date.'],
        authorReference('author', 'Author', 'Who wrote this page.'),
        authorReference('reviewer', 'Reviewed by', 'Optional second pair of eyes.'),
    ];
}

function contentFields(): array
{
    return [
        ['name' => 'intro', 'label' => 'Introduction', 'type' => 'text', 'description' => 'One or two sentences under the title.'],
        [
            'name'        => 'image',
            'label'       => 'Featured image',
            'type'        => 'image',
            'options'     => ['media' => 'content'],
            'description' => 'Shown under the introduction and used as the social preview. Upload the largest version you have — the engine produces every size it needs.',
        ],
        ['name' => 'image_alt', 'label' => 'Featured image: alt text', 'type' => 'string', 'description' => 'What the image shows, for screen readers and search engines. Defaults to the title.'],
        ['name' => 'image_caption', 'label' => 'Featured image: caption', 'type' => 'string', 'description' => 'Optional, printed under the image.'],
        [
            'name'        => 'body',
            'label'       => 'Main content',
            'type'        => 'rich-text',
            'options'     => ['format' => 'markdown', 'media' => 'content'],
            'description' => 'Use the image button in the toolbar to place pictures inside the text. They are made responsive automatically.',
        ],
    ];
}

function tailFields(): array
{
    return [
        ['name' => 'faq', 'component' => 'faq'],
        ['name' => 'related', 'label' => 'Related pages', 'type' => 'string', 'list' => true, 'description' => 'Optional. Page ids, e.g. "reviews/candy-ai".'],
        ['name' => 'seo', 'component' => 'seo'],
        ['name' => 'indexing', 'component' => 'indexing'],
        ['name' => 'canonical', 'component' => 'canonical'],
    ];
}

/**
 * Union of the interface-label keys used by the GEO configs, so the CMS form
 * declares every key that exists. Keys the form does not declare survive a save
 * thanks to settings.content.merge, but declared keys are what editors can see.
 *
 * @return array{nav: string[], sections: string[], labels: string[]}
 */
function uiKeys(array $codes): array
{
    $keys = ['nav' => [], 'sections' => [], 'labels' => []];
    foreach ($codes as $code) {
        $ui = (array) (Network::geo($code)['ui'] ?? []);
        foreach (array_keys($keys) as $group) {
            foreach (array_keys((array) ($ui[$group] ?? [])) as $key) {
                $keys[$group][(string) $key] = true;
            }
        }
    }

    return array_map(static fn ($k) => array_keys($k), $keys);
}

function labelFields(array $keys, array $descriptions = []): array
{
    $fields = [];
    foreach ($keys as $key) {
        $field = [
            'name'  => $key,
            'label' => ucfirst(str_replace('_', ' ', $key)),
            'type'  => 'string',
        ];
        if (isset($descriptions[$key])) {
            $field['description'] = $descriptions[$key];
        }
        $fields[] = $field;
    }

    return $fields;
}

/**
 * "Site settings" — the GEO configuration file, edited through a form.
 *
 * Technical keys are marked readonly rather than hidden so an editor can see
 * them and quote them to a developer, but cannot break a build with them.
 * Keys that are not declared here (pages.dir, languages, currency format,
 * Cecil options…) are never touched: Pages CMS merges the form into the file.
 */
function siteSettingsFile(string $code, array $ui): array
{
    return [
        'name'        => $code . '_settings',
        'label'       => 'Site settings',
        'description' => 'Name, description and every piece of interface text for this country.',
        'type'        => 'file',
        'path'        => str_replace('\\', '/', substr(Network::configFile($code), strlen(Network::root()) + 1)),
        'format'      => 'yaml',
        'fields'      => [
            ['name' => 'title', 'label' => 'Site name', 'type' => 'string', 'required' => true, 'description' => 'Used in the header, in <title> and in Organization/WebSite structured data.'],
            ['name' => 'baseline', 'label' => 'Tagline', 'type' => 'string'],
            ['name' => 'description', 'label' => 'Default meta description', 'type' => 'text', 'required' => true, 'description' => 'Used on pages that do not define their own.'],
            [
                'name'   => 'organization',
                'label'  => 'Publisher',
                'type'   => 'object',
                'fields' => [
                    ['name' => 'name', 'label' => 'Publisher name', 'type' => 'string'],
                    ['name' => 'legal_name', 'label' => 'Legal name', 'type' => 'string'],
                    ['name' => 'email', 'label' => 'Contact email', 'type' => 'string'],
                ],
            ],
            [
                'name'        => 'ui',
                'label'       => 'Interface text',
                'description' => 'Everything the engine writes on the page by itself: menu, buttons, table headings.',
                'type'        => 'object',
                'fields'      => [
                    ['name' => 'nav', 'label' => 'Menu labels', 'type' => 'object', 'fields' => labelFields($ui['nav'])],
                    ['name' => 'sections', 'label' => 'Section names', 'type' => 'object', 'fields' => labelFields($ui['sections'])],
                    ['name' => 'labels', 'label' => 'Interface labels', 'type' => 'object', 'fields' => labelFields($ui['labels'], [
                        'try_now'          => 'The %s is replaced by the product name, e.g. "Try %s".',
                        'affiliate_notice' => 'Shown near every monetised button and in the footer.',
                        'out_of'           => 'Used as "4.6 out of 5".',
                    ])],
                ],
            ],
            [
                'name'        => 'baseurl',
                'label'       => 'Domain',
                'type'        => 'string',
                'readonly'    => true,
                'description' => 'Read-only. Changing a domain means DNS, a certificate and a new Nginx vhost — ask a developer.',
            ],
            [
                'name'        => 'routes',
                'label'       => 'URL structure',
                'type'        => 'object',
                'readonly'    => true,
                'description' => 'Read-only. These prefixes are part of every URL in the section; changing one moves every page in it and needs redirects — ask a developer.',
                'fields'      => [
                    ['name' => 'reviews', 'label' => 'Reviews prefix', 'type' => 'string', 'readonly' => true],
                    ['name' => 'compare', 'label' => 'Comparisons prefix', 'type' => 'string', 'readonly' => true],
                    ['name' => 'guides', 'label' => 'Guides prefix', 'type' => 'string', 'readonly' => true],
                    ['name' => 'rankings', 'label' => 'Rankings prefix', 'type' => 'string', 'readonly' => true],
                ],
            ],
            [
                'name'     => 'geo',
                'label'    => 'Market',
                'type'     => 'object',
                'readonly' => true,
                'fields'   => [
                    ['name' => 'name', 'label' => 'Country name', 'type' => 'string', 'readonly' => true],
                    ['name' => 'hreflang', 'label' => 'Language/region code', 'type' => 'string', 'readonly' => true],
                    ['name' => 'enabled', 'label' => 'Live', 'type' => 'boolean', 'readonly' => true, 'description' => 'Read-only. A country goes live once DNS, the certificate and the content are ready.'],
                ],
            ],
        ],
    ];
}

/**
 * "Network settings" — the parts of config/common.yml that are editorial
 * decisions rather than engine plumbing.
 */
function networkSettingsFile(): array
{
    $navItem = static fn (string $label, string $hint): array => [
        'name'        => $label,
        'label'       => ucfirst($label) . ' menu',
        'description' => $hint,
        'type'        => 'object',
        'list'        => ['collapsible' => ['collapsed' => false, 'summary' => '{fields.label} ({fields.id})']],
        'fields'      => [
            ['name' => 'id', 'label' => 'Page id', 'type' => 'string', 'required' => true, 'description' => 'e.g. "reviews", "about", "rankings/best-ai-girlfriend". Countries that do not have the page simply skip the item.'],
            ['name' => 'label', 'label' => 'Label key', 'type' => 'string', 'required' => true, 'description' => 'Which entry of "Menu labels" to show. Add the same key to every country under Site settings.'],
            ['name' => 'weight', 'label' => 'Order', 'type' => 'number', 'description' => 'Lower numbers come first.'],
        ],
    ];

    return [
        'name'        => 'network_settings',
        'label'       => 'Navigation & SEO',
        'description' => 'Menu structure and structured-data switches for every country at once.',
        'type'        => 'file',
        'path'        => 'config/common.yml',
        'format'      => 'yaml',
        'fields'      => [
            [
                'name'        => 'navigation',
                'label'       => 'Menus',
                'description' => 'One structure for the whole network; each country supplies its own labels.',
                'type'        => 'object',
                'fields'      => [
                    $navItem('main', 'Header menu.'),
                    $navItem('footer', 'Footer menu.'),
                ],
            ],
            [
                'name'        => 'schema',
                'label'       => 'Structured data',
                'description' => 'Which schema.org blocks the engine emits. Turn something off only if search guidelines change.',
                'type'        => 'object',
                'fields'      => [
                    ['name' => 'organization', 'label' => 'Organization', 'type' => 'boolean'],
                    ['name' => 'website', 'label' => 'WebSite', 'type' => 'boolean'],
                    ['name' => 'webpage', 'label' => 'WebPage', 'type' => 'boolean'],
                    ['name' => 'breadcrumbs', 'label' => 'BreadcrumbList', 'type' => 'boolean'],
                    ['name' => 'article', 'label' => 'Article (rankings, comparisons, guides)', 'type' => 'boolean'],
                    ['name' => 'review', 'label' => 'Review (review pages with a rating)', 'type' => 'boolean'],
                    ['name' => 'faq', 'label' => 'FAQPage', 'type' => 'boolean', 'description' => 'Valid markup either way; Google limits FAQ rich results to a few site categories.'],
                ],
            ],
            [
                'name'   => 'affiliate',
                'label'  => 'Affiliate links',
                'type'   => 'object',
                'fields' => [
                    ['name' => 'rel', 'label' => 'rel attribute', 'type' => 'string', 'description' => 'Applied to every monetised outbound link. Keep "sponsored" in it.'],
                    ['name' => 'target', 'label' => 'Link target', 'type' => 'string'],
                    ['name' => 'redirect_base', 'label' => 'Redirect service', 'type' => 'string', 'readonly' => true, 'description' => 'Read-only. When set, buttons point at go.<domain>/<product>/<country> instead of the network URL.'],
                ],
            ],
        ],
    ];
}

function view(array $fields, string $sort = 'updated'): array
{
    return [
        'fields'  => $fields,
        'primary' => 'title',
        'sort'    => ['updated', 'title', 'status'],
        'search'  => ['title', 'slug', 'seo.title'],
        'default' => ['sort' => $sort, 'order' => 'desc'],
    ];
}

function collection(string $name, string $label, string $path, string $description, array $fields, array $view): array
{
    return [
        'name'        => $name,
        'label'       => $label,
        'description' => $description,
        'type'        => 'collection',
        'path'        => $path,
        'format'      => 'yaml-frontmatter',
        'subfolders'  => false,
        'filename'    => ['template' => '{fields.translation_key}.md', 'field' => false],
        'view'        => $view,
        'fields'      => $fields,
    ];
}

/* ------------------------------------------------------------ per-GEO tree */

function modelCmsFields(array $fields): array
{
    $out = [];
    foreach ($fields as $name => $rule) {
        $type = $rule['type'] ?? 'string';
        if ($type === 'list') {
            $child = modelCmsFields([$name => array_merge($rule['items'] ?? ['type' => 'string'], ['label' => $rule['label'] ?? $name])])[0];
            $child['list'] = true;
            $out[] = $child;
            continue;
        }
        if ($type === 'product') {
            $field = productReference($name, $rule['label'] ?? $name);
        } else {
            $field = ['name' => $name, 'label' => $rule['label'] ?? $name, 'type' => $type === 'url' ? 'string' : $type];
            if ($type === 'object') { $field['fields'] = modelCmsFields($rule['fields'] ?? []); }
        }
        if (!empty($rule['required'])) { $field['required'] = true; }
        if (isset($rule['options'])) { $field['type'] = 'select'; $field['options'] = ['values' => $rule['options']]; }
        $out[] = $field;
    }
    return $out;
}

function geoGroup(string $code, array $ui): array
{
    $geo = Network::geo($code);
    $base = $geo['pages']['dir'];
    $items = [siteSettingsFile($code, $ui)];
    foreach (\AiGf\Tools\Model::types($code) as $type => $definition) {
        $fields = array_merge(baseFields($type, $type !== 'homepage'), metaFields(), contentFields(), modelCmsFields($definition['fields'] ?? []), tailFields());
        $fields[] = ['name' => 'aliases', 'label' => 'Предыдущие адреса', 'type' => 'string', 'list' => true, 'readonly' => true];
        $blocks = [];
        foreach (\AiGf\Tools\Model::blocks() as $block => $def) {
            $blocks[] = ['name' => $block, 'label' => $def['label'], 'fields' => modelCmsFields($def['fields'])];
        }
        $fields[] = ['name' => 'blocks', 'label' => 'Блоки страницы', 'type' => 'block', 'list' => true, 'blockKey' => 'type', 'blocks' => $blocks];
        if ($type === 'homepage') {
            $items[] = ['name' => $code . '_home', 'label' => $definition['label'], 'type' => 'file', 'path' => $base . '/index.md', 'format' => 'yaml-frontmatter', 'fields' => $fields];
            continue;
        }
        $path = $base . (empty($definition['section']) ? '' : '/' . $definition['section']);
        $item = collection($code . '_' . $type, $definition['label'], $path, '', $fields, view(['title', 'slug', 'status', 'updated']));
        if (empty($definition['section'])) { $item['exclude'] = ['index.md']; }
        $items[] = $item;
    }
    return ['name' => 'site_' . $code, 'label' => $geo['title'] . ' — ' . $code, 'type' => 'group', 'items' => $items];
}

/* ------------------------------------------------------ business data trees */

function geoOverrideFields(array $codes, array $fields): array
{
    $out = [];
    $seen = [];
    foreach ($codes as $code) {
        $geo = Network::geo($code);
        $code = $geo['site_identity']['market'];
        if (isset($seen[$code])) { continue; }
        $seen[$code] = true;
        $out[] = [
            'name'   => $code,
            'label'  => (string) ($geo['geo']['name'] ?? strtoupper($code)) . ' (' . strtoupper($code) . ')',
            'type'   => 'object',
            'fields' => $fields,
        ];
    }

    return $out;
}

function productsCollection(array $codes): array
{
    $priceFields = [
        ['name' => 'amount', 'label' => 'Price per month', 'type' => 'number'],
        ['name' => 'period', 'label' => 'Billing period', 'type' => 'string', 'default' => 'month', 'hidden' => true],
    ];

    $geoFields = [
        ['name' => 'tagline', 'label' => 'One-line summary', 'type' => 'text'],
        ['name' => 'best_for', 'label' => 'Best for', 'type' => 'string'],
        [
            'name'    => 'availability',
            'label'   => 'Availability',
            'type'    => 'select',
            'default' => 'available',
            'options' => ['values' => [
                ['value' => 'available', 'label' => 'Available'],
                ['value' => 'limited', 'label' => 'Limited'],
                ['value' => 'unavailable', 'label' => 'Not available'],
            ]],
        ],
        ['name' => 'price', 'label' => 'Price', 'type' => 'object', 'fields' => $priceFields],
        ['name' => 'rating', 'label' => 'Rating override', 'type' => 'number', 'description' => 'Leave empty to use the global rating.', 'options' => ['min' => 0, 'max' => 5, 'step' => 0.1]],
    ];

    return [
        'name'        => 'products',
        'label'       => 'Products',
        'description' => 'One file per product, shared by every country. Articles reference a product — they never copy its data.',
        'type'        => 'collection',
        'path'        => 'data/products',
        'format'      => 'yaml',
        'subfolders'  => false,
        'filename'    => ['template' => '{fields.slug}.yml', 'field' => false],
        'view'        => [
            'fields'  => ['name', 'slug', 'rating'],
            'primary' => 'name',
            'sort'    => ['name', 'rating'],
            'search'  => ['name', 'slug'],
            'default' => ['sort' => 'name', 'order' => 'asc'],
        ],
        'fields' => [
            ['name' => 'name', 'label' => 'Product name', 'type' => 'string', 'required' => true],
            ['name' => 'slug', 'label' => 'Product id', 'type' => 'string', 'required' => true, 'description' => 'Lowercase id used by articles and affiliate links, e.g. "candy-ai". Also becomes the file name.', 'pattern' => ['regex' => SLUG_PATTERN, 'message' => 'Use lowercase letters, numbers and single hyphens.']],
            ['name' => 'website', 'label' => 'Official website', 'type' => 'string'],
            ['name' => 'logo', 'label' => 'Logo', 'type' => 'image', 'options' => ['media' => 'products']],
            ['name' => 'rating', 'label' => 'Editorial rating', 'type' => 'number', 'options' => ['min' => 0, 'max' => 5, 'step' => 0.1], 'description' => 'Out of 5. Used on cards, tables and in Review structured data.'],
            ['name' => 'free_tier', 'label' => 'Has a free tier', 'type' => 'boolean', 'default' => false],
            ['name' => 'launched', 'label' => 'Launched', 'type' => 'number'],
            ['name' => 'category', 'label' => 'Schema category', 'type' => 'string', 'default' => 'LifestyleApplication', 'hidden' => true],
            ['name' => 'platforms', 'label' => 'Platforms', 'type' => 'string', 'description' => 'e.g. "Web, iOS, Android".'],
            ['name' => 'features', 'label' => 'Key features', 'type' => 'string', 'list' => true],
            ['name' => 'price', 'label' => 'Default price', 'type' => 'object', 'fields' => $priceFields],
            [
                'name'        => 'geo',
                'label'       => 'Country overrides',
                'description' => 'Only fill in what differs from the defaults above.',
                'type'        => 'object',
                'fields'      => geoOverrideFields($codes, $geoFields),
            ],
        ],
    ];
}

function affiliatesCollection(array $codes): array
{
    return [
        'name'        => 'affiliates',
        'label'       => 'Affiliate links',
        'description' => 'One file per product. Buttons pick the right link automatically from the product and the country.',
        'type'        => 'collection',
        'path'        => 'data/affiliates',
        'format'      => 'yaml',
        'subfolders'  => false,
        'filename'    => ['template' => '{fields.product}.yml', 'field' => false],
        'view'        => [
            'fields'  => ['product', 'default'],
            'primary' => 'product',
            'sort'    => ['product'],
            'search'  => ['product'],
            'default' => ['sort' => 'product', 'order' => 'asc'],
        ],
        'fields' => [
            ['name' => 'product', 'label' => 'Product id', 'type' => 'string', 'required' => true, 'description' => 'Must match the product id, e.g. "candy-ai".', 'pattern' => ['regex' => SLUG_PATTERN, 'message' => 'Use lowercase letters, numbers and single hyphens.']],
            ['name' => 'default', 'label' => 'Fallback link', 'type' => 'string', 'description' => 'Used for any country without its own link.'],
            [
                'name'   => 'geo',
                'label'  => 'Country links',
                'type'   => 'object',
                'fields' => geoOverrideFields($codes, [
                    ['name' => 'url', 'label' => 'Affiliate URL', 'type' => 'string'],
                ]),
            ],
        ],
    ];
}

function authorsCollection(array $codes): array
{
    $languages = [];
    foreach ($codes as $code) {
        $lang = (string) (Network::geo($code)['language'] ?? 'en');
        $languages[$lang] = (string) (Network::geo($code)['languages'][0]['name'] ?? strtoupper($lang));
    }
    ksort($languages);

    $roleFields = [];
    foreach ($languages as $lang => $name) {
        $roleFields[] = ['name' => $lang, 'label' => $name, 'type' => 'string'];
    }

    return [
        'name'        => 'authors',
        'label'       => 'Authors',
        'description' => 'Shared byline registry. Pages reference an author by id.',
        'type'        => 'collection',
        'path'        => 'data/authors',
        'format'      => 'yaml',
        'subfolders'  => false,
        'filename'    => ['template' => '{fields.id}.yml', 'field' => false],
        'view'        => [
            'fields'  => ['name', 'id'],
            'primary' => 'name',
            'sort'    => ['name'],
            'search'  => ['name', 'id'],
            'default' => ['sort' => 'name', 'order' => 'asc'],
        ],
        'fields' => [
            ['name' => 'name', 'label' => 'Display name', 'type' => 'string', 'required' => true],
            ['name' => 'id', 'label' => 'Author id', 'type' => 'string', 'required' => true, 'description' => 'Lowercase id used by pages, e.g. "m-keller".', 'pattern' => ['regex' => SLUG_PATTERN, 'message' => 'Use lowercase letters, numbers and single hyphens.']],
            ['name' => 'avatar', 'label' => 'Photo', 'type' => 'image', 'options' => ['media' => 'content']],
            ['name' => 'role', 'label' => 'Role per language', 'type' => 'object', 'fields' => $roleFields],
        ],
    ];
}

/* ----------------------------------------------------------------- assemble */

$config = [
    'media' => [
        [
            'name'       => 'content',
            'label'      => 'Article images',
            'input'      => 'static/images/content',
            'output'     => '/images/content',
            'categories' => ['image'],
            'extensions' => ['jpg', 'jpeg', 'png', 'webp', 'avif', 'svg'],
            'rename'     => 'safe',
        ],
        [
            'name'       => 'products',
            'label'      => 'Product logos',
            'input'      => 'static/images/products',
            'output'     => '/images/products',
            'categories' => ['image'],
            'extensions' => ['png', 'webp', 'svg'],
            'rename'     => 'safe',
        ],
    ],
    'settings' => [
        // Essential: configuration files are edited through a partial form.
        // With merge enabled Pages CMS deep-merges the form into the existing
        // file, so keys the form does not declare (Cecil options, currency
        // format, content directory…) survive every save.
        'content' => [
            'merge' => true,
        ],
        'commit' => [
            'templates' => [
                'create' => 'content: add {filename}',
                'update' => 'content: update {filename}',
                'delete' => 'content: remove {filename}',
                'rename' => 'content: rename {filename}',
            ],
        ],
    ],
    'components' => [
        'status'    => statusField(),
        'seo'       => seoComponent(),
        'indexing'  => indexingComponent(),
        'faq'       => faqComponent(),
        'canonical' => canonicalComponent(),
    ],
    'content' => [
        [
            'name'        => 'sites',
            'label'       => 'Sites',
            'description' => 'Pick a country, then a page type.',
            'type'        => 'group',
            'items'       => array_map(static fn (string $code) => geoGroup($code, uiKeys($codes)), $codes),
        ],
        productsCollection($codes),
        affiliatesCollection($codes),
        authorsCollection($codes),
        [
            'name'  => 'network',
            'label' => 'Network',
            'type'  => 'group',
            'items' => [networkSettingsFile()],
        ],
    ],
];

$header = <<<TXT
# ---------------------------------------------------------------------------
# Pages CMS schema — GENERATED by scripts/cms-config. Do not edit by hand.
#
# Regenerate after adding a GEO, a page type or a product attribute:
#     ./scripts/cms-config && git commit .pages.yml
#
# Editors never see files, YAML, Git or Cecil: they pick a country, pick a page
# type and fill in fields. URLs, canonicals, hreflang, breadcrumbs, schema.org
# markup and the sitemap are produced by the engine at build time.
# ---------------------------------------------------------------------------

TXT;

/* ------------------------------------------------- optional translation pass */

$missing = [];
if ($lang !== 'en') {
    $dictionaryFile = Network::path('config', 'cms-labels.' . $lang . '.yml');
    if (!is_file($dictionaryFile)) {
        Cli::error(\sprintf('No dictionary for "%s": expected config/cms-labels.%s.yml', $lang, $lang));
        exit(1);
    }
    $dictionary = (array) (Yaml::parseFile($dictionaryFile) ?? []);

    // Only user-facing keys are translated; names, paths and field types are
    // structural and must stay exactly as generated.
    $translate = static function (array $node, string $parent = "") use (&$translate, $dictionary, &$missing): array {
        foreach ($node as $key => $value) {
            if (\is_array($value)) {
                $node[$key] = $translate($value, (string) $key);
                continue;
            }
            // `options.label` and `options.value` are templates ({fields.name}),
            // not text: translating them would break reference fields.
            if ($parent === "options") {
                continue;
            }
            if (!\in_array($key, ['label', 'description', 'message'], true) || !\is_string($value)) {
                continue;
            }
            if (isset($dictionary[$value])) {
                $node[$key] = $dictionary[$value];
            } elseif (trim($value) !== '' && !preg_match('/^[a-z0-9_-]+$/', $value) && !preg_match('/[А-Яа-яЁё]/u', $value)) {
                $missing[$value] = true;
            }
        }

        return $node;
    };
    $config = $translate($config);
}

$file = Network::path('.pages.yml');
file_put_contents($file, $header . Yaml::dump($config, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));

Cli::ok(\sprintf('.pages.yml generated for %d GEO(s): %s%s', \count($codes), implode(', ', array_map('strtoupper', $codes)), $lang === 'en' ? '' : ' [' . $lang . ']'));

if ($missing !== []) {
    Cli::warn(\sprintf('%d string(s) have no %s translation and stay English:', \count($missing), $lang));
    foreach (\array_slice(array_keys($missing), 0, 10) as $string) {
        Cli::info('  ' . $string);
    }
    Cli::info(\sprintf('Add them to config/cms-labels.%s.yml', $lang));
}
