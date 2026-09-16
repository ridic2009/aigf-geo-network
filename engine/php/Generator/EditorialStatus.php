<?php

declare(strict_types=1);

namespace AiGf\Engine\Generator;

use Cecil\Collection\Page\Page;
use Cecil\Generator\AbstractGenerator;
use Cecil\Generator\GeneratorInterface;

/**
 * Editorial workflow: maps the CMS `status` field to Cecil's `published` flag.
 *
 *   status: draft      -> never rendered
 *   status: review     -> never rendered (visible with `--drafts` only)
 *   status: published  -> rendered
 *
 * Runs at priority 15, i.e. after front matter has been parsed and before
 * Cecil's Section/Homepage generators, so unpublished pages are also absent
 * from listings, menus, sitemap and internal links.
 */
final class EditorialStatus extends AbstractGenerator implements GeneratorInterface
{
    public const STATUSES = ['draft', 'review', 'published'];
    private const PUBLISHABLE = ['published'];

    public function generate(): void
    {
        $includeUnpublished = (bool) ($this->builder->getBuildOptions()['drafts'] ?? false);

        /** @var Page $page */
        foreach ($this->builder->getPages() ?? [] as $page) {
            if ($page->isVirtual()) {
                continue; // robots.txt, sitemap.xml, 404… have no editorial status
            }

            $status = (string) $page->getVariable('status', 'published');
            // These aliases are handled as HTTP 301s by the release manifest.
            // Cecil's built-in alias generator would emit conflicting HTML stubs.
            $page->unVariable('aliases');
            $types = (array) $this->config->get('page_types');
            $definition = $types[$page->getVariable('type', '')] ?? [];
            if (isset($definition['layout'])) { $page->setVariable('layout', $definition['layout']); }

            if (!\in_array($status, self::STATUSES, true)) {
                $this->builder->getLogger()->error(\sprintf(
                    'Page "%s" has an unknown status "%s" (expected: %s). Treated as draft.',
                    $page->getId(),
                    $status,
                    implode(', ', self::STATUSES)
                ));
                $page->setVariable('published', false);
                continue;
            }

            if (\in_array($status, self::PUBLISHABLE, true)) {
                continue;
            }

            if ($includeUnpublished) {
                $page->setVariable('preview_status', $status);
                continue;
            }

            $page->setVariable('published', false);
        }
    }
}
