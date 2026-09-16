<?php
declare(strict_types=1);
namespace AiGf\Tools;

use Symfony\Component\Yaml\Yaml;

/** Configuration is trusted code; content submitted by editors is not. */
final class Model
{
    public static function types(string $site): array
    {
        $name = Network::geo($site)['site_identity']['model'];
        Network::assertId($name);
        $model = Yaml::parseFile(Network::path('config', 'models', $name . '.yml'));
        $types = $model['types'] ?? [];
        if (!$types) { throw new \RuntimeException('Модель не содержит типов страниц: ' . $name); }
        foreach ($types as $id => $type) {
            Network::assertId($id);
            if (isset($type['section'])) { Network::assertId($type['section']); }
            self::template($type['layout'] ?? '_default/page');
        }
        return $types;
    }

    public static function blocks(): array
    {
        return Yaml::parseFile(Network::path('config', 'blocks.yml')) ?? [];
    }

    public static function template(string $name): void
    {
        if (!preg_match('~^[a-zA-Z0-9_/-]+$~D', $name) || str_contains($name, '..')
            || !is_file(Network::path('engine', 'layouts', $name . '.html.twig'))) {
            throw new \RuntimeException('Не найден шаблон: ' . $name);
        }
    }

    public static function commonFields(): array
    {
        return [
            'title' => ['label' => 'Заголовок', 'type' => 'string', 'required' => true],
            'slug' => ['label' => 'Адрес страницы', 'type' => 'string'],
            'translation_key' => ['label' => 'Ключ перевода', 'type' => 'string'],
            'intro' => ['label' => 'Введение', 'type' => 'text'],
            'seo' => ['label' => 'SEO', 'type' => 'object', 'required' => true, 'fields' => [
                'title' => ['label' => 'SEO-заголовок', 'type' => 'string', 'required' => true],
                'description' => ['label' => 'Описание для поиска', 'type' => 'text', 'required' => true],
                'primary_keyword' => ['label' => 'Основной запрос', 'type' => 'string'],
                'og_title' => ['label' => 'Заголовок для соцсетей', 'type' => 'string'],
                'og_description' => ['label' => 'Описание для соцсетей', 'type' => 'text'],
                'og_image' => ['label' => 'Изображение для соцсетей', 'type' => 'image'],
            ]],
            'canonical' => ['label' => 'Canonical (обычно формируется автоматически)', 'type' => 'object', 'fields' => [
                'url' => ['label' => 'Другой канонический URL', 'type' => 'url'],
            ]],
            'image' => ['label' => 'Обложка', 'type' => 'image'],
            'image_alt' => ['label' => 'Описание обложки', 'type' => 'string'],
            'breadcrumb_title' => ['label' => 'Название в хлебных крошках', 'type' => 'string'],
            'indexing' => ['label' => 'Индексация', 'type' => 'object', 'fields' => [
                'index' => ['label' => 'Разрешить индексацию', 'type' => 'boolean'],
                'follow' => ['label' => 'Разрешить переходы', 'type' => 'boolean'],
            ]],
            'date' => ['label' => 'Дата материала', 'type' => 'date'],
            'updated' => ['label' => 'Дата обновления', 'type' => 'date'],
            'author' => ['label' => 'Автор', 'type' => 'string'],
            'reviewer' => ['label' => 'Проверил', 'type' => 'string'],
            'related' => ['label' => 'Связанные страницы', 'type' => 'list', 'items' => ['type' => 'string']],
            'faq' => array_replace(self::blocks()['faq']['fields']['items'], ['required' => false, 'min_items' => 0]),
        ];
    }

    public static function fields(string $site, string $type): array
    {
        $types = self::types($site);
        if (!isset($types[$type])) { throw new \InvalidArgumentException('Неизвестный тип страницы.'); }
        return array_replace(self::commonFields(), $types[$type]['fields'] ?? []);
    }

    public static function validateFields(array $data, array $fields, string $prefix = '', bool $required = true): array
    {
        $errors = [];
        foreach ($fields as $name => $rule) {
            $path = $prefix . $name;
            $value = $data[$name] ?? null;
            if ($value === null || $value === '' || $value === []) {
                if ($required && ($rule['required'] ?? false)) { $errors[] = ['field' => $path, 'message' => 'Заполните поле «' . ($rule['label'] ?? $name) . '».']; }
                continue;
            }
            $type = $rule['type'] ?? 'string';
            $valid = match ($type) {
                'object' => is_array($value) && !array_is_list($value),
                'list' => is_array($value) && array_is_list($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'date' => is_int($value) || (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)),
                default => is_string($value),
            };
            if (!$valid) { $errors[] = ['field' => $path, 'message' => 'Неверный формат поля «' . ($rule['label'] ?? $name) . '».']; continue; }
            if ($type === 'object') { $errors = array_merge($errors, self::validateFields($value, $rule['fields'] ?? [], $path . '.', $required)); }
            if ($type === 'list') {
                if ($required && count($value) < ($rule['min_items'] ?? 0)) { $errors[] = ['field' => $path, 'message' => 'Добавьте не менее ' . $rule['min_items'] . ' элементов.']; }
                foreach ($value as $i => $item) { $errors = array_merge($errors, self::validateFields([$i => $item], [$i => $rule['items'] ?? ['type' => 'string']], $path . '.', $required)); }
            }
            if ($type === 'product' && !isset(Network::products()[$value])) { $errors[] = ['field' => $path, 'message' => 'Выберите существующий продукт.']; }
            if ($type === 'url' && !self::safeUrl($value)) { $errors[] = ['field' => $path, 'message' => 'Укажите HTTPS-адрес или путь внутри сайта, начинающийся с /.']; }
            if ($type === 'image' && (!preg_match('~^/images/[a-zA-Z0-9_./-]+$~D', $value) || str_contains($value, '..') || !is_file(Network::path('static', ltrim($value, '/'))))) {
                $errors[] = ['field' => $path, 'message' => 'Выберите загруженное изображение.'];
            }
            if (isset($rule['options']) && !in_array($value, $rule['options'], true)) { $errors[] = ['field' => $path, 'message' => 'Выберите значение из списка.']; }
            if ($type === 'number' && ((isset($rule['minimum']) && $value < $rule['minimum']) || (isset($rule['maximum']) && $value > $rule['maximum']))) {
                $errors[] = ['field' => $path, 'message' => 'Число выходит за разрешённые границы.'];
            }
        }
        return $errors;
    }

    public static function safeUrl(string $value): bool
    {
        return !preg_match('/[\x00-\x20\\\\]/', $value) && ((str_starts_with($value, '/') && !str_starts_with($value, '//'))
            || (filter_var($value, FILTER_VALIDATE_URL) && parse_url($value, PHP_URL_SCHEME) === 'https'));
    }

    public static function validateBlocks(mixed $blocks): array
    {
        if (!is_array($blocks) || !array_is_list($blocks)) { return [['field' => 'blocks', 'message' => 'Блоки должны быть списком.']]; }
        $errors = [];
        foreach ($blocks as $i => $block) {
            $def = is_array($block) ? (self::blocks()[$block['type'] ?? ''] ?? null) : null;
            if (!$def) { $errors[] = ['field' => "blocks.$i.type", 'message' => 'Выберите существующий тип блока.']; continue; }
            self::template($def['template']);
            $errors = array_merge($errors, self::validateFields($block, $def['fields'], "blocks.$i."));
        }
        return $errors;
    }
}
