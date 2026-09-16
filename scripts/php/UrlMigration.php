<?php
declare(strict_types=1);
namespace AiGf\Tools;

final class UrlMigration
{
    /** Apply route changes as one local transaction; caller restores returned originals on failure. */
    public static function routes(string $site, array $before, array &$originals, array $replacements = []): array
    {
        $after = array_column(ContentScanner::scan($site), null, 'relative');
        foreach ($before as $old) {
            $new = $after[$old['relative']] ?? null;
            if (!$new || $new['path'] === $old['path'] || ($old['front_matter']['status'] ?? '') !== 'published') { continue; }
            $from = '/' . trim($old['path'], '/') . '/';
            $to = '/' . trim($new['path'], '/') . '/';
            $fm = $new['front_matter'];
            $fm['aliases'] = array_values(array_unique(array_merge($fm['aliases'] ?? [], [$from])));
            // A previously used address may be reused by this same page.
            $fm['aliases'] = array_values(array_filter($fm['aliases'], static fn ($alias) => $alias !== $to));
            $originals[realpath($new['file'])] ??= file_get_contents($new['file']);
            file_put_contents($new['file'], StudioContent::markdown($fm, $new['body']));
            $replacements[$from] = $to;
        }
        foreach (ContentScanner::scan($site) as $page) {
            $text = file_get_contents($page['file']);
            $fm = self::rewriteValues($page['front_matter'], $replacements);
            $body = self::rewrite($page['body'], $replacements);
            $changed = $fm !== $page['front_matter'] || $body !== $page['body'] ? StudioContent::markdown($fm, $body) : $text;
            if ($text !== $changed) { $originals[realpath($page['file'])] ??= $text; file_put_contents($page['file'], $changed); }
        }
        return $replacements;
    }

    public static function rewrite(string $text, array $map): string
    {
        // One pass prevents double rewrites when old/new paths overlap.
        return preg_replace_callback('~(\]\()(/[^\s)#?]+)(?=[)#?\s])~', static fn ($m) => $m[1] . ($map[$m[2]] ?? $m[2]), $text);
    }

    public static function rewriteValues(array $values, array $map): array
    {
        foreach ($values as $key => &$value) {
            if ($key === 'aliases') { continue; }
            if (is_array($value)) { $value = self::rewriteValues($value, $map); }
            elseif (is_string($value)) {
                $value = self::rewrite($value, $map);
                $value = preg_replace_callback('~^(/[^?#]+)([?#].*)?$~', static fn ($m) => ($map[$m[1]] ?? $m[1]) . ($m[2] ?? ''), $value);
            }
        } unset($value);
        return $values;
    }
}
