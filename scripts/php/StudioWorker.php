<?php
declare(strict_types=1);
namespace AiGf\Tools;

final class StudioWorker
{
    /** One worker at a time. A killed worker is recorded as failed on the next run. */
    public static function once(bool $deploy = false): ?array
    {
        $data = StudioStore::dir();
        StudioStore::transaction(static fn (&$s) => null);
        $lock = fopen($data . '/worker.lock', 'c');
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return null; }
        $root = Network::root();
        $oldRoot = getenv('MINICMS_ROOT'); $oldData = getenv('STUDIO_DATA');
        putenv('STUDIO_DATA=' . $data);
        try {
            $job = StudioStore::transaction(function (&$state) {
                foreach ($state['jobs'] as &$job) {
                    if ($job['state'] === 'publishing') {
                        $job['state'] = 'publication_failed';
                        $job['errors'] = [['field' => 'body', 'message' => 'Предыдущий процесс публикации прерван. Проверьте релиз на серверах перед повтором.']];
                        $state['documents'][$job['key']]['state'] = 'publication_failed';
                        $state['documents'][$job['key']]['errors'] = $job['errors'];
                    }
                } unset($job);
                foreach ($state['jobs'] as &$candidate) {
                    if ($candidate['state'] !== 'queued') { continue; }
                    $doc = $state['documents'][$candidate['key']];
                    $approver = $state['users'][$doc['approved_by'] ?? ''] ?? null;
                    if ($doc['revision'] !== $candidate['document']['revision'] || ($doc['approved_revision'] ?? -1) !== $doc['revision'] || !$approver || !$approver['enabled'] || !in_array($approver['role'], ['editor', 'admin'], true)
                        || (!in_array('*', $approver['sites'], true) && !in_array($doc['site'], $approver['sites'], true))) {
                        $candidate['state'] = 'cancelled'; continue;
                    }
                    $candidate['state'] = 'publishing'; $candidate['started_at'] = gmdate('c');
                    $state['documents'][$candidate['key']]['state'] = 'publishing';
                    return $candidate;
                }
                return null;
            });
            if (!$job) { return null; }
            $directory = $data . '/jobs/' . $job['id'];
            $workspace = $directory . '/workspace';
            mkdir($workspace, 0700, true);
            $issues = []; $log = ''; $artifact = null; $moves = []; $baseline = [];
            try {
                foreach (['config', 'content', 'data', 'engine', 'static'] as $dir) { self::copyTree($root . '/' . $dir, $workspace . '/' . $dir); }
                $doc = $job['document'];
                $baseline = [];
                foreach (ContentScanner::scan($doc['site']) as $record) { $baseline[$record['relative']] = hash_file('sha256', $record['file']); }
                $source = StudioContent::path($doc['site'], $doc['page']);
                $hash = is_file($source) ? hash_file('sha256', $source) : null;
                if ($hash !== $doc['base_hash']) { throw new \RuntimeException('Исходник изменён вне Studio. Согласуйте изменения перед публикацией.'); }
                $fm = $doc['front_matter']; $fm['status'] = 'published';
                $old = null;
                foreach (ContentScanner::scan($doc['site']) as $p) { if ($p['relative'] === $doc['page']) { $old = $p; break; } }
                if ($old && ($old['front_matter']['status'] ?? '') === 'published' && $old['slug'] !== ($fm['slug'] ?? $old['slug'])) {
                    $fm['aliases'] = array_values(array_unique(array_merge($fm['aliases'] ?? [], ['/' . $old['path'] . '/'])));
                    $resolver = new \AiGf\Engine\Routing\RouteResolver(Network::routes($doc['site']));
                    $folder = dirname($doc['page']);
                    $subfolder = $old['section'] !== null && str_starts_with($folder, $old['section'] . '/') ? substr($folder, strlen($old['section']) + 1) : null;
                    $newPath = '/' . $resolver->pagePath($old['section'], $fm['slug'], $subfolder) . '/';
                    $fm['aliases'] = array_values(array_filter($fm['aliases'], static fn ($alias) => $alias !== $newPath));
                }
                putenv('MINICMS_ROOT=' . $workspace); Network::reset();
                $target = StudioContent::path($doc['site'], $doc['page']);
                if (!is_dir(dirname($target))) { mkdir(dirname($target), 0755, true); }
                file_put_contents($target, StudioContent::markdown($fm, $doc['body']));
                // Rewrite internal references to a changed slug in the isolated release.
                if ($old && $old['slug'] !== ($fm['slug'] ?? $old['slug'])) {
                    $new = null;
                    foreach (ContentScanner::scan($doc['site']) as $p) { if ($p['relative'] === $doc['page']) { $new = $p; } }
                    if ($new) {
                        $moves = ['/' . $old['path'] . '/' => '/' . $new['path'] . '/'];
                        self::rewriteLinks(Network::contentDir($doc['site']), '/' . $old['path'] . '/', '/' . $new['path'] . '/');
                    }
                }
                $sites = array_values(array_unique(array_merge(Network::codes(true), [$doc['site']])));
                $issues = (new DataValidator())->validate()['errors'];
                foreach ($sites as $site) { $issues = array_merge($issues, (new ContentValidator())->validate($site)['errors']); }
                if ($issues) { throw new \RuntimeException('Исправьте ошибки в материалах.'); }
                Prepare::run($sites);
                foreach ($sites as $site) {
                    [$exit, $out, $err] = Builder::run([PHP_BINARY, Network::codeRoot() . '/scripts/build.php', $site, '--optimize']);
                    $log .= $out . $err;
                    if ($exit !== 0) { throw new \RuntimeException('Сборка сайта ' . $site . ' не прошла. Подробности доступны в журнале задания.'); }
                }
                $artifact = self::archive($workspace, $directory, $job, $sites);
                if ($deploy) {
                    if (!getenv('DEPLOY_HOSTS') && !getenv('DEPLOY_HOST')) { throw new \RuntimeException('На worker не настроен DEPLOY_HOSTS.'); }
                    $shell = getenv('STUDIO_SHELL') ?: 'sh';
                    [$exit, $out, $err] = Builder::run(array_merge([$shell, Network::codeRoot() . '/scripts/deploy-all'], $sites));
                    $log .= $out . $err;
                    if ($exit !== 0) { throw new \RuntimeException('Деплой не завершён на всех серверах. Проверьте журнал и версии серверов; частичный выпуск возможен.'); }
                    // Only adopt sources after deployment. Preserve all unrelated external changes.
                    putenv('MINICMS_ROOT=' . $root); Network::reset();
                    $current = is_file($source) ? hash_file('sha256', $source) : null;
                    if ($current !== $hash) { throw new \RuntimeException('Релиз развёрнут, но исходник изменился во время деплоя. Требуется согласование.'); }
                    $changed = [];
                    $contentDir = $workspace . '/' . Network::geo($doc['site'])['pages']['dir'];
                    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($contentDir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                        if (!$f->isFile() || $f->getExtension() !== 'md') { continue; }
                        $relative = str_replace('\\', '/', substr($f->getPathname(), strlen($contentDir) + 1));
                        if (($baseline[$relative] ?? null) === hash_file('sha256', $f->getPathname())) { continue; }
                        $destination = StudioContent::path($doc['site'], $relative);
                        $actual = is_file($destination) ? hash_file('sha256', $destination) : null;
                        if ($actual !== ($baseline[$relative] ?? null)) { throw new \RuntimeException('Релиз развёрнут, но файл ' . $relative . ' изменился вне Studio. Исходники не перезаписаны.'); }
                        $changed[$destination] = $f->getPathname();
                    }
                    foreach ($changed as $destination => $from) {
                        if (!is_dir(dirname($destination))) { mkdir(dirname($destination), 0755, true); }
                        if (!copy($from, $destination)) { throw new \RuntimeException('Релиз развёрнут, но не удалось сохранить исходник.'); }
                    }
                }
                $resultState = $deploy ? 'published' : 'built';
            } catch (\Throwable $e) {
                $resultState = 'publication_failed';
                if (!$issues) { $issues[] = Diagnostics::enrich(['where' => $job['document']['site'] . '/' . $job['document']['page'], 'field' => 'body', 'message' => $e->getMessage()]); }
                $log .= "\n" . $e->getMessage();
            }
            putenv('MINICMS_ROOT=' . $root); Network::reset();
            file_put_contents($directory . '/worker.log', $log);
            return StudioStore::transaction(function (&$state) use ($job, $resultState, $issues, $artifact, $deploy, $moves, $baseline) {
                $state['jobs'][$job['id']]['state'] = $resultState;
                $state['jobs'][$job['id']]['finished_at'] = gmdate('c');
                $state['jobs'][$job['id']]['errors'] = $issues;
                $state['jobs'][$job['id']]['artifact'] = $artifact;
                $doc = &$state['documents'][$job['key']];
                $doc['state'] = $resultState === 'built' ? 'approved' : $resultState;
                $doc['errors'] = $issues;
                if ($resultState === 'published') {
                    $doc['published_revision'] = $doc['revision'];
                    $doc['published_at'] = gmdate('c');
                    $doc['base_hash'] = hash_file('sha256', StudioContent::path($doc['site'], $doc['page']));
                    [$doc['front_matter'], $doc['body']] = ContentScanner::parse(StudioContent::path($doc['site'], $doc['page']));
                    foreach ($state['documents'] as $key => &$pending) {
                        if ($key === $job['key'] || $pending['site'] !== $doc['site'] || !$moves) { continue; }
                        $file = StudioContent::path($pending['site'], $pending['page']);
                        if (is_file($file) && $pending['base_hash'] === ($baseline[$pending['page']] ?? null) && $pending['base_hash'] !== hash_file('sha256', $file)) {
                            $pending['body'] = UrlMigration::rewrite($pending['body'], $moves);
                            $pending['front_matter'] = UrlMigration::rewriteValues($pending['front_matter'], $moves);
                            $pending['base_hash'] = hash_file('sha256', $file);
                            $pending['revision']++; $pending['state'] = 'draft'; unset($pending['approved_revision'], $pending['approved_by']);
                            $pending['history'][] = ['at' => gmdate('c'), 'actor' => 'worker', 'action' => 'links_updated', 'revision' => $pending['revision']];
                        }
                    } unset($pending);
                }
                $doc['history'][] = ['at' => gmdate('c'), 'actor' => 'worker', 'action' => $resultState, 'revision' => $doc['revision']];
                StudioStore::audit($state, 'worker', 'job.' . $resultState, ['job' => $job['id']]);
                return $state['jobs'][$job['id']];
            });
        } finally {
            $oldRoot === false ? putenv('MINICMS_ROOT') : putenv('MINICMS_ROOT=' . $oldRoot);
            $oldData === false ? putenv('STUDIO_DATA') : putenv('STUDIO_DATA=' . $oldData);
            Network::reset(); flock($lock, LOCK_UN); fclose($lock);
        }
    }

    public static function copyTree(string $from, string $to): void
    {
        if (!is_dir($from)) { return; }
        if (!is_dir($to)) { mkdir($to, 0700, true); }
        foreach (new \DirectoryIterator($from) as $f) {
            if ($f->isDot()) { continue; }
            if ($f->isLink()) { throw new \RuntimeException('Ссылки в дереве проекта не поддерживаются.'); }
            $dest = $to . '/' . $f->getFilename();
            if ($f->isDir()) { self::copyTree($f->getPathname(), $dest); }
            elseif (!copy($f->getPathname(), $dest)) { throw new \RuntimeException('Не удалось скопировать файл.'); }
        }
    }

    public static function rewriteLinks(string $dir, string $old, string $new): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->getExtension() !== 'md') { continue; }
            $text = file_get_contents($f->getPathname());
            [$fm, $body] = ContentScanner::parse($f->getPathname());
            $newFm = UrlMigration::rewriteValues($fm, [$old => $new]);
            $newBody = UrlMigration::rewrite($body, [$old => $new]);
            $changed = $newFm !== $fm || $newBody !== $body ? StudioContent::markdown($newFm, $newBody) : $text;
            if ($text !== $changed) { file_put_contents($f->getPathname(), $changed); }
        }
    }

    private static function archive(string $workspace, string $directory, array $job, array $sites): string
    {
        $zip = new \ZipArchive();
        $path = $directory . '/release.zip';
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) { throw new \RuntimeException('Не удалось создать архив.'); }
        $manifest = ['release' => $job['id'], 'created_at' => gmdate('c'), 'revision' => $job['document']['revision'], 'sites' => $sites, 'files' => []];
        foreach (['dist', 'config', 'content', 'data', 'static', 'engine'] as $part) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($workspace . '/' . $part, \FilesystemIterator::SKIP_DOTS)) as $f) {
                if (!$f->isFile()) { continue; }
                $relative = str_replace('\\', '/', substr($f->getPathname(), strlen($workspace) + 1));
                $manifest['files'][$relative] = hash_file('sha256', $f->getPathname());
                $zip->addFile($f->getPathname(), $relative);
            }
        }
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if (!$zip->close()) { throw new \RuntimeException('Не удалось записать архив.'); }
        return hash_file('sha256', $path);
    }
}
