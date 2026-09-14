<?php

declare(strict_types=1);

namespace AiGf\Tools;

use Symfony\Component\Yaml\Yaml;

/**
 * Checks .pages.yml against the Pages CMS schema rules (pages-cms/lib/config-schema.ts)
 * and against this repository.
 *
 * Pages CMS validates its config strictly — an unknown key breaks the whole CMS
 * for the editors. Catching that in CI is much cheaper than finding out from a
 * rewriter who cannot open the site.
 */
final class CmsConfigValidator
{
    private const LEAF_KEYS = [
        'name', 'label', 'description', 'type', 'path', 'operations', 'filename',
        'exclude', 'view', 'format', 'delimiters', 'subfolders', 'fields', 'list',
        'commit', 'actions',
    ];
    private const GROUP_KEYS = ['name', 'label', 'description', 'type', 'items'];
    private const FIELD_KEYS = [
        'name', 'label', 'description', 'component', 'default', 'fields', 'type',
        'list', 'hidden', 'readonly', 'required', 'pattern', 'options', 'blocks', 'blockKey',
    ];
    private const FIELD_TYPES = [
        'boolean', 'code', 'date', 'file', 'image', 'number', 'reference',
        'rich-text', 'select', 'string', 'text', 'uuid', 'object', 'block',
    ];
    private const MEDIA_KEYS = [
        'name', 'label', 'input', 'output', 'path', 'extensions', 'categories',
        'rename', 'commit', 'actions',
    ];
    private const FORMATS = [
        'yaml-frontmatter', 'json-frontmatter', 'toml-frontmatter', 'yaml',
        'json', 'toml', 'datagrid', 'code', 'raw',
    ];
    private const NAME_PATTERN = '/^[a-zA-Z0-9\-_]+$/';

    private array $errors = [];
    private array $warnings = [];
    private array $components = [];
    private array $collections = [];

    public function validate(?string $file = null): array
    {
        $this->errors = [];
        $this->warnings = [];

        $file = $file ?? Network::path('.pages.yml');
        if (!is_file($file)) {
            $this->error('.pages.yml', 'File is missing. Run ./scripts/cms-config to generate it.');

            return $this->result();
        }

        try {
            $config = Yaml::parseFile($file) ?? [];
        } catch (\Throwable $e) {
            $this->error('.pages.yml', 'Invalid YAML: ' . $e->getMessage());

            return $this->result();
        }

        $this->components = array_keys((array) ($config['components'] ?? []));
        $this->collectNames((array) ($config['content'] ?? []));

        $this->validateMedia($config['media'] ?? null);
        $this->validateMergeSafety($config);

        foreach ((array) ($config['components'] ?? []) as $name => $component) {
            if (!preg_match(self::NAME_PATTERN, (string) $name)) {
                $this->error('components.' . $name, 'Component key must be alphanumeric with dashes and underscores.');
            }
            $this->validateField($component, 'components.' . $name, true);
        }

        foreach ((array) ($config['content'] ?? []) as $i => $entry) {
            $this->validateContent($entry, 'content[' . $i . ']');
        }

        return $this->result();
    }

    private function collectNames(array $entries): void
    {
        foreach ($entries as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            if (($entry['type'] ?? '') === 'group') {
                $this->collectNames((array) ($entry['items'] ?? []));
                continue;
            }
            if (isset($entry['name'])) {
                $this->collections[(string) $entry['name']] = $entry;
            }
        }
    }

    private function validateContent(mixed $entry, string $where): void
    {
        if (!\is_array($entry)) {
            $this->error($where, 'Content entry must be an object.');

            return;
        }

        $name = (string) ($entry['name'] ?? '');
        $label = $name !== '' ? $where . ' (' . $name . ')' : $where;

        if ($name === '') {
            $this->error($where, "'name' is required.");
        } elseif (!preg_match(self::NAME_PATTERN, $name)) {
            $this->error($label, "'name' must be alphanumeric with dashes and underscores.");
        }

        $type = (string) ($entry['type'] ?? '');

        if ($type === 'group') {
            $this->rejectUnknownKeys($entry, self::GROUP_KEYS, $label);
            if (!isset($entry['items']) || !\is_array($entry['items'])) {
                $this->error($label, "A group must have an 'items' array.");

                return;
            }
            foreach ($entry['items'] as $i => $child) {
                $this->validateContent($child, $label . '.items[' . $i . ']');
            }

            return;
        }

        if (!\in_array($type, ['collection', 'file'], true)) {
            $this->error($label, "'type' must be 'collection', 'file' or 'group'.");

            return;
        }

        $this->rejectUnknownKeys($entry, self::LEAF_KEYS, $label);

        $path = (string) ($entry['path'] ?? '');
        if ($path === '' && !\array_key_exists('path', $entry)) {
            $this->error($label, "'path' is required.");
        } elseif (str_starts_with($path, '/') || str_ends_with($path, '/')) {
            $this->error($label, "'path' must not start or end with a slash.");
        } else {
            $absolute = Network::path(...explode('/', $path));
            if ($type === 'collection' && !is_dir($absolute)) {
                $this->error($label, \sprintf('Collection path does not exist in the repository: %s', $path));
            }
            if ($type === 'file' && !is_file($absolute)) {
                $this->error($label, \sprintf('File path does not exist in the repository: %s', $path));
            }
        }

        if (isset($entry['format']) && !\in_array($entry['format'], self::FORMATS, true)) {
            $this->error($label, \sprintf('Unknown format "%s".', $entry['format']));
        }

        if (isset($entry['filename'])) {
            $filename = $entry['filename'];
            if (\is_array($filename)) {
                if (!isset($filename['template'])) {
                    $this->error($label, "'filename' object must contain 'template'.");
                }
                if (isset($filename['field']) && !\in_array($filename['field'], [true, false, 'create'], true)) {
                    $this->error($label, "'filename.field' must be true, false or 'create'.");
                }
            } elseif (!\is_string($filename)) {
                $this->error($label, "'filename' must be a string or an object.");
            }
        }

        foreach ((array) ($entry['fields'] ?? []) as $i => $field) {
            $this->validateField($field, $label . '.fields[' . $i . ']');
        }
    }

    private function validateField(mixed $field, string $where, bool $isComponent = false): void
    {
        if (!\is_array($field)) {
            $this->error($where, 'Field must be an object.');

            return;
        }

        $name = (string) ($field['name'] ?? '');
        $label = $name !== '' ? $where . ' (' . $name . ')' : $where;

        $this->rejectUnknownKeys($field, self::FIELD_KEYS, $label);

        if (!$isComponent) {
            if ($name === '') {
                $this->error($where, "'name' is required.");
            } elseif (!preg_match(self::NAME_PATTERN, $name)) {
                $this->error($label, "'name' must be alphanumeric with dashes and underscores.");
            }
        }

        $hasType = \array_key_exists('type', $field);
        $hasComponent = \array_key_exists('component', $field);

        if (!$isComponent && $hasType === $hasComponent) {
            $this->error($label, "Field must have exactly one of 'type' or 'component'.");
        }

        if ($hasComponent && !\in_array((string) $field['component'], $this->components, true)) {
            $this->error($label, \sprintf('Unknown component "%s".', $field['component']));
        }

        if ($hasType) {
            $type = (string) $field['type'];
            if (!\in_array($type, self::FIELD_TYPES, true)) {
                $this->error($label, \sprintf('Unknown field type "%s".', $type));
            }
            if ($type === 'object' && !isset($field['fields'])) {
                $this->error($label, "Fields of type 'object' must have a 'fields' array.");
            }
            if ($type === 'block' && !isset($field['blocks'])) {
                $this->error($label, "Fields of type 'block' must have a 'blocks' array.");
            }
            if ($type === 'select') {
                $values = $field['options']['values'] ?? null;
                if (!\is_array($values) || $values === []) {
                    $this->error($label, "Fields of type 'select' need options.values.");
                }
            }
            if ($type === 'reference') {
                $collection = (string) ($field['options']['collection'] ?? '');
                if ($collection === '') {
                    $this->error($label, "Fields of type 'reference' need options.collection.");
                } elseif (!isset($this->collections[$collection])) {
                    $this->error($label, \sprintf('Reference points at unknown collection "%s".', $collection));
                }
            }
        }

        if (\array_key_exists('blockKey', $field) && ($field['type'] ?? null) !== 'block') {
            $this->error($label, "'blockKey' is only valid on fields of type 'block'.");
        }

        foreach ((array) ($field['fields'] ?? []) as $i => $child) {
            $this->validateField($child, $label . '.fields[' . $i . ']');
        }
        foreach ((array) ($field['blocks'] ?? []) as $i => $child) {
            $this->validateField($child, $label . '.blocks[' . $i . ']', true);
        }
    }

    /**
     * Configuration files are edited through a partial form: only a subset of
     * their keys is declared. Without `settings.content.merge` Pages CMS would
     * replace the whole file on save and silently delete everything the form
     * does not know about — routes, the content directory, Cecil options.
     */
    private function validateMergeSafety(array $config): void
    {
        $partial = [];
        foreach ($this->collections as $name => $entry) {
            if (($entry['type'] ?? '') === 'file' && str_starts_with((string) ($entry['path'] ?? ''), 'config/')) {
                $partial[] = $name;
            }
        }
        if ($partial === []) {
            return;
        }

        if (($config['settings']['content']['merge'] ?? false) !== true) {
            $this->error('.pages.yml', \sprintf(
                'settings.content.merge must be true: %s edit configuration files through a partial form, '
                . 'and without merge every save would delete the keys the form does not declare.',
                implode(', ', $partial)
            ));
        }
    }

    private function validateMedia(mixed $media): void
    {
        if ($media === null) {
            $this->warn('.pages.yml', 'No media configuration: editors will not be able to upload images.');

            return;
        }
        if (\is_string($media)) {
            return;
        }
        if (!\is_array($media)) {
            $this->error('media', "'media' must be a string, an object or an array of objects.");

            return;
        }

        $entries = isset($media['input']) ? [$media] : $media;
        foreach ($entries as $i => $entry) {
            $where = 'media[' . $i . ']';
            if (!\is_array($entry)) {
                $this->error($where, 'Media entry must be an object.');
                continue;
            }
            $this->rejectUnknownKeys($entry, self::MEDIA_KEYS, $where);

            $input = (string) ($entry['input'] ?? '');
            if ($input === '') {
                $this->error($where, "'input' is required.");
            } elseif (str_starts_with($input, '/') || str_ends_with($input, '/')) {
                $this->error($where, "'input' must be a relative path without leading or trailing slash.");
            } elseif (!is_dir(Network::path(...explode('/', $input)))) {
                $this->error($where, \sprintf('Media input directory does not exist: %s', $input));
            }

            $output = (string) ($entry['output'] ?? '');
            if ($output === '') {
                $this->error($where, "'output' is required.");
            } elseif (str_ends_with($output, '/')) {
                $this->error($where, "'output' must not end with a slash.");
            }

            if (\count($entries) > 1 && ($entry['name'] ?? '') === '') {
                $this->error($where, "'name' is required when several media sources are configured.");
            }
        }
    }

    private function rejectUnknownKeys(array $subject, array $allowed, string $where): void
    {
        foreach (array_keys($subject) as $key) {
            if (!\in_array((string) $key, $allowed, true)) {
                $this->error($where, \sprintf('Unknown key "%s" — Pages CMS rejects the whole config on unknown keys.', $key));
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

    private function result(): array
    {
        return ['errors' => $this->errors, 'warnings' => $this->warnings];
    }
}
