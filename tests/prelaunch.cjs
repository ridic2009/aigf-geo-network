// Run after php scripts/build-all.php; uses the existing .cache/browser Playwright install.
// Serves build output locally with the production CSP. Never requests an offer.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const {chromium} = require('../.cache/browser/node_modules/playwright');
const root = path.resolve(__dirname, '..');
const dist = path.join(root, 'dist');
const sites = fs.readdirSync(dist).filter(name => fs.existsSync(path.join(dist, name, 'sitemap.xml')));
const csp = fs.readFileSync(path.join(root, 'scripts/nginx-config.php'), 'utf8').match(/add_header Content-Security-Policy "([^"]+)"/)[1];
const types = {'.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml', '.webp': 'image/webp'};
const server = http.createServer((req, res) => {
  const site = req.headers['x-test-site'];
  if (!sites.includes(site)) { res.writeHead(404).end(); return; }
  const dir = path.join(dist, site);
  let file = path.resolve(dir, '.' + decodeURIComponent(new URL(req.url, 'http://localhost').pathname));
  if (file !== dir && !file.startsWith(dir + path.sep)) { res.writeHead(403).end(); return; }
  if (fs.existsSync(file) && fs.statSync(file).isDirectory()) file = path.join(file, 'index.html');
  if (!fs.existsSync(file)) { res.writeHead(404).end(); return; }
  res.writeHead(200, {'Content-Type': types[path.extname(file)] || 'application/octet-stream', 'Content-Security-Policy': csp});
  fs.createReadStream(file).pipe(res);
});

(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const base = `http://127.0.0.1:${server.address().port}`;
  let browser;
  let checks = 0;
  try {
    browser = await chromium.launch({headless: true, ...(process.platform === 'win32' ? {executablePath: process.env.CHROME_BINARY || 'C:/Program Files/Google/Chrome/Application/chrome.exe'} : {})});
    for (const site of sites) {
      const context = await browser.newContext({extraHTTPHeaders: {'x-test-site': site}});
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.addInitScript(() => {
        window.cspViolations = [];
        document.addEventListener('securitypolicyviolation', event => window.cspViolations.push(event.effectiveDirective));
      });
      const sitemap = fs.readFileSync(path.join(dist, site, 'sitemap.xml'), 'utf8');
      const selected = new Map();
      for (const [, url] of sitemap.matchAll(/<loc>(.*?)<\/loc>/g)) {
        const pathname = new URL(url).pathname;
        const file = path.join(dist, site, pathname, 'index.html');
        if (!fs.existsSync(file)) continue;
        const kind = fs.readFileSync(file, 'utf8').match(/page--(ranking|comparison|review)\b/)?.[1];
        if (kind && !selected.has(kind)) selected.set(kind, pathname);
      }
      for (const [kind, pathname] of selected) {
        for (const width of [320, 390, 768, 1440]) {
          await page.setViewportSize({width, height: 900});
          await page.goto(base + pathname, {waitUntil: 'networkidle'});
          const data = await page.evaluate(() => {
            const visible = el => el.getClientRects().length > 0;
            const bounds = el => { const r = el.getBoundingClientRect(); return {left: r.left, right: r.right, height: r.height}; };
            return {
              overflow: document.documentElement.scrollWidth > innerWidth + 1,
              violations: window.cspViolations,
              summaries: [...document.querySelectorAll('.product-summaries')].some(visible),
              table: [...document.querySelectorAll('.product-overview__table')].some(visible),
              actions: [...document.querySelectorAll('.product-overview a, .sticky-cta .btn')].filter(visible).map(bounds),
              ratings: [...document.querySelectorAll('.rating__stars-fill')].filter(visible).map(el => ({actual: el.getBoundingClientRect().width / el.ownerSVGElement.getBoundingClientRect().width, expected: Number(el.getAttribute('width')) / 100})),
              disclosureFirst: !!document.querySelector('.product-hero__actions .disclosure') && !!(document.querySelector('.product-hero__actions .disclosure').compareDocumentPosition(document.querySelector('.product-hero__actions a')) & Node.DOCUMENT_POSITION_FOLLOWING),
              stickyNotice: [...document.querySelectorAll('.sticky-cta__notice')].some(visible)
            };
          });
          const label = `${site} ${kind} ${width}px`;
          assert.equal(data.overflow, false, label + ': no page overflow');
          assert.deepEqual(data.violations, [], label + ': CSP accepts rendered content');
          assert.ok(data.ratings.length && data.ratings.every(r => Math.abs(r.actual - r.expected) < .015), label + ': rating fill matches value');
          assert.ok(data.actions.every(r => r.left >= 0 && r.right <= width + 1), label + ': CTA fits viewport');
          if (kind === 'review') {
            assert.ok(data.disclosureFirst, label + ': disclosure precedes hero CTA');
            if (width < 720) {
              assert.ok(data.stickyNotice, label + ': sticky CTA discloses affiliation');
              await page.evaluate(() => scrollTo(0, document.body.scrollHeight));
              assert.ok(await page.evaluate(() => document.querySelector('.site-footer').getBoundingClientRect().bottom <= document.querySelector('.sticky-cta').getBoundingClientRect().top), label + ': footer clears sticky CTA');
            }
          } else {
            assert.equal(data.summaries, width < 720, label + ': compact summaries on mobile');
            assert.equal(data.table, width >= 720, label + ': table on desktop');
            if (width < 720) assert.ok(data.actions.length && data.actions.every(r => r.height >= 44), label + ': touch targets');
          }
          checks++;
        }
      }
      // The homepage carries the ranking: comparison table, filters, sticky bars.
      for (const width of [320, 390, 768, 1440]) {
        await page.setViewportSize({width, height: 900});
        await page.goto(base + '/', {waitUntil: 'networkidle'});
        const home = await page.evaluate(() => {
          const visible = el => !!el && el.getClientRects().length > 0;
          return {
            overflow: document.documentElement.scrollWidth > innerWidth + 1,
            violations: window.cspViolations,
            tools: visible(document.querySelector('[data-compare-tools]')),
            table: visible(document.querySelector('.compare__table')),
            cards: visible(document.querySelector('.compare__cards')),
            header: document.querySelector('.site-header').offsetHeight,
            sectionNav: visible(document.querySelector('[data-section-nav]'))
          };
        });
        const label = `${site} homepage ${width}px`;
        assert.equal(home.overflow, false, label + ': no page overflow');
        assert.deepEqual(home.violations, [], label + ': CSP accepts rendered content');
        assert.ok(home.tools, label + ': filters revealed by JavaScript');
        assert.equal(home.table, width >= 1024, label + ': table on wide screens');
        assert.equal(home.cards, width < 1024, label + ': cards on narrow screens');
        assert.ok(home.header < 80, label + ': header on one line');
        assert.ok(home.sectionNav, label + ': "on this page" bar');
        checks++;
      }
      await page.click('.chip:has(input[data-filter="nsfw"])');
      const filtered = await page.evaluate(() => {
        const rows = [...document.querySelectorAll('.compare-table tbody tr')];
        return {shown: rows.filter(r => !r.hidden).length, expected: rows.filter(r => r.dataset.nsfw === '1').length};
      });
      assert.equal(filtered.shown, filtered.expected, site + ': a filter hides the rows it excludes');

      // Site search: the index is fetched and results render under the CSP.
      const searchPage = await page.getAttribute('[data-search-toggle]', 'href');
      const word = await page.evaluate(() => document.querySelector('.compare-table__name').textContent.trim().split(/\s+/)[0]);
      await page.goto(base + searchPage + '?q=' + encodeURIComponent(word), {waitUntil: 'networkidle'});
      await page.waitForSelector('.search-result', {timeout: 5000});
      assert.deepEqual(await page.evaluate(() => window.cspViolations), [], site + ': search runs under the CSP');
      checks++;

      assert.deepEqual(errors, [], site + ': no JavaScript errors');
      await context.close();
    }
    assert.ok(checks >= 12, 'built review and comparison fixtures are required');
    console.log(`PASS ${checks} pre-launch page/viewport checks under production CSP`);
  } finally {
    if (browser) await browser.close();
    await new Promise(resolve => server.close(resolve));
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
