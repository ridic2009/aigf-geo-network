<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use AiGf\Tools\{Network, Model, StudioStore, StudioAuth, StudioCatalog, StudioContent, StudioHistory, StudioInsight, StudioSites, StudioPreview};

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || getenv('STUDIO_HTTPS') === '1';
if (!$secure && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(400); exit('Studio требует HTTPS. Настройте reverse proxy и STUDIO_HTTPS=1.');
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; frame-src 'self'; frame-ancestors 'self'; base-uri 'none'; form-action 'self'; object-src 'none'");
if (in_array($path, ['/app.js', '/app.css'], true)) {
    header('Content-Type: ' . ($path === '/app.js' ? 'text/javascript' : 'text/css') . '; charset=utf-8');
    readfile(__DIR__ . $path); exit;
}
StudioStore::transaction(static fn (&$s) => null);
$sessions = StudioStore::dir() . '/sessions';
if (!is_dir($sessions)) { mkdir($sessions, 0700, true); }
ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1');
session_save_path($sessions); session_name('minicms_studio');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
if (isset($_SESSION['seen']) && time() - $_SESSION['seen'] > 28800) { $_SESSION = []; session_regenerate_id(true); }
$_SESSION['seen'] = time();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$user = isset($_SESSION['login']) ? StudioAuth::user($_SESSION['login']) : null;

function response(array $data, int $status = 200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit;
}

try {
    if (str_starts_with($path, '/preview/')) {
        if (!$user) { http_response_code(401); exit('Войдите в Studio.'); }
        if (!preg_match('~^/preview/([a-f0-9]{32})(/.*)$~D', $path, $match)) { throw new RuntimeException('Предпросмотр не найден.', 404); }
        $dir = StudioStore::dir() . '/previews/' . $match[1];
        $access = is_file($dir . '/access.json') ? json_decode(file_get_contents($dir . '/access.json'), true) : null;
        if (!$access || $access['expires'] < time() || $access['owner'] !== $user['login']) { throw new RuntimeException('Предпросмотр истёк или недоступен.', 404); }
        StudioAuth::requireSite($user, $access['site']);
        $dist = realpath($dir . '/workspace/dist/' . $access['host']);
        $file = $dist . $match[2];
        if (is_dir($file)) { $file .= '/index.html'; }
        $file = realpath($file);
        if (!$file || !str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', $dist) . '/')) { throw new RuntimeException('Файл не найден.', 404); }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $types = ['html' => 'text/html', 'css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2'];
        if (!isset($types[$ext])) { throw new RuntimeException('Файл не найден.', 404); }
        header('Content-Type: ' . $types[$ext] . '; charset=utf-8');
        header("Content-Security-Policy: sandbox allow-same-origin; default-src 'self'; script-src 'none'; style-src 'self'; img-src 'self' data:; frame-ancestors 'self'; base-uri 'none'; form-action 'none'");
        $body = file_get_contents($file);
        if ($ext === 'html') { $body = preg_replace('~\b(href|src)=(?:"|\x27)?/(?!/)([^\s>"\x27]*)["\x27]?~i', '$1="/preview/' . $match[1] . '/$2"', $body); }
        echo $body; exit;
    }
    if ($path === '/api') {
        $action = $_GET['action'] ?? '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) { throw new RuntimeException('Сессия формы устарела. Обновите страницу.', 403); }
            if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 6000000) { throw new RuntimeException('Запрос слишком большой.', 413); }
            $input = $action === 'upload' ? $_POST : json_decode(file_get_contents('php://input'), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($input)) { throw new InvalidArgumentException('Некорректный запрос.'); }
            if ($action === 'setup') {
                if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || $secure) { throw new RuntimeException('Первый администратор создаётся локально или через CLI.', 403); }
                StudioAuth::create((string) ($input['login'] ?? ''), (string) ($input['password'] ?? ''), 'admin', ['*'], true);
                response(['ok' => true]);
            }
            if ($action === 'login') {
                $logged = StudioAuth::login((string) ($input['login'] ?? ''), (string) ($input['password'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? '');
                if (!$logged) { response(['ok' => false, 'message' => 'Не удалось войти. Проверьте логин и пароль. После нескольких ошибок повторите через 15 минут.'], 401); }
                session_regenerate_id(true); $_SESSION['login'] = $logged['login']; $_SESSION['csrf'] = bin2hex(random_bytes(32));
                response(['ok' => true, 'user' => $logged, 'csrf' => $_SESSION['csrf']]);
            }
            if (!$user) { throw new RuntimeException('Войдите в Studio.', 401); }
            $site = (string) ($input['site'] ?? ''); $page = (string) ($input['page'] ?? '');
            switch ($action) {
                case 'logout': $_SESSION = []; session_destroy(); response(['ok' => true]);
                case 'user-create':
                    StudioAuth::requireAdmin($user);
                    StudioAuth::create((string) ($input['login'] ?? ''), (string) ($input['password'] ?? ''), (string) ($input['role'] ?? 'author'), (array) ($input['sites'] ?? []));
                    response(['ok' => true]);
                case 'user-update':
                    StudioAuth::update($user, (string) ($input['login'] ?? ''), (string) ($input['role'] ?? ''), (array) ($input['sites'] ?? []), (bool) ($input['enabled'] ?? false));
                    response(['ok' => true]);
                case 'save': response(StudioContent::save($user, $site, $page, $input));
                case 'transition': response(StudioContent::transition($user, $site, $page, (string) ($input['transition'] ?? ''), (int) ($input['revision'] ?? -1)));
                case 'site-save': response(['ok' => true, 'site' => StudioSites::save($user, $site, $input, !empty($input['create']))]);
                case 'product-save': response(['ok' => true] + StudioCatalog::save($user, (string) ($input['id'] ?? ''), $input, !empty($input['create'])));
                case 'product-delete':
                    StudioCatalog::delete($user, (string) ($input['id'] ?? ''));
                    response(['ok' => true]);
                case 'history-restore':
                    $restored = StudioStore::transaction(fn (&$s) => StudioHistory::restore($user, (string) ($input['path'] ?? ''), (string) ($input['sha'] ?? ''), $s));
                    response(['ok' => true, 'path' => $restored]);
                case 'markdown':
                    // The preview pane renders with the same parser the build
                    // uses. Safe mode on: this HTML goes straight into the DOM,
                    // and an author's raw tags must stay visible as text.
                    StudioAuth::requireSite($user, $site);
                    $body = (string) ($input['body'] ?? '');
                    if (strlen($body) > 400_000) { throw new InvalidArgumentException('Текст слишком большой для предпросмотра.'); }
                    $parser = new Parsedown();
                    $parser->setSafeMode(true);
                    response(['ok' => true, 'html' => $parser->text($body)]);
                case 'history-baseline':
                    StudioAuth::requireAdmin($user);
                    response(['ok' => true, 'recorded' => StudioHistory::baseline($user['login'])]);
                case 'preview':
                    session_write_close(); response(['ok' => true, 'url' => StudioPreview::create($user, $site, $page)]);
                case 'upload':
                    StudioAuth::requireSite($user, $site);
                    $upload = $_FILES['image'] ?? null;
                    if (!$upload || $upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > 5000000 || !is_uploaded_file($upload['tmp_name'])) { throw new InvalidArgumentException('Выберите изображение до 5 МБ.'); }
                    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
                    $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? null;
                    $dimensions = getimagesize($upload['tmp_name']);
                    if (!$ext || !$dimensions || $dimensions[0] * $dimensions[1] > 25000000) { throw new InvalidArgumentException('Поддерживаются PNG, JPEG, WebP до 25 мегапикселей.'); }
                    $dir = Network::path('static', 'images', 'content', $site);
                    if (!is_dir($dir)) { mkdir($dir, 0755, true); }
                    $name = bin2hex(random_bytes(12)) . '.' . $ext;
                    if (!move_uploaded_file($upload['tmp_name'], $dir . '/' . $name)) { throw new RuntimeException('Не удалось сохранить изображение.'); }
                    response(['ok' => true, 'url' => '/images/content/' . $site . '/' . $name]);
                default: throw new InvalidArgumentException('Неизвестное действие.');
            }
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') { throw new RuntimeException('Метод недоступен.', 405); }
        if ($action === 'session') { response(['ok' => true, 'user' => $user, 'csrf' => $_SESSION['csrf'], 'needs_setup' => PHP_SAPI === 'cli-server' && !$secure && StudioStore::transaction(fn (&$s) => $s['users'] === [])]); }
        if (!$user) { throw new RuntimeException('Войдите в Studio.', 401); }
        $site = (string) ($_GET['site'] ?? '');
        switch ($action) {
            case 'users':
                StudioAuth::requireAdmin($user);
                $users = StudioStore::transaction(fn (&$s) => array_values(array_map(static function ($u) { unset($u['password']); return $u; }, $s['users'])));
                response(['ok' => true, 'users' => $users]);
            case 'sites':
                $sites = [];
                foreach (Network::codes(false) as $id) {
                    try { StudioAuth::requireSite($user, $id); } catch (Throwable) { continue; }
                    $c = Network::geo($id);
                    $sites[] = ['id' => $id, 'title' => $c['title'], 'baseurl' => $c['baseurl'], 'identity' => $c['site_identity'], 'staging' => $c['geo']['staging'] ?? false, 'enabled' => $c['geo']['enabled'] ?? true];
                }
                response(['ok' => true, 'sites' => $sites]);
            case 'schema':
                StudioAuth::requireSite($user, $site);
                $types = Model::types($site);
                foreach ($types as $id => &$type) { $type['fields'] = Model::fields($site, $id); }
                response(['ok' => true, 'types' => $types, 'blocks' => Model::blocks(), 'products' => array_map(static fn ($p) => $p['name'], Network::products())]);
            case 'pages':
                StudioAuth::requireSite($user, $site);
                $records = [];
                foreach (\AiGf\Tools\ContentScanner::scan($site) as $p) { $records[$p['relative']] = ['page' => $p['relative'], 'title' => $p['front_matter']['title'] ?? '', 'type' => $p['front_matter']['type'] ?? 'static', 'state' => 'source', 'source_status' => $p['front_matter']['status'] ?? 'draft', 'revision' => 0,
                    // the editor links to pages by their built URL, not by file name
                    'path' => $p['path'] === '' ? '/' : '/' . trim($p['path'], '/') . '/', 'product' => $p['front_matter']['product'] ?? null]; }
                $docs = StudioStore::transaction(fn (&$s) => array_values($s['documents']));
                foreach ($docs as $doc) { if ($doc['site'] === $site) { $records[$doc['page']] = ['page' => $doc['page'], 'title' => $doc['front_matter']['title'] ?? '', 'type' => $doc['front_matter']['type'], 'state' => $doc['state'], 'revision' => $doc['revision'], 'owner' => $doc['owner']]; } }
                response(['ok' => true, 'pages' => array_values($records)]);
            case 'document': response(['ok' => true, 'document' => StudioContent::get($user, $site, (string) ($_GET['page'] ?? ''), $_GET['type'] ?? null)]);
            case 'catalog':
                response(['ok' => true, 'products' => StudioCatalog::list($user), 'markets' => StudioCatalog::markets(),
                    'redirect_base' => Network::common()['affiliate']['redirect_base'] ?? '']);
            case 'product': response(['ok' => true] + StudioCatalog::get($user, (string) ($_GET['id'] ?? '')));
            case 'seo':
                // Both views walk the same index; scoping happens inside it.
                response(['ok' => true, 'audit' => StudioInsight::audit($user, $_GET['site'] ?? null),
                    'hreflang' => StudioInsight::hreflang($user)]);
            case 'history':
                StudioAuth::requireAdmin($user);
                StudioHistory::ensure();
                response(['ok' => true, 'stats' => StudioHistory::stats(),
                    'entries' => StudioHistory::log(['path' => $_GET['path'] ?? null, 'actor' => $_GET['actor'] ?? null], 200),
                    'untracked' => count(array_filter(StudioHistory::files(), static fn ($f) => StudioHistory::versions(StudioHistory::relative($f) ?? 'content/x') === []))]);
            case 'history-version':
                StudioAuth::requireAdmin($user);
                $path = StudioHistory::assertPath((string) ($_GET['path'] ?? ''));
                $versions = StudioHistory::versions($path);
                $sha = (string) ($_GET['sha'] ?? ($versions[0]['sha'] ?? ''));
                $index = null;
                foreach ($versions as $i => $version) { if ($version['sha'] === $sha) { $index = $i; break; } }
                $body = $sha === '' ? '' : StudioHistory::read($sha);
                $previous = $index === null ? null : ($versions[$index + 1]['sha'] ?? null);
                response(['ok' => true, 'path' => $path, 'sha' => $sha, 'versions' => $versions,
                    'body' => $body, 'previous' => $previous,
                    'diff' => StudioHistory::diff($previous === null ? '' : StudioHistory::read($previous), $body),
                    'current' => is_file(Network::root() . '/' . $path) ? hash_file('sha256', Network::root() . '/' . $path) : null]);
            case 'settings':
                StudioAuth::requireAdmin($user);
                $themes = []; foreach (glob(Network::path('config', 'themes', '*.yml')) as $file) { $themes[basename($file, '.yml')] = \Symfony\Component\Yaml\Yaml::parseFile($file)['label']; }
                $models = []; foreach (glob(Network::path('config', 'models', '*.yml')) as $file) { $models[basename($file, '.yml')] = \Symfony\Component\Yaml\Yaml::parseFile($file)['label']; }
                $settings = Network::geo($site ?: 'us');
                $settings['navigation'] = Network::resolved($site ?: 'us')['navigation'];
                $settings['routes'] = Network::routes($site ?: 'us');
                $manual = $settings['redirects'] ?? [];
                $automatic = [];
                try {
                    foreach (\AiGf\Tools\Redirects::map($site ?: 'us') as $old => $new) {
                        if (!isset($manual[$old])) { $automatic[$old] = $new; }
                    }
                } catch (Throwable) { /* a broken map is reported by validation, not here */ }
                foreach (Model::types($site ?: 'us') as $type) { if (isset($type['section'])) { $settings['routes'][$type['section']] ??= $type['section']; } }
                response(['ok' => true, 'config' => $settings, 'themes' => $themes, 'models' => $models, 'redirects_auto' => $automatic]);
            case 'jobs':
                $jobs = StudioStore::transaction(fn (&$s) => array_reverse(array_values($s['jobs'])));
                $visible = [];
                foreach ($jobs as $job) {
                    try { StudioAuth::requireSite($user, $job['document']['site']); } catch (Throwable) { continue; }
                    $job['site'] = $job['document']['site']; $job['page'] = $job['document']['page']; unset($job['document']); $visible[] = $job;
                }
                response(['ok' => true, 'jobs' => $visible]);
            case 'job-log':
                $id = (string) ($_GET['id'] ?? '');
                $job = StudioStore::transaction(fn (&$s) => $s['jobs'][$id] ?? null);
                if (!$job) { throw new RuntimeException('Задание не найдено.', 404); }
                StudioAuth::requireSite($user, $job['document']['site'], true);
                $file = StudioStore::dir() . '/jobs/' . $id . '/worker.log';
                response(['ok' => true, 'log' => is_file($file) ? substr(file_get_contents($file), -60000) : 'Журнал ещё не создан.']);
            default: throw new InvalidArgumentException('Неизвестное действие.');
        }
    }
} catch (Throwable $e) {
    $status = in_array($e->getCode(), [401, 403, 404, 405, 409, 413], true) ? $e->getCode() : 422;
    response(['ok' => false, 'message' => $e->getMessage()], $status);
}
if ($path !== '/') { http_response_code(404); exit('Not found'); }
?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MiniCMS Studio</title><link rel="stylesheet" href="/app.css"><script src="/app.js" defer></script></head>
<body><a class="skip" href="#main">Перейти к содержимому</a><div id="app"><main id="main"><p>Загрузка Studio…</p></main></div><div id="notice" role="status" aria-live="polite"></div></body></html>
