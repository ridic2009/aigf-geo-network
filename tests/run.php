<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use AiGf\Tools\{Network, Backup, Catalog, Content, ContentScanner, ContentValidator, Sites, Model, Theme, Redirects, Builder, Prepare, ReleaseArchive};
use Symfony\Component\Yaml\Yaml;

$count = 0;
function check(bool $ok, string $message): void { global $count; $count++; if (!$ok) { throw new RuntimeException('FAIL: ' . $message); } echo "PASS $message\n"; }
function denied(callable $fn, string $message, ?int $code = null): void {
    try { $fn(); } catch (Throwable $e) { check($code === null || $e->getCode() === $code, $message); return; }
    check(false, $message);
}
$original = Network::root();
$fixture = $original . '/var/tests/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
mkdir($fixture, 0700, true);
foreach (['config', 'content', 'data', 'engine', 'static'] as $dir) { copyTree($original . '/' . $dir, $fixture . '/' . $dir); }
putenv('MINICMS_ROOT=' . $fixture); putenv('MINICMS_BACKUP_DIR=' . $fixture . '/.backups'); Network::reset();

/** The suite works on a copy: nothing here may touch the real content tree. */
function copyTree(string $from, string $to): void
{
    if (!is_dir($from)) { return; }
    if (!is_dir($to)) { mkdir($to, 0700, true); }
    foreach (new DirectoryIterator($from) as $f) {
        if ($f->isDot()) { continue; }
        $dest = $to . '/' . $f->getFilename();
        if ($f->isDir()) { copyTree($f->getPathname(), $dest); }
        elseif (!copy($f->getPathname(), $dest)) { throw new RuntimeException('Не удалось скопировать файл.'); }
    }
}

// Any real GEO will do. Resolved from the network rather than hardcoded, so
// adding or dropping a market cannot break the suite.
$codes = Network::codes(false);
if (count($codes) < 2) { throw new RuntimeException('The suite needs at least two GEOs.'); }
[$geo] = $codes;
$geoHost  = Network::host($geo);
$geoPage  = 'content/' . $geo . '/test-page.md';
$geoAbout = 'content/' . $geo . '/about.md';

try {
    // --- a page on disk ----------------------------------------------------
    denied(fn () => Content::path($geo, '../../config/common.yml'), 'path traversal rejected');
    $draft = Content::load($geo, 'test-page.md', 'static');
    check(!$draft['exists'] && $draft['hash'] === null && $draft['front_matter']['status'] === 'draft', 'a page that does not exist yet comes back as a skeleton');

    $fm = $draft['front_matter'];
    $fm['title'] = 'Integration test page';
    $fm['slug'] = 'integration-test-page';
    $fm['seo'] = ['title' => 'Integration test page', 'description' => 'An integration test page with enough descriptive text to pass validation.'];
    $saved = Content::save($geo, 'test-page.md', $fm, 'A safe test paragraph.');
    check($saved['ok'] && is_file($fixture . '/' . $geoPage), 'a draft is written straight to the content tree');

    $bad = $fm; $bad['status'] = 'published';
    check(Content::save($geo, 'test-page.md', $bad, '<script>alert(1)</script>')['ok'] === false, 'executable content rejected');
    $thin = $fm; $thin['status'] = 'published'; $thin['seo']['description'] = '';
    $result = Content::save($geo, 'test-page.md', $thin, 'Text.');
    check(!$result['ok'] && $result['errors'][0]['field'] === 'seo.description' && str_contains($result['errors'][0]['edit_url'], '#field-seo-description'), 'publishing without SEO fails on the exact field');
    check(Content::save($geo, 'test-page.md', $fm, 'Draft text.')['ok'], 'the same page saves as a draft');

    denied(fn () => Content::save($geo, 'test-page.md', $fm, 'Text.', str_repeat('0', 64)), 'a page changed underneath is refused, not overwritten', 409);

    // A literal block dumped last carries no closing newline of its own, which
    // once glued "---" to the final answer and cost the page its front matter.
    $literal = ['title' => 'Literal block last', 'faq' => [['question' => 'Q?', 'answer' => "Line one\nline two."]]];
    file_put_contents($fixture . '/literal.md', Content::markdown($literal, 'Body.'));
    [$parsed, $parsedBody, $parseError] = ContentScanner::parse($fixture . '/literal.md');
    check($parseError === null && $parsed === $literal && trim($parsedBody) === 'Body.', 'a multi-line field written last keeps the front matter closed');

    $published = $fm; $published['status'] = 'published';
    check(Content::save($geo, 'test-page.md', $published, 'A safe test paragraph.')['ok'], 'a complete page publishes');
    [$onDisk] = ContentScanner::parse($fixture . '/' . $geoPage);
    check($onDisk['status'] === 'published', 'the status in the file is what the build reads');

    // --- products and affiliate links --------------------------------------
    $product = Catalog::save('test-product', [
        'name' => 'Test product', 'slug' => 'test-product', 'website' => 'https://example.com/',
        'rating' => 4.2, 'price' => ['amount' => 9.99, 'period' => 'month'],
        'affiliate' => ['default' => 'https://example.com/go'],
    ], true);
    check($product['product']['name'] === 'Test product' && is_file($fixture . '/data/products/test-product.yml'), 'a product is written to the catalogue');
    check(is_file($fixture . '/data/affiliates/test-product.yml'), 'its affiliate link is a separate file');
    Catalog::delete('test-product');
    check(!is_file($fixture . '/data/products/test-product.yml'), 'an unused product can be removed');
    $used = array_key_first(array_filter(Catalog::usage(), static fn ($pages) => $pages !== []));
    if ($used !== null) { denied(fn () => Catalog::delete($used), 'a product a page still points at cannot be deleted', 409); }

    // --- a second site, from configuration only ----------------------------
    $created = Sites::save('other-us', ['from' => $geo, 'title' => 'Other brand', 'baseurl' => 'https://other.example.com/', 'brand' => 'other', 'market' => $geo, 'language' => 'en', 'locale' => 'en_US', 'hreflang' => 'en-US', 'translation_group' => 'other', 'theme' => 'editorial', 'model' => 'reviews'], true);
    check($created['site_identity']['id'] === 'other-us' && $created['geo']['code'] === $geo, 'two brands share market without sharing site identity');
    check($created['pages']['dir'] === 'content/other-us' && is_file($fixture . '/content/other-us/index.md'), 'site scaffold has independent content tree');
    check(str_contains(Theme::css('other-us'), '#285d42'), 'theme selected per site');
    denied(fn () => Sites::save('other-us', ['baseurl' => Network::baseUrl($geo)]), 'duplicate hostname rejected');
    denied(fn () => Sites::save('other-us', ['baseurl' => 'https://user@example.com/']), 'URL credentials rejected');
    check(Model::validateBlocks([['type' => 'cta', 'label' => 'Open', 'url' => 'javascript:alert(1)']]) !== [], 'unsafe CTA URL rejected');
    check(Model::validateBlocks([['type' => 'image', 'src' => '/images/no.png', 'alt' => 'Image']]) !== [], 'missing image caught before build');
    Sites::save('other-us', ['model' => 'services']);
    check(isset(Model::types('other-us')['service']), 'a second thematic model needs no PHP change');
    check(Model::validateFields(['score' => 11], Model::fields('other-us', 'service'), '', false) !== [], 'model-defined numeric bounds validated');
    Sites::save('other-us', ['baseurl' => 'http://other.example.com/']);
    $https = Sites::save('other-us', ['baseurl' => 'https://other.example.com/']);
    check($https['previous_domains'] === [], 'HTTPS upgrade does not create a redirect to the same hostname');
    Sites::save('other-us', ['baseurl' => 'https://moved.example.com/']);
    $back = Sites::save('other-us', ['baseurl' => 'https://other.example.com/']);
    check($back['previous_domains'] === ['moved.example.com'], 'moving back removes current hostname from old-domain redirects');

    // --- moving a whole section --------------------------------------------
    $homePath = $fixture . '/content/other-us/index.md'; [$homeFm] = ContentScanner::parse($homePath); $homeFm['status'] = 'published';
    $homeFm['blocks'] = [['type' => 'cta', 'label' => 'See service', 'url' => '/services/consulting/?ref=home']];
    file_put_contents($homePath, Content::markdown($homeFm, '[Service](/services/consulting/) and [All services](/services/)'));
    mkdir($fixture . '/content/other-us/services');
    $servicePath = $fixture . '/content/other-us/services/consulting.md';
    $serviceFm = ['title' => 'Consulting', 'slug' => 'consulting', 'type' => 'service', 'status' => 'published', 'provider' => 'Example provider', 'score' => 8.5, 'tested_on' => '2026-09-15', 'seo' => ['title' => 'Consulting', 'description' => 'Independent assessment of this consulting service and its results.']];
    file_put_contents($servicePath, Content::markdown($serviceFm, 'A service assessment.'));
    Sites::save('other-us', ['routes' => ['services' => 'expertise'], 'navigation' => ['main' => [['id' => 'services/consulting', 'text' => 'Consulting help']]], 'ui' => ['labels' => ['home' => 'Start']]]);
    $map = Redirects::map('other-us');
    check(($map['/services/consulting/'] ?? null) === '/expertise/consulting/' && ($map['/services/'] ?? null) === '/expertise/', 'route migration covers pages and generated section indexes');
    check(str_contains(file_get_contents($homePath), '](/expertise/consulting/)') && str_contains(file_get_contents($homePath), '](/expertise/)'), 'route migration rewrites internal Markdown destinations');
    [$movedHome] = ContentScanner::parse($homePath);
    check($movedHome['blocks'][0]['url'] === '/expertise/consulting/?ref=home', 'route migration rewrites block URLs and preserves query parameters');
    $serviceSource = file_get_contents($servicePath);
    denied(fn () => Sites::save('other-us', ['routes' => ['services' => 'services/consulting']]), 'conflicting route migration rejected');
    check(Network::geo('other-us')['routes']['services'] === 'expertise' && file_get_contents($servicePath) === $serviceSource, 'failed route migration restores configuration and content together');

    // --- the build the publisher runs --------------------------------------
    Prepare::run(['other-us']); $serviceBuild = Builder::build('other-us', ['quiet' => true]);
    check($serviceBuild['ok'], 'alternate content model builds real HTML');
    $html = file_get_contents($fixture . '/dist/other.example.com/expertise/consulting/index.html');
    check(str_contains($html, 'Example provider') && str_contains($html, '2026-09-15') && str_contains($html, 'Consulting help'), 'configured custom fields, dates and navigation render');
    check(is_file($fixture . '/dist/other.example.com/' . Theme::asset('other-us')), 'selected theme asset exists in output');
    $build = Builder::build($geo, ['quiet' => true]);
    check($build['ok'] && is_file($fixture . '/dist/' . $geoHost . '/integration-test-page/index.html'), 'a published page reaches the output');
    check((new ContentValidator())->validate($geo)['errors'] === [], 'the content of the fixture network validates');

    // --- redirects ----------------------------------------------------------
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

    // --- release archives ---------------------------------------------------
    $releaseDir = $fixture . '/.backups/release';
    mkdir($releaseDir, 0700, true);
    $zip = new ZipArchive();
    $archive = $releaseDir . '/release.zip';
    $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('content/' . $geo . '/about.md', file_get_contents($fixture . '/' . $geoAbout));
    $zip->addFromString('manifest.json', json_encode(['files' => ['content/' . $geo . '/about.md' => hash_file('sha256', $fixture . '/' . $geoAbout)]]));
    $zip->close();
    $recovered = ReleaseArchive::read($archive, true);
    check(is_file($recovered['directory'] . '/content/' . $geo . '/about.md'), 'a verified release can be recovered without overwriting sources');
    $tampered = $releaseDir . '/tampered.zip'; copy($archive, $tampered);
    $zip->open($tampered); $zip->addFromString('content/' . $geo . '/about.md', 'corrupted'); $zip->close();
    denied(fn () => ReleaseArchive::read($tampered), 'corrupted archive fails checksum verification');
    $unsafe = $releaseDir . '/unsafe.zip';
    $zip->open($unsafe, ZipArchive::CREATE); $zip->addFromString('content/../../escape.txt', 'bad');
    $zip->addFromString('manifest.json', json_encode(['files' => ['content/../../escape.txt' => hash('sha256', 'bad')]])); $zip->close();
    denied(fn () => ReleaseArchive::read($unsafe, true), 'archive traversal rejected before extraction');

    // --- encrypted off-site backup -----------------------------------------
    putenv('MINICMS_BACKUP_KEY=' . base64_encode(str_repeat("\x01", 32)));
    $backup = Backup::create();
    check($backup['files'] > 10 && is_file($backup['file']), 'backup archives the sources');
    $read = Backup::restore($backup['file']);
    check($read['files'] === $backup['files'], 'backup verifies every checksum');
    check(count(array_filter(array_keys($read['manifest']['files']), static fn ($p) => str_starts_with($p, 'static/'))) > 0, 'backup includes the images git alone would not protect');
    $raw = file_get_contents($backup['file']);
    check(!str_contains($raw, 'Integration test page') && str_starts_with($raw, 'MCMSBK1'), 'the archive is encrypted at rest');
    file_put_contents($backup['file'] . '.cut', substr($raw, 0, strlen($raw) - 64));
    denied(fn () => Backup::restore($backup['file'] . '.cut'), 'a truncated archive is refused');
    $flip = $raw; $at = intdiv(strlen($flip), 2); $flip[$at] = $flip[$at] === 'A' ? 'B' : 'A';
    file_put_contents($backup['file'] . '.bad', $flip);
    denied(fn () => Backup::restore($backup['file'] . '.bad'), 'a tampered archive is refused');
    putenv('MINICMS_BACKUP_KEY=' . base64_encode(str_repeat("\x02", 32)));
    denied(fn () => Backup::restore($backup['file']), 'the wrong key cannot read an archive');
    putenv('MINICMS_BACKUP_KEY');

    check(is_file($original . '/' . $geoAbout) && !is_file($original . '/' . $geoPage), 'all tests isolated from user content');
    echo "\n$count checks passed. Fixture: $fixture\n";
} finally { putenv('MINICMS_ROOT'); putenv('MINICMS_BACKUP_DIR'); Network::reset(); }
