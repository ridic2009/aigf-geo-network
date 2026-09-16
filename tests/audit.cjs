// Design audit against a running Studio, in both colour schemes. Reports text
// below the WCAG AA contrast ratio, controls without an accessible name,
// duplicate ids, labels pointing nowhere, overflow and missing focus rings.
//
// Not part of the suite: it needs a server and an account.
//   php scripts/studio.php serve --port=8788
//   node tests/audit.cjs http://127.0.0.1:8788 <login> <password>
const path = require('node:path');
const {chromium} = require(path.join(__dirname, '..', '.cache/browser/node_modules/playwright'));
const [url, user, pass] = process.argv.slice(2);

const probe = () => {
  const out = {contrast: [], overflow: [], noFocusRing: [], dupIds: [], unnamed: [], tiny: [], orphanLabels: [], mixedHeights: []};
  const lum = c => {
    // Chromium serialises color-mix() as color(srgb 0.96 0.97 0.98 / .88),
    // i.e. 0-1 floats rather than 0-255 channels.
    const srgb = c.startsWith('color(');
    const [r, g, b] = c.match(/[\d.]+/g).slice(0, 3).map(Number).map(v => {
      v = srgb ? v : v / 255;
      return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
  };
  const bgOf = el => {
    for (let n = el; n; n = n.parentElement) {
      const bg = getComputedStyle(n).backgroundColor;
      if (bg && !/rgba\(0, 0, 0, 0\)|transparent/.test(bg)) return bg;
    }
    return 'rgb(255, 255, 255)';
  };
  const ratio = (a, b) => {
    const [x, y] = [lum(a), lum(b)].sort((m, n) => n - m);
    return (x + 0.05) / (y + 0.05);
  };
  const path = el => el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).join('.') : '');

  for (const el of document.querySelectorAll('body *')) {
    const style = getComputedStyle(el);
    if (style.display === 'none' || style.visibility === 'hidden' || !el.offsetParent && style.position !== 'fixed') continue;
    const text = [...el.childNodes].filter(n => n.nodeType === 3).map(n => n.textContent.trim()).join('');
    if (text) {
      const size = parseFloat(style.fontSize);
      const bold = parseInt(style.fontWeight, 10) >= 700;
      const need = size >= 24 || (size >= 18.66 && bold) ? 3 : 4.5;
      const got = ratio(style.color, bgOf(el));
      if (got < need) out.contrast.push({el: path(el), text: text.slice(0, 40), size, got: +got.toFixed(2), need});
    }
    if (el.scrollWidth > el.clientWidth + 1 && style.overflowX === 'visible') out.overflow.push({el: path(el), scroll: el.scrollWidth, client: el.clientWidth});
  }

  const seen = new Set();
  for (const el of document.querySelectorAll('[id]')) {
    if (seen.has(el.id)) out.dupIds.push(el.id);
    seen.add(el.id);
  }
  for (const label of document.querySelectorAll('label[for]')) {
    if (!document.getElementById(label.htmlFor)) out.orphanLabels.push(label.htmlFor);
  }
  for (const el of document.querySelectorAll('button, a[href], input, select, textarea')) {
    if (el.disabled || !el.offsetParent) continue;
    const name = (el.getAttribute('aria-label') || el.textContent || el.title || el.labels?.[0]?.textContent || el.getAttribute('placeholder') || '').trim();
    if (!name) out.unnamed.push(path(el));
    const r = el.getBoundingClientRect();
    if (r.height && r.height < 28) out.tiny.push({el: path(el), h: Math.round(r.height)});
  }
  return out;
};

const focusRings = () => {
  const bad = [];
  const nodes = [...document.querySelectorAll('button, a[href], input, select, textarea, [role=tab]')].filter(n => n.offsetParent && !n.disabled);
  for (const el of nodes) {
    el.focus();
    const s = getComputedStyle(el);
    // A control may delegate its ring to a wrapper styled with :focus-within,
    // which is a legitimate pattern for composite editors. Walk every such
    // ancestor: the nearest one is often a bare layout div.
    let wrapped = false;
    for (let up = el.parentElement; up && !wrapped; up = up.parentElement) {
      if (!up.matches(':focus-within')) break;
      const style = getComputedStyle(up);
      wrapped = /inset|0px 0px 0px/.test(style.boxShadow) || (style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0);
    }
    const ring = (s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0) || /inset|0px 0px 0px/.test(s.boxShadow) || wrapped;
    if (!ring) bad.push(el.tagName.toLowerCase() + '.' + (typeof el.className === 'string' ? el.className.trim().split(/\s+/).join('.') : ''));
  }
  return [...new Set(bad)];
};

(async () => {
  const browser = await chromium.launch({headless: true, ...(process.env.CHROME_BINARY || process.platform === 'win32' ? {executablePath: process.env.CHROME_BINARY || 'C:/Program Files/Google/Chrome/Application/chrome.exe'} : {})});
  for (const scheme of ['light', 'dark']) {
    const context = await browser.newContext({colorScheme: scheme, viewport: {width: 1440, height: 950}});
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(url);
    if (await page.getByLabel('Логин', {exact: true}).isVisible().catch(() => false)) {
      console.log(`\n##### ${scheme} / login`);
      console.log(JSON.stringify(await page.evaluate(probe), null, 1));
      console.log('no focus ring:', JSON.stringify(await page.evaluate(focusRings)));
      await page.getByLabel('Логин', {exact: true}).fill(user);
      await page.getByLabel('Пароль', {exact: true}).fill(pass);
      await page.getByRole('button').last().click();
      await page.waitForTimeout(1200);
    }
    for (const [name, href] of [
      ['sites', '/?view=sites'],
      ['pages', '/?view=pages&site=us'],
      ['editor', '/?view=editor&site=us&page=reviews/candy-ai.md'],
      ['editor/meta', '/?view=editor&site=us&page=reviews/candy-ai.md&tab=meta'],
      ['settings', '/?view=settings&site=us'],
      ['settings/labels', '/?view=settings&site=us&tab=labels'],
      ['catalog', '/?view=catalog'],
      ['product', '/?view=product&id=candy-ai'],
      ['product/market', '/?view=product&id=candy-ai&tab=m-de'],
      ['history', '/?view=history'],
      ['jobs', '/?view=jobs'],
      ['users', '/?view=users'],
      ['new-site', '/?view=new-site'],
    ]) {
      await page.goto(url + href);
      await page.waitForTimeout(700);
      const found = await page.evaluate(probe);
      const rings = await page.evaluate(focusRings);
      const any = Object.values(found).some(v => v.length) || rings.length;
      console.log(`\n##### ${scheme} / ${name}${any ? '' : '  — clean'}`);
      for (const [k, v] of Object.entries(found)) if (v.length) console.log(' ', k, JSON.stringify(v));
      if (rings.length) console.log('  noFocusRing', JSON.stringify(rings));
    }
    if (errors.length) console.log(`\n!! ${scheme} JS errors:`, errors);
    await context.close();
  }
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
