<?php
declare(strict_types=1);
namespace AiGf\Tools;

/** Exact-match server redirects travel with each release. No HTML redirect stubs. */
final class Redirects
{
    public static function map(string $site): array
    {
        $map = Network::geo($site)['redirects'] ?? [];
        foreach (ContentScanner::scan($site) as $page) {
            if (($page['front_matter']['status'] ?? '') !== 'published') { continue; }
            foreach ($page['front_matter']['aliases'] ?? [] as $old) {
                if (isset($map[$old]) && $map[$old] !== '/' . trim($page['path'], '/') . '/') { throw new \RuntimeException('Повторный старый адрес: ' . $old); }
                $map[$old] = $page['path'] === '' ? '/' : '/' . $page['path'] . '/';
            }
        }
        return $map;
    }
    public static function validPath(string $path): bool
    {
        return (bool) preg_match('~^/(?:[a-zA-Z0-9_-]+/)*$~D', $path);
    }
    public static function validate(string $site): array
    {
        $errors = [];
        try {
            $map = self::map($site);
            $pages = ContentScanner::scan($site);
            $live = ['/'];
            foreach ($pages as $p) { if (($p['front_matter']['status'] ?? '') === 'published') { $live[] = '/' . trim($p['path'], '/') . '/'; } }
            foreach (ContentScanner::generatedSections($site, $pages) as $s) { $live[] = '/' . $s['path'] . '/'; }
            foreach ($map as $from => $to) {
                if (!is_string($from) || !is_string($to) || !self::validPath($from) || !self::validPath($to)) { throw new \RuntimeException('Редиректы должны содержать внутренние адреса вида /old/ → /new/.'); }
                if (in_array($from, $live, true)) { throw new \RuntimeException('Старый адрес занят опубликованной страницей: ' . $from); }
                $seen = [$from]; $target = $to;
                while (isset($map[$target])) {
                    if (in_array($target, $seen, true)) { throw new \RuntimeException('Цикл редиректов: ' . $from); }
                    $seen[] = $target; $target = $map[$target];
                }
                if (!in_array($target, $live, true)) { throw new \RuntimeException('Цель редиректа не опубликована: ' . $target); }
            }
        } catch (\Throwable $e) { $errors[] = ['where' => $site, 'field' => 'aliases', 'message' => $e->getMessage()]; }
        return $errors;
    }
    public static function write(string $site, string $dist): void
    {
        $errors = self::validate($site);
        if ($errors) { throw new \RuntimeException($errors[0]['message']); }
        $map = self::map($site); $nginx = "# Generated exact-match redirects\n"; $portable = '';
        foreach ($map as $from => $to) {
            while (isset($map[$to])) { $to = $map[$to]; }
            $nginx .= 'location = ' . $from . ' { return 301 ' . $to . '$is_args$args; }' . "\n";
            $portable .= "$from $to 301\n";
        }
        file_put_contents($dist . '/redirects.nginx.conf', $nginx);
        file_put_contents($dist . '/_redirects', $portable);
        file_put_contents($dist . '/redirects.json', json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
