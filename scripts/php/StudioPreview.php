<?php
declare(strict_types=1);
namespace AiGf\Tools;
use Symfony\Component\Yaml\Yaml;

final class StudioPreview
{
    public static function create(array $user, string $site, string $page): string
    {
        $doc = StudioContent::get($user, $site, $page);
        $id = bin2hex(random_bytes(16));
        $root = Network::root(); $env = getenv('MINICMS_ROOT');
        $directory = StudioStore::dir() . '/previews/' . $id;
        $workspace = $directory . '/workspace';
        mkdir($workspace, 0700, true);
        try {
            foreach (['config', 'content', 'data', 'engine', 'static'] as $dir) { StudioWorker::copyTree($root . '/' . $dir, $workspace . '/' . $dir); }
            putenv('MINICMS_ROOT=' . $workspace); Network::reset();
            $path = StudioContent::path($site, $page);
            if (!is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
            $fm = $doc['front_matter']; $fm['status'] = 'published';
            file_put_contents($path, StudioContent::markdown($fm, $doc['body']));
            $config = Network::geo($site); unset($config['site_identity']);
            $config['geo']['staging'] = true;
            file_put_contents(Network::path('config', 'sites', $site . '.yml'), Yaml::dump($config, 20, 2));
            Network::reset();
            [$exit, $out, $err] = Builder::run([PHP_BINARY, Network::codeRoot() . '/scripts/build.php', $site, '--drafts', '--skip-validation']);
            if ($exit !== 0) { throw new \RuntimeException('Предпросмотр не собран. Проверьте заполнение полей и ссылки. ' . substr(strip_tags($err), 0, 300)); }
            $host = Network::host($site); $url = '/';
            foreach (ContentScanner::scan($site) as $p) { if ($p['relative'] === $page) { $url = $p['path'] === '' ? '/' : '/' . $p['path'] . '/'; } }
            file_put_contents($directory . '/access.json', json_encode(['owner' => $user['login'], 'site' => $site, 'host' => $host, 'expires' => time() + 3600]));
            file_put_contents($workspace . '/dist/' . $host . '/.preview', 'Never deploy preview output.');
            return '/preview/' . $id . $url;
        } finally { $env === false ? putenv('MINICMS_ROOT') : putenv('MINICMS_ROOT=' . $env); Network::reset(); }
    }
}
