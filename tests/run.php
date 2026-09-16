<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use AiGf\Tools\{Network, StudioStore, StudioAuth, StudioBackup, StudioContent, StudioHistory, StudioSites, StudioWorker, StudioPreview, Model, Theme, Redirects, Builder, Prepare, ContentScanner, ReleaseArchive};
use Symfony\Component\Yaml\Yaml;

$count = 0;
function check(bool $ok, string $message): void { global $count; $count++; if (!$ok) { throw new RuntimeException('FAIL: ' . $message); } echo "PASS $message\n"; }
function denied(callable $fn, string $message, ?int $code = null): void {
    try { $fn(); } catch (Throwable $e) { check($code === null || $e->getCode() === $code, $message); return; }
    check(false, $message);
}
$original = Network::root();
$fixture = $original . '/.studio/tests/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
mkdir($fixture, 0700, true);
foreach (['config', 'content', 'data', 'engine', 'static'] as $dir) { StudioWorker::copyTree($original . '/' . $dir, $fixture . '/' . $dir); }
putenv('MINICMS_ROOT=' . $fixture); putenv('STUDIO_DATA=' . $fixture . '/.studio'); Network::reset();
// Any real GEO will do. Resolved from the network rather than hardcoded, so
// adding or dropping a market cannot break the suite. The scope test needs a
// second, different GEO: the author is granted $geo and must be refused $otherGeo.
$codes = Network::codes(false);
if (count($codes) < 2) { throw new RuntimeException('The suite needs at least two GEOs.'); }
[$geo, $otherGeo] = $codes;
$geoHost  = Network::host($geo);
$geoPage  = 'content/' . $geo . '/studio-test.md';
$geoAbout = 'content/' . $geo . '/about.md';
try {
    StudioAuth::create('writer', 'Integration-only-123!', 'author', [$geo]);
    StudioAuth::create('second', 'Integration-only-123!', 'author', [$geo]);
    StudioAuth::create('editor', 'Integration-only-123!', 'editor', [$geo]);
    StudioAuth::create('admin', 'Integration-only-123!', 'admin', ['*']);
    $author = StudioAuth::user('writer'); $editor = StudioAuth::user('editor'); $admin = StudioAuth::user('admin');
    check(StudioAuth::login('writer', 'wrong', 'test') === null, 'wrong password rejected');
    check(StudioAuth::login('writer', 'Integration-only-123!', 'test')['role'] === 'author', 'password hash authentication');
    denied(fn () => StudioContent::get($author, $otherGeo, 'about.md'), 'site scope enforced', 403);
    denied(fn () => StudioContent::get($author, $geo, '../../config/common.yml'), 'path traversal rejected');
    denied(fn () => StudioSites::save($author, $geo, []), 'authors cannot change site configuration', 403);
    $doc = StudioContent::get($author, $geo, 'studio-test.md');
    $fm = $doc['front_matter'];
    $fm['title'] = 'Studio integration test'; $fm['status'] = 'published'; $fm['layout'] = '/etc/passwd';
    $fm['seo'] = ['title' => 'Studio integration test', 'description' => 'An integration test page with enough descriptive text to pass validation.'];
    $payload = ['revision' => 0, 'front_matter' => $fm, 'body' => 'A safe test paragraph.'];
    $saved = StudioContent::save($author, $geo, 'studio-test.md', $payload);
    check($saved['ok'], 'author saves structured draft');
    $doc = $saved['document'];
    check($doc['state'] === 'draft' && $doc['front_matter']['status'] === 'draft' && !isset($doc['front_matter']['layout']), 'status and executable template injection ignored');
    check(!is_file($fixture . '/' . $geoPage), 'draft does not change published source tree');
    denied(fn () => StudioContent::save($author, $geo, 'studio-test.md', $payload), 'stale revision rejected', 409);
    denied(fn () => StudioContent::save(StudioAuth::user('second'), $geo, 'studio-test.md', array_replace($payload, ['revision' => 1])), 'author cannot edit another author draft', 403);
    denied(fn () => StudioContent::transition($author, $geo, 'studio-test.md', 'approve', 1), 'author cannot approve via direct API service call', 403);
    $bad = $payload; $bad['revision'] = 1; $bad['body'] = '<script>alert(1)</script>';
    check(!StudioContent::save($author, $geo, 'studio-test.md', $bad)['ok'], 'executable content rejected');
    $missing = $payload; $missing['revision'] = 1; $missing['front_matter']['seo']['description'] = '';
    check(StudioContent::save($author, $geo, 'studio-test.md', $missing)['ok'], 'incomplete SEO can be saved as draft');
    $result = StudioContent::transition($author, $geo, 'studio-test.md', 'submit', 2);
    check(!$result['ok'] && $result['errors'][0]['field'] === 'seo.description' && str_contains($result['errors'][0]['edit_url'], '#field-seo-description'), 'submission error links to exact field');
    $payload['revision'] = 2; StudioContent::save($author, $geo, 'studio-test.md', $payload);
    StudioContent::transition($author, $geo, 'studio-test.md', 'submit', 3);
    check(StudioContent::transition($editor, $geo, 'studio-test.md', 'approve', 3)['document']['state'] === 'approved', 'editor approves specific revision');
    StudioContent::transition($editor, $geo, 'studio-test.md', 'publish', 3);
    denied(fn () => StudioContent::transition($editor, $geo, 'studio-test.md', 'publish', 3), 'duplicate publication queue entry rejected', 409);
    $job = StudioWorker::once(false);
    if (($job['state'] ?? '') !== 'built') { echo json_encode($job['errors'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"; }
    check($job['state'] === 'built', 'worker builds real isolated Cecil release');
    $after = StudioContent::get($author, $geo, 'studio-test.md');
    check($after['state'] === 'approved' && $after['published_revision'] === null, 'build-only never claims publication');
    check(!is_file($fixture . '/' . $geoPage), 'build-only leaves source tree untouched');
    $zip = new ZipArchive(); $zip->open(StudioStore::dir() . '/jobs/' . $job['id'] . '/release.zip');
    $manifest = json_decode($zip->getFromName('manifest.json'), true);
    check(isset($manifest['files']['dist/' . $geoHost . '/studio-test/index.html']), 'release archive contains manifest and generated page');
    $zip->close();
    $archivePath = StudioStore::dir() . '/jobs/' . $job['id'] . '/release.zip';
    $recovered = ReleaseArchive::read($archivePath, true);
    check(is_file($recovered['directory'] . '/dist/' . $geoHost . '/studio-test/index.html'), 'verified release can be recovered without overwriting sources');
    $tampered = StudioStore::dir() . '/tampered.zip'; copy($archivePath, $tampered);
    $zip->open($tampered); $zip->addFromString($geoPage, 'corrupted'); $zip->close();
    denied(fn () => ReleaseArchive::read($tampered), 'corrupted archive fails checksum verification');
    $unsafeZip = StudioStore::dir() . '/unsafe.zip';
    $zip->open($unsafeZip, ZipArchive::CREATE); $zip->addFromString('content/../../escape.txt', 'bad');
    $zip->addFromString('manifest.json', json_encode(['files' => ['content/../../escape.txt' => hash('sha256', 'bad')]])); $zip->close();
    denied(fn () => ReleaseArchive::read($unsafeZip, true), 'archive traversal rejected before extraction');
    $preview = StudioPreview::create($author, $geo, 'studio-test.md');
    check(str_starts_with($preview, '/preview/') && str_ends_with($preview, '/studio-test/'), 'authenticated full-template preview generated');
    $payload['revision'] = 3; $doc = StudioContent::save($author, $geo, 'studio-test.md', $payload)['document'];
    check($doc['state'] === 'draft' && !isset($doc['approved_revision']), 'editing invalidates approval');
    denied(fn () => StudioContent::transition($editor, $geo, 'studio-test.md', 'publish', 4), 'unapproved revised draft cannot publish', 409);
    StudioContent::transition($author, $geo, 'studio-test.md', 'submit', 4); StudioContent::transition($editor, $geo, 'studio-test.md', 'approve', 4); StudioContent::transition($editor, $geo, 'studio-test.md', 'publish', 4);
    // Change after queueing: worker must cancel the old approved revision.
    $payload['revision'] = 4; StudioContent::save($author, $geo, 'studio-test.md', $payload);
    check(StudioWorker::once(false) === null, 'queued stale approval is cancelled');
    StudioContent::transition($author, $geo, 'studio-test.md', 'submit', 5); StudioContent::transition($editor, $geo, 'studio-test.md', 'approve', 5); StudioContent::transition($editor, $geo, 'studio-test.md', 'publish', 5);
    StudioAuth::update($admin, 'editor', 'author', [$geo], true);
    check(StudioWorker::once(false) === null, 'revoked publishing authority cancels pending release');
    StudioAuth::update($admin, 'editor', 'editor', [$geo], true);
    StudioContent::transition($editor, $geo, 'studio-test.md', 'publish', 5);
    file_put_contents($fixture . '/' . $geoPage, 'External change');
    $failed = StudioWorker::once(false);
    check($failed['state'] === 'publication_failed' && str_contains($failed['errors'][0]['message'], 'вне Studio'), 'source conflict becomes a readable publication failure');
    check(file_get_contents($fixture . '/' . $geoPage) === 'External change', 'failed publication preserves external source');
    unlink($fixture . '/' . $geoPage);
    StudioStore::transaction(function (&$state) use ($failed) { $state['jobs'][$failed['id']]['state'] = 'publishing'; $state['documents'][$failed['key']]['state'] = 'publishing'; });
    StudioWorker::once(false);
    check(StudioContent::get($author, $geo, 'studio-test.md')['state'] === 'publication_failed', 'next worker records interrupted publication as failed');
    denied(fn () => StudioAuth::update($admin, 'admin', 'author', [$geo], true), 'last active admin cannot be demoted');
    $created = StudioSites::save($admin, 'other-us', ['from' => $geo, 'title' => 'Other brand', 'baseurl' => 'https://other.example.com/', 'brand' => 'other', 'market' => $geo, 'language' => 'en', 'locale' => 'en_US', 'hreflang' => 'en-US', 'translation_group' => 'other', 'theme' => 'editorial', 'model' => 'reviews'], true);
    check($created['site_identity']['id'] === 'other-us' && $created['geo']['code'] === $geo, 'two brands share market without sharing site identity');
    check($created['pages']['dir'] === 'content/other-us' && is_file($fixture . '/content/other-us/index.md'), 'site scaffold has independent content tree');
    check(str_contains(Theme::css('other-us'), '#285d42'), 'theme selected per site');
    denied(fn () => StudioSites::save($admin, 'other-us', ['baseurl' => Network::baseUrl($geo)]), 'duplicate hostname rejected');
    denied(fn () => StudioSites::save($admin, 'other-us', ['baseurl' => 'https://user@example.com/']), 'URL credentials rejected');
    check(Model::validateBlocks([['type' => 'cta', 'label' => 'Open', 'url' => 'javascript:alert(1)']]) !== [], 'unsafe CTA URL rejected');
    check(Model::validateBlocks([['type' => 'image', 'src' => '/images/no.png', 'alt' => 'Image']]) !== [], 'missing image caught before build');
    StudioSites::save($admin, 'other-us', ['model' => 'services']);
    check(isset(Model::types('other-us')['service']), 'a second thematic model needs no PHP change');
    check(Model::validateFields(['score' => 11], Model::fields('other-us', 'service'), '', false) !== [], 'model-defined numeric bounds validated');
    StudioSites::save($admin, 'other-us', ['baseurl' => 'http://other.example.com/']);
    $https = StudioSites::save($admin, 'other-us', ['baseurl' => 'https://other.example.com/']);
    check($https['previous_domains'] === [], 'HTTPS upgrade does not create a redirect to the same hostname');
    StudioSites::save($admin, 'other-us', ['baseurl' => 'https://moved.example.com/']);
    $back = StudioSites::save($admin, 'other-us', ['baseurl' => 'https://other.example.com/']);
    check($back['previous_domains'] === ['moved.example.com'], 'moving back removes current hostname from old-domain redirects');
    $homePath = $fixture . '/content/other-us/index.md'; [$homeFm] = ContentScanner::parse($homePath); $homeFm['status'] = 'published';
    $homeFm['blocks'] = [['type' => 'cta', 'label' => 'See service', 'url' => '/services/consulting/?ref=home']];
    file_put_contents($homePath, StudioContent::markdown($homeFm, '[Service](/services/consulting/) and [All services](/services/)'));
    mkdir($fixture . '/content/other-us/services');
    $servicePath = $fixture . '/content/other-us/services/consulting.md';
    $serviceFm = ['title' => 'Consulting', 'slug' => 'consulting', 'type' => 'service', 'status' => 'published', 'provider' => 'Example provider', 'score' => 8.5, 'tested_on' => '2026-09-15', 'seo' => ['title' => 'Consulting', 'description' => 'Independent assessment of this consulting service and its results.']];
    file_put_contents($servicePath, StudioContent::markdown($serviceFm, 'A service assessment.'));
    $pending = StudioContent::get($admin, 'other-us', 'services/consulting.md');
    StudioContent::save($admin, 'other-us', 'services/consulting.md', ['revision' => 0, 'front_matter' => $serviceFm, 'body' => 'Working revision']);
    StudioContent::transition($admin, 'other-us', 'services/consulting.md', 'submit', 1); StudioContent::transition($admin, 'other-us', 'services/consulting.md', 'approve', 1);
    StudioSites::save($admin, 'other-us', ['routes' => ['services' => 'expertise'], 'navigation' => ['main' => [['id' => 'services/consulting', 'text' => 'Consulting help']]], 'ui' => ['labels' => ['home' => 'Start']]]);
    $map = Redirects::map('other-us');
    check(($map['/services/consulting/'] ?? null) === '/expertise/consulting/' && ($map['/services/'] ?? null) === '/expertise/', 'route migration covers pages and generated section indexes');
    check(str_contains(file_get_contents($homePath), '](/expertise/consulting/)') && str_contains(file_get_contents($homePath), '](/expertise/)'), 'route migration rewrites internal Markdown destinations');
    [$movedHome] = ContentScanner::parse($homePath);
    check($movedHome['blocks'][0]['url'] === '/expertise/consulting/?ref=home', 'route migration rewrites block URLs and preserves query parameters');
    $migrated = StudioContent::get($admin, 'other-us', 'services/consulting.md');
    check($migrated['state'] === 'draft' && $migrated['revision'] === 2 && $migrated['base_hash'] === hash_file('sha256', $servicePath), 'route migration invalidates approval and updates source baseline');
    $serviceSource = file_get_contents($servicePath);
    denied(fn () => StudioSites::save($admin, 'other-us', ['routes' => ['services' => 'services/consulting']]), 'conflicting route migration rejected');
    check(Network::geo('other-us')['routes']['services'] === 'expertise' && file_get_contents($servicePath) === $serviceSource, 'failed route migration restores configuration and content together');
    Prepare::run(['other-us']); $serviceBuild = Builder::build('other-us', ['quiet' => true]);
    check($serviceBuild['ok'], 'alternate content model builds real HTML');
    $html = file_get_contents($fixture . '/dist/other.example.com/expertise/consulting/index.html');
    check(str_contains($html, 'Example provider') && str_contains($html, '2026-09-15') && str_contains($html, 'Consulting help'), 'configured custom fields, dates and navigation render');
    check(is_file($fixture . '/dist/other.example.com/' . Theme::asset('other-us')), 'selected theme asset exists in output');
    // The redirect target must be a page that really exists in this GEO, and the
    // "about" slug is localized (/about/ in English, /a-propos/ in French).
    $aboutUrl = null;
    foreach (ContentScanner::scan($geo) as $scanned) {
        if ($scanned['relative'] === 'about.md') { $aboutUrl = '/' . trim($scanned['path'], '/') . '/'; break; }
    }
    if ($aboutUrl === null) { throw new RuntimeException('No about.md in ' . $geo . ' to redirect to.'); }
    $config = Network::geo($geo); $config['redirects'] = ['/old/' => '/middle/', '/middle/' => $aboutUrl]; unset($config['site_identity']);
    file_put_contents(Network::configFile($geo), Yaml::dump($config, 20, 2)); Network::reset();
    check(Redirects::validate($geo) === [], 'valid redirect chains accepted');
    $dist = $fixture . '/dist/redirect-test'; mkdir($dist, 0755, true); Redirects::write($geo, $dist);
    check(str_contains(file_get_contents($dist . '/redirects.nginx.conf'), 'location = /old/ { return 301 ' . $aboutUrl . '$is_args$args; }'), 'redirect chains collapse and preserve query string');
    $config['redirects'] = ['/a/' => '/b/', '/b/' => '/a/']; file_put_contents(Network::configFile($geo), Yaml::dump($config, 20, 2)); Network::reset();
    check(Redirects::validate($geo) !== [], 'redirect cycles rejected');
    denied(fn () => Builder::removeDirectory($fixture . '/content'), 'build cannot recursively delete source directories');
    // --- version history: what Git used to give us -------------------------
    $tracked = $fixture . '/' . $geoAbout;
    StudioHistory::ensure();
    $first = StudioHistory::versions($geoAbout);
    check(count($first) === 1 && $first[0]['action'] === 'import', 'an existing workspace is baselined into the history');
    $before = file_get_contents($tracked);
    file_put_contents($tracked, $before . "\nA line added outside Studio.\n");
    check(StudioHistory::record($tracked, 'admin', 'publish', 'test') !== null, 'a changed file gets a new version');
    check(StudioHistory::record($tracked, 'admin', 'publish', 'test') === null, 'an unchanged file is not versioned twice');
    $versions = StudioHistory::versions($geoAbout);
    check(count($versions) === 2 && $versions[0]['prev'] === $versions[1]['sha'], 'versions form a chain');
    $diff = StudioHistory::diff(StudioHistory::read($versions[1]['sha']), StudioHistory::read($versions[0]['sha']));
    $added = array_filter($diff, static fn ($l) => $l['op'] === '+');
    $removed = array_filter($diff, static fn ($l) => $l['op'] === '-');
    check($removed === [] && count(array_filter($added, static fn ($l) => $l['text'] === 'A line added outside Studio.')) === 1,
        'diff shows the added line and reports nothing removed');
    StudioStore::transaction(function (&$s) use ($admin, $versions, $geoAbout) {
        StudioHistory::restore($admin, $geoAbout, $versions[1]['sha'], $s);
    });
    check(file_get_contents($tracked) === $before, 'restoring an old version puts the bytes back');
    check(count(StudioHistory::versions($geoAbout)) === 3, 'a restore is itself recorded');
    denied(fn () => StudioStore::transaction(fn (&$s) => StudioHistory::restore($author, $geoAbout, $versions[1]['sha'], $s)), 'authors cannot restore versions', 403);
    denied(fn () => StudioHistory::assertPath('../../etc/passwd'), 'history rejects paths outside the workspace');
    denied(fn () => StudioHistory::assertPath('vendor/autoload.php'), 'history only tracks editable sources');
    denied(fn () => StudioHistory::read(str_repeat('f', 64)), 'a missing version is reported, not invented', 404);

    // --- encrypted off-site backup -----------------------------------------
    putenv('MINICMS_BACKUP_KEY=' . base64_encode(str_repeat("\x01", 32)));
    $backup = StudioBackup::create($fixture . '/.studio/backups');
    check($backup['files'] > 10 && is_file($backup['file']), 'backup archives the workspace');
    $read = StudioBackup::restore($backup['file']);
    check($read['files'] === $backup['files'], 'backup verifies every checksum');
    check(in_array('studio/state.json', array_keys($read['manifest']['files']), true), 'backup includes accounts, drafts and the queue');
    check(count(array_filter(array_keys($read['manifest']['files']), static fn ($p) => str_starts_with($p, 'studio/history/'))) > 0, 'backup includes the version history');
    $raw = file_get_contents($backup['file']);
    check(!str_contains($raw, 'Studio integration test') && str_starts_with($raw, 'MCMSBK1'), 'the archive is encrypted at rest');
    file_put_contents($backup['file'] . '.cut', substr($raw, 0, strlen($raw) - 64));
    denied(fn () => StudioBackup::restore($backup['file'] . '.cut'), 'a truncated archive is refused');
    $flip = $raw; $at = intdiv(strlen($flip), 2); $flip[$at] = $flip[$at] === 'A' ? 'B' : 'A';
    file_put_contents($backup['file'] . '.bad', $flip);
    denied(fn () => StudioBackup::restore($backup['file'] . '.bad'), 'a tampered archive is refused');
    putenv('MINICMS_BACKUP_KEY=' . base64_encode(str_repeat("\x02", 32)));
    denied(fn () => StudioBackup::restore($backup['file']), 'the wrong key cannot read an archive');
    putenv('MINICMS_BACKUP_KEY');

    check(is_file($original . '/' . $geoAbout) && !is_file($original . '/' . $geoPage), 'all tests isolated from user content');
    echo "\n$count checks passed. Fixture: $fixture\n";
} finally { putenv('MINICMS_ROOT'); putenv('STUDIO_DATA'); Network::reset(); }
