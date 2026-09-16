<?php
declare(strict_types=1);
namespace AiGf\Tools;

final class Diagnostics
{
    public static function enrich(array $issue): array
    {
        $field = $issue['field'] ?? '';
        if ($field === '' && preg_match('/\b(seo\.(?:title|description|primary_keyword)|canonical|slug|title|status|type|blocks|product|ranking|related|hero_cta|indexing\.(?:index|follow)|author|reviewer|faq)\b/', $issue['message'], $m)) { $field = $m[1]; }
        if ($field === '' && str_contains($issue['message'], 'heading')) { $field = 'body'; }
        $issue['field'] = $field ?: 'body';
        if (preg_match('~^([a-z][a-z0-9_-]*)/(.+\.md)$~', $issue['where'], $m) && Network::exists($m[1])) {
            $issue['site'] = $m[1]; $issue['page'] = $m[2];
            $issue['edit_url'] = '/?view=editor&site=' . rawurlencode($m[1]) . '&page=' . rawurlencode($m[2]) . '#field-' . str_replace('.', '-', $issue['field']);
        }
        return $issue;
    }
}
