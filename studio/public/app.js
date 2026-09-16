'use strict';
const app = document.querySelector('#app');
const labels = {draft:'Черновик', review:'На проверке', approved:'Одобрено', publishing:'Публикуется', published:'Опубликовано', publication_failed:'Ошибка публикации', queued:'В очереди', built:'Собрано — без деплоя', source:'Исходник', cancelled:'Отменено', staging:'Тестовый', production:'Production'};
const roles = {admin:'Администратор', editor:'Выпускающий редактор', author:'Автор'};
let session, sites = [], currentDocument, dirty = false;

const h = (tag, attrs = {}, ...children) => {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs)) {
    if (key.startsWith('on')) node.addEventListener(key.slice(2).toLowerCase(), value);
    else if (key === 'class') node.className = value;
    else if (key === 'value') node.value = value;
    else if (value !== false && value != null) node.setAttribute(key, value === true ? '' : String(value));
  }
  for (const child of children.flat(Infinity)) if (child != null) node.append(child instanceof Node ? child : document.createTextNode(String(child)));
  return node;
};

/* Inline SVG: the CSP forbids remote assets, and an icon font would be a
   heavier dependency than twenty path strings. */
const paths = {
  logo:'M12 2.5 21 7v10l-9 4.5L3 17V7zM12 7.5 7.5 10v4l4.5 2.5 4.5-2.5v-4z',
  sites:'M3 5.5h18M3 12h18M3 18.5h18',
  grid:'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
  doc:'M6 3h8l4 4v14H6zM14 3v4h4',
  rocket:'M13.5 3c3.5 1 6 4.5 5.5 8.5l-4 4-5-5 4-4zM9 14l-3 3M5.5 12.5 3 15l3 3 2.5-2.5',
  plus:'M12 5v14M5 12h14',
  users:'M16 19v-1.5a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4V19M9 9.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7M22 19v-1.5a4 4 0 0 0-3-3.87M16 2.63a4 4 0 0 1 0 7.75',
  gear:'M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 7.1 19.4l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.6 1.6 0 0 0 3 14.9H3a2 2 0 1 1 0-4h.1A1.6 1.6 0 0 0 4.6 7.1l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 2.7 1.1l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z',
  search:'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14M20 20l-4-4',
  chevron:'M9 6l6 6-6 6',
  up:'M12 19V5M5 12l7-7 7 7',
  down:'M12 5v14M19 12l-7 7-7-7',
  trash:'M4 7h16M10 11v6M14 11v6M5 7l1 13h12l1-13M9 7V4h6v3',
  eye:'M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6',
  check:'M20 6 9 17l-5-5',
  alert:'M12 8v5M12 16.5v.5M10.3 3.9 1.8 18.5A2 2 0 0 0 3.5 21.5h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0',
  info:'M12 16v-5M12 8.5V8M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18',
  logout:'M15 17l5-5-5-5M20 12H9M12 20H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h6',
  menu:'M4 7h16M4 12h16M4 17h16',
  inbox:'M3 13h5l1.5 3h5L16 13h5M3 13l3-8h12l3 8v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z',
  back:'M19 12H5M12 19l-7-7 7-7',
  close:'M6 6l12 12M18 6 6 18',
  tag:'M20.5 12.5 12 21 3 12V3h9zM7.5 7.5h.01',
  history:'M3 12a9 9 0 1 0 2.6-6.4M3 4v4h4M12 7.5V12l3 2',
  chart:'M4 20V10M10 20V4M16 20v-7M22 20H2',
  palette:'M12 21a9 9 0 1 1 9-9c0 1.7-1.3 3-3 3h-1.5a2 2 0 0 0-1.4 3.4A2 2 0 0 1 13.7 21zM7.5 10.5h.01M12 7.5h.01M16.5 10.5h.01',
  chain:'M10.5 13.5a4.5 4.5 0 0 0 6.6.4l2.4-2.4a4.5 4.5 0 0 0-6.4-6.4l-1.4 1.4M13.5 10.5a4.5 4.5 0 0 0-6.6-.4l-2.4 2.4a4.5 4.5 0 0 0 6.4 6.4l1.4-1.4',
};
function icon(name) {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('fill', 'none');
  svg.setAttribute('stroke', 'currentColor');
  svg.setAttribute('stroke-width', '1.7');
  svg.setAttribute('stroke-linecap', 'round');
  svg.setAttribute('stroke-linejoin', 'round');
  svg.setAttribute('aria-hidden', 'true');
  const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
  path.setAttribute('d', paths[name] || '');
  svg.append(path);
  return svg;
}

const button = (text, fn, kind = '') => h('button', {type:'button', class:kind, onclick:fn}, text);
const iconButton = (name, title, fn, kind = 'btn--icon') => h('button', {type:'button', class:kind, title, 'aria-label':title, onclick:fn}, icon(name));
const badge = state => h('span', {class:'badge state-' + state}, labels[state] || state);
const monogram = (text, size = 2) => (text || '?').replace(/[^\p{L}\p{N}]/gu, '').slice(0, size).toUpperCase();

/* Toasts replace the old single-line notice: messages stack, say what kind of
   event they are, and get out of the way on their own. */
function notice(text, kind = 'info') {
  const host = document.querySelector('#notice');
  if (!host) return;
  host.className = 'toasts';
  const toast = h('div', {class:'toast toast--' + kind},
    icon(kind === 'ok' ? 'check' : kind === 'error' ? 'alert' : 'info'),
    h('div', {}, text),
    iconButton('close', 'Закрыть', () => toast.remove(), 'btn--icon btn--sm'));
  host.append(toast);
  setTimeout(() => toast.remove(), kind === 'error' ? 9000 : 4500);
}
const fail = text => notice(text, 'error');
const done = text => notice(text, 'ok');

const params = () => new URLSearchParams(location.search);

function ago(value) {
  const seconds = Math.round((Date.now() - new Date(value).getTime()) / 1000);
  const steps = [[60, 'сек'], [3600, 'мин'], [86400, 'ч'], [604800, 'дн']];
  if (seconds < 45) return 'только что';
  for (let i = 0; i < steps.length; i++) {
    const [limit, unit] = steps[i];
    if (seconds < limit) return Math.round(seconds / (i ? steps[i - 1][0] : 1)) + ' ' + unit + ' назад';
  }
  return new Date(value).toLocaleDateString('ru', {day:'numeric', month:'short'});
}

/** Brings a field into view even when it sits on a closed tab or group. */
function revealField(target) {
  for (let parent = target?.parentElement; parent; parent = parent.parentElement) {
    if (parent.tagName === 'DETAILS') parent.open = true;
    if (parent.dataset?.tab) parent.parentElement.show(parent.dataset.tab);
  }
  target?.scrollIntoView({block:'center'});
}

async function api(action, data, query = {}) {
  const options = data === undefined ? {} : {method:'POST', headers:{'Content-Type':'application/json', 'X-CSRF-Token':session.csrf}, body:JSON.stringify(data)};
  const result = await fetch('/api?' + new URLSearchParams({action, ...query}), options);
  const json = await result.json();
  if (!result.ok || (!json.ok && !json.errors)) throw new Error(json.message || 'Не удалось выполнить действие.');
  return json;
}
function guard(fn) { return async (...args) => { try { await fn(...args); } catch (error) { fail(error.message); } }; }

function setDirty(value) {
  dirty = value;
  const bar = document.querySelector('.savebar');
  if (bar) {
    bar.classList.toggle('is-dirty', value);
    bar.querySelector('.savebar__state').textContent = value ? 'Есть несохранённые изменения' : 'Все изменения сохранены';
  }
}

function navigate(query = {}) {
  if (dirty && !confirm('Есть несохранённые изменения. Покинуть страницу?')) return;
  dirty = false;
  history.pushState(null, '', '/?' + new URLSearchParams(query));
  render();
}
window.addEventListener('popstate', () => { dirty = false; render(); });
window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });

function link(text, query, cls = '') {
  return h('a', {href:'/?' + new URLSearchParams(query), class:cls, onclick:event => { event.preventDefault(); navigate(query); }}, text);
}
function navLink(name, text, query, active) {
  const node = link(text, query, 'nav__item' + (active ? ' active' : ''));
  node.prepend(icon(name));
  if (active) node.setAttribute('aria-current', 'page');
  return node;
}

/**
 * Application shell.
 * options: {title, subtitle, breadcrumb: [[text, query]|text], actions: [Node], narrow: bool}
 * narrow suits a form, where a full-width measure would strand the labels.
 */
function shell(options, ...children) {
  const {title, subtitle, breadcrumb = [], actions = [], narrow = false} = options;
  const site = params().get('site') || sites[0]?.id;
  const view = params().get('view') || 'sites';
  const settingsTab = view === 'settings' ? (params().get('tab') || 'general') : null;
  const current = sites.find(s => s.id === site);
  const admin = session.user.role === 'admin';

  const brand = h('a', {class:'sidebar__brand', href:'/?', onclick:e => { e.preventDefault(); navigate({}); }}, icon('logo'), 'MiniCMS Studio');

  const aside = h('aside', {class:'sidebar'},
    brand,
    current ? link([
      h('span', {class:'site-switch__mark'}, monogram(current.identity?.market || current.title)),
      h('span', {class:'site-switch__body'},
        h('span', {class:'site-switch__name'}, current.title),
        h('span', {class:'site-switch__meta'}, current.baseurl.replace(/^https?:\/\//, '').replace(/\/$/, ''))),
      icon('chevron'),
    ], {view:'sites'}, 'site-switch') : null,
    // Grouped by what you are working on, and named after the job rather than
    // the screen: "how do I change how the site looks" should not end in a tab
    // inside a tab, so the settings tabs are linked directly.
    h('nav', {class:'nav', 'aria-label':'Разделы'},
      site ? h('p', {class:'nav__label'}, 'Этот сайт') : null,
      site ? navLink('doc', 'Страницы и тексты', {view:'pages', site}, ['pages', 'editor'].includes(view)) : null,
      site && admin ? navLink('palette', 'Внешний вид', {view:'settings', site, tab:'appearance'}, settingsTab === 'appearance') : null,
      site && admin ? navLink('chain', 'Адреса и меню', {view:'settings', site, tab:'routes'}, ['routes', 'labels', 'navigation'].includes(settingsTab)) : null,
      site && admin ? navLink('back', 'Редиректы', {view:'settings', site, tab:'redirects'}, settingsTab === 'redirects') : null,
      site && admin ? navLink('gear', 'Название и домен', {view:'settings', site, tab:'general'}, settingsTab === 'general') : null,
      h('p', {class:'nav__label'}, 'Вся сеть'),
      navLink('grid', 'Все сайты', {view:'sites'}, view === 'sites'),
      admin ? navLink('tag', 'Товары и ссылки', {view:'catalog'}, ['catalog', 'product'].includes(view)) : null,
      navLink('chart', 'SEO: аудит и переводы', {view:'seo'}, view === 'seo'),
      navLink('rocket', 'Публикации', {view:'jobs'}, view === 'jobs'),
      admin ? h('p', {class:'nav__label'}, 'Администрирование') : null,
      admin ? navLink('plus', 'Новый сайт', {view:'new-site'}, view === 'new-site') : null,
      admin ? navLink('history', 'История правок', {view:'history'}, ['history', 'version'].includes(view)) : null,
      admin ? navLink('users', 'Команда', {view:'users'}, view === 'users') : null),
    h('div', {class:'sidebar__spacer'}),
    h('div', {class:'sidebar__user'},
      h('span', {class:'sidebar__avatar'}, monogram(session.user.login, 1)),
      h('span', {class:'sidebar__who'}, h('strong', {}, session.user.login), h('span', {}, roles[session.user.role])),
      iconButton('logout', 'Выйти', guard(async () => { await api('logout', {}); dirty = false; location.href = '/'; }))));

  const crumbs = h('div', {class:'breadcrumb'});
  breadcrumb.forEach((item, i) => {
    if (i) crumbs.append(h('span', {class:'breadcrumb__sep'}, '/'));
    crumbs.append(Array.isArray(item) ? link(item[0], item[1]) : h('strong', {}, item));
  });

  const measure = narrow ? ' is-narrow' : '';
  const root = h('div', {class:'app'}, aside,
    h('div', {class:'app__main'},
      h('div', {class:'topbar'},
        h('div', {class:'topbar__inner' + measure},
          iconButton('menu', 'Меню', () => root.classList.toggle('drawer-open'), 'btn--icon drawer-toggle'),
          crumbs,
          h('div', {class:'topbar__actions'}, ...actions))),
      h('main', {id:'main', class:'content' + measure},
        title ? h('div', {class:'page-head'}, h('div', {class:'page-head__text'},
          h('h1', {}, title), subtitle ? h('p', {}, subtitle) : null)) : null,
        ...children)));

  root.addEventListener('click', event => {
    if (root.classList.contains('drawer-open') && !event.target.closest('.sidebar, .drawer-toggle')) root.classList.remove('drawer-open');
  });
  app.replaceChildren(root);
}

function emptyState(text, hint, action) {
  return h('div', {class:'empty'}, icon('inbox'), h('strong', {}, text), hint ? h('p', {}, hint) : null, action || null);
}

/**
 * Tab group.
 * items: [{id, label, icon, hint, panel}]
 *
 * Inactive panels are hidden, never detached: saving reads every field back
 * from the DOM, so a removed panel would be written out as empty. Hidden form
 * controls still keep their values, which is exactly what we need.
 *
 * The open tab is mirrored into ?tab= with replaceState, not navigate(), so
 * switching tabs never re-renders the view or throws away unsaved edits — but
 * a reload or a shared link still lands on the same tab.
 */
function tabs(items, param = 'tab') {
  const list = h('div', {class:'tabs', role:'tablist'});
  const wrap = h('div', {class:'tabset'}, list);
  const buttons = new Map();

  const show = (id, remember = true) => {
    if (!buttons.has(id)) id = items[0].id;
    for (const item of items) {
      const on = item.id === id;
      const tab = buttons.get(item.id);
      tab.classList.toggle('active', on);
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
      tab.tabIndex = on ? 0 : -1;
      item.panel.hidden = !on;
    }
    wrap.active = id;
    if (remember) {
      const query = params();
      query.set(param, id);
      history.replaceState(null, '', '/?' + query);
    }
  };

  items.forEach((item, index) => {
    const tabId = 'tab-' + item.id;
    const panelId = 'panel-' + item.id;
    Object.assign(item.panel, {id:panelId, hidden:true});
    item.panel.dataset.tab = item.id;
    item.panel.setAttribute('role', 'tabpanel');
    item.panel.setAttribute('aria-labelledby', tabId);

    const tab = h('button', {
      type:'button', class:'tab', id:tabId, role:'tab', 'aria-controls':panelId,
      onclick:() => show(item.id),
      onkeydown:event => {
        const step = {ArrowRight:1, ArrowLeft:-1, Home:-index, End:items.length - 1 - index}[event.key];
        if (step === undefined) return;
        event.preventDefault();
        const next = items[(index + step + items.length) % items.length];
        show(next.id);
        buttons.get(next.id).focus();
      },
    }, item.icon ? icon(item.icon) : null, h('span', {}, item.label), h('span', {class:'tab__flag', hidden:true}));

    buttons.set(item.id, tab);
    list.append(tab);
    wrap.append(item.panel);
  });

  wrap.show = show;
  wrap.tabFor = id => buttons.get(id);
  show(params().get(param) || items[0].id, false);
  return wrap;
}

/**
 * Filter strip. Looks like the tab bar but filters the list below instead of
 * swapping panels, so it is a radio group rather than a tablist.
 */
function segmented(items, value, onchange) {
  const strip = h('div', {class:'tabs tabs--filter', role:'radiogroup'});
  const paint = next => {
    value = next;
    strip.querySelectorAll('.tab').forEach(node => {
      const on = node.dataset.value === String(next);
      node.classList.toggle('active', on);
      node.setAttribute('aria-checked', on ? 'true' : 'false');
      node.tabIndex = on ? 0 : -1;
    });
    onchange(next);
  };
  for (const item of items) {
    strip.append(h('button', {
      type:'button', class:'tab', role:'radio', 'data-value':item.id,
      onclick:() => paint(item.id),
    }, h('span', {}, item.label), h('span', {class:'tab__count'}, item.count)));
  }
  paint(value);
  return strip;
}

/** Marks tabs whose panel holds a field the server rejected. */
function flagTabs() {
  document.querySelectorAll('.tab__flag').forEach(flag => { flag.hidden = true; flag.textContent = ''; });
  document.querySelectorAll('.tabset').forEach(set => {
    for (const panel of set.querySelectorAll(':scope > [role="tabpanel"]')) {
      const count = panel.querySelectorAll('[aria-invalid="true"]').length;
      const flag = set.tabFor(panel.dataset.tab)?.querySelector('.tab__flag');
      if (!flag || !count) continue;
      flag.hidden = false;
      flag.textContent = String(count);
      flag.title = count + ' ' + (count === 1 ? 'поле требует внимания' : 'полей требуют внимания');
    }
  });
}

function errorsPanel(issues, container) {
  document.querySelectorAll('[aria-invalid="true"]').forEach(control => {
    control.removeAttribute('aria-invalid');
    control.removeAttribute('aria-describedby');
    document.getElementById(control.id + '-error')?.remove();
  });
  container.replaceChildren();
  if (!issues?.length) { flagTabs(); return; }
  container.append(h('section', {class:'error-summary', tabindex:'-1', role:'alert'},
    h('h2', {}, 'Исправьте перед продолжением'),
    h('ul', {}, issues.map(issue => h('li', {}, h('a', {href:issue.edit_url || '#field-' + (issue.field || 'body').replaceAll('.', '-'), onclick:event => {
      if (issue.site && (issue.site !== currentDocument?.site || issue.page !== currentDocument?.page)) return;
      event.preventDefault();
      const target = document.getElementById('field-' + (issue.field || 'body').replaceAll('.', '-')) || document.getElementById('field-body');
      revealField(target);
      (target?.matches('input,textarea,select') ? target : target?.querySelector('input,textarea,select,button'))?.focus();
    }}, issue.message))))));
  for (const issue of issues) {
    const field = document.getElementById('field-' + (issue.field || '').replaceAll('.', '-'));
    const control = field?.matches('input,textarea,select') ? field : field?.querySelector('input,textarea,select');
    if (control) {
      control.setAttribute('aria-invalid', 'true');
      const id = control.id + '-error';
      control.setAttribute('aria-describedby', id);
      control.after(h('small', {id, class:'field-error'}, issue.message));
    }
  }
  flagTabs();
  container.firstElementChild.focus();
}

// One recursive field renderer for page models and blocks. No schema-specific JS.
function field(name, rule, value, path, schema) {
  const id = 'field-' + path.replaceAll('.', '-');
  const type = rule.type || 'string';
  const label = (rule.label || name) + (rule.required ? ' *' : '');
  const wrap = h('div', {class:'field', 'data-path':path});
  if (type === 'object') {
    const group = h('fieldset', {id}, rule.hideLabel ? null : h('legend', {}, label));
    const readers = {};
    for (const [key, child] of Object.entries(rule.fields || {})) { const f = field(key, child, value?.[key], path + '.' + key, schema); group.append(f); readers[key] = f; }
    wrap.append(group);
    wrap.read = () => Object.fromEntries(Object.entries(readers).map(([k, f]) => [k, f.read()]));
    wrap.write = value => { for (const [key, f] of Object.entries(readers)) f.write(value?.[key]); };
    return wrap;
  }
  if (type === 'list') {
    const group = h('fieldset', {id}, h('legend', {}, label));
    const list = h('div', {class:'repeat-list'});
    let values = Array.isArray(value) ? value : [];
    // The "empty" placeholder is a child of the list too, so skip anything
    // that is not a real row.
    const collect = () => [...list.children].filter(row => row.reader).map(row => row.reader.read());
    const paint = () => {
      list.replaceChildren();
      values.forEach((item, i) => {
        const child = field(String(i + 1), {...(rule.items || {type:'string'}), hideLabel:true, label:label + ' ' + (i + 1)}, item, path + '.' + i, schema);
        const row = h('div', {class:'repeat-item'}, h('div', {class:'repeat-tools'},
          h('span', {}, String(i + 1).padStart(2, '0')),
          iconButton('up', 'Выше', () => { values = collect(); if (i > 0) [values[i-1], values[i]] = [values[i], values[i-1]]; paint(); setDirty(true); }),
          iconButton('down', 'Ниже', () => { values = collect(); if (i < values.length-1) [values[i+1], values[i]] = [values[i], values[i+1]]; paint(); setDirty(true); }),
          iconButton('trash', 'Удалить', () => { values = collect(); values.splice(i, 1); paint(); setDirty(true); }, 'btn--icon btn--danger')), child);
        row.reader = child;
        list.append(row);
      });
      if (!values.length) list.append(h('p', {class:'muted small'}, 'Пока пусто.'));
    };
    const add = h('button', {type:'button', class:'btn--sm', onclick:() => { values = collect(); values.push(rule.items?.type === 'object' ? {} : rule.items?.type === 'list' ? [] : ''); paint(); setDirty(true); }}, icon('plus'), 'Добавить');
    group.append(list, h('div', {}, add));
    paint();
    wrap.append(group);
    wrap.read = collect;
    wrap.write = next => { values = Array.isArray(next) ? next : []; paint(); };
    return wrap;
  }
  let control;
  if (type === 'product' || rule.options) {
    const options = type === 'product' ? Object.entries(schema.products) : rule.options.map(x => [x, x]);
    control = h('select', {id}, h('option', {value:''}, 'Выберите…'), options.map(([key, text]) => h('option', {value:key, selected:key === value}, text)));
  } else if (type === 'boolean') {
    control = h('input', {id, type:'checkbox', checked:value === undefined ? true : !!value});
  } else if (type === 'text') {
    control = h('textarea', {id, rows:4}, value || '');
  } else {
    let v = value ?? '';
    if (type === 'date' && typeof v === 'number') v = new Date(v * 1000).toISOString().slice(0, 10);
    control = h('input', {id, type:type === 'number' ? 'number' : type === 'date' ? 'date' : 'text', value:v, step:type === 'number' ? 'any' : null});
  }
  control.name = path;
  control.addEventListener('input', () => { control.removeAttribute('aria-invalid'); setDirty(true); });
  // A row in a list hides its label to avoid repeating it; the control still
  // needs a name, or a screen reader announces a bare "edit text".
  if (rule.hideLabel) control.setAttribute('aria-label', label);
  else wrap.append(h('label', {for:id}, label));
  wrap.append(control);
  if (rule.hint) wrap.append(h('small', {class:'field__hint'}, rule.hint));
  if (type === 'image') {
    const preview = h('img', {class:'dropzone__preview', alt:'', src:control.value || ''});
    preview.classList.toggle('is-hidden', !control.value);
    const upload = h('input', {type:'file', accept:'image/png,image/jpeg,image/webp', 'aria-label':'Загрузить изображение', onchange:guard(async event => {
      if (!event.target.files[0]) return;
      const data = new FormData();
      data.append('site', currentDocument.site);
      data.append('image', event.target.files[0]);
      const result = await fetch('/api?action=upload', {method:'POST', headers:{'X-CSRF-Token':session.csrf}, body:data});
      const json = await result.json();
      if (!json.ok) throw new Error(json.message);
      control.value = json.url;
      preview.src = json.url;
      preview.classList.remove('is-hidden');
      setDirty(true);
      done('Изображение загружено. Добавьте описание в поле alt.');
    })});
    wrap.append(h('div', {class:'dropzone'}, preview, upload));
  }
  wrap.read = () => type === 'boolean' ? control.checked : type === 'number' ? (control.value === '' ? null : Number(control.value)) : control.value;
  wrap.write = value => {
    if (type === 'boolean') { control.checked = !!value; return; }
    control.value = value == null ? '' : String(value);
    if (type === 'image') { const preview = wrap.querySelector('.dropzone__preview'); if (preview) { preview.src = control.value; preview.classList.toggle('is-hidden', !control.value); } }
  };
  return wrap;
}

/* ------------------------------------------------------------ text editor
   A bare textarea is fine for a paragraph and miserable for a 1500-word
   review. This adds the three things a copywriter actually needs: formatting
   without remembering Markdown, a preview rendered by the same parser the
   build uses, and a count of what has been written. */

const wordCount = text => {
  const plain = text
    .replace(/```[\s\S]*?```/g, ' ')
    .replace(/!\[[^\]]*\]\([^)]*\)/g, ' ')
    .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
    .replace(/^[ \t]{0,3}#{1,6}[ \t]+/gm, '')
    .replace(/[*_`>|-]+/g, ' ')
    .trim();
  return {words: plain ? plain.split(/\s+/).length : 0, chars: plain.length};
};

function markdownEditor(textarea, site) {
  const wrap = h('div', {class:'editor-pane'});
  const preview = h('div', {class:'md-preview', 'aria-live':'polite'});
  const counter = h('span', {class:'md-count'});

  /** Wraps the selection, or drops a marker where the caret is. */
  const around = (before, after = before) => {
    const {selectionStart:from, selectionEnd:to, value} = textarea;
    const picked = value.slice(from, to) || 'текст';
    textarea.value = value.slice(0, from) + before + picked + after + value.slice(to);
    textarea.focus();
    textarea.setSelectionRange(from + before.length, from + before.length + picked.length);
    textarea.dispatchEvent(new Event('input', {bubbles:true}));
  };
  /** Prefixes every selected line — lists, quotes and headings work that way. */
  const lines = prefix => {
    const {selectionStart:from, selectionEnd:to, value} = textarea;
    const start = value.lastIndexOf('\n', from - 1) + 1;
    const end = value.indexOf('\n', to) === -1 ? value.length : value.indexOf('\n', to);
    const block = value.slice(start, end).split('\n').map(line => line.startsWith(prefix) ? line.slice(prefix.length) : prefix + line).join('\n');
    textarea.value = value.slice(0, start) + block + value.slice(end);
    textarea.focus();
    textarea.setSelectionRange(start, start + block.length);
    textarea.dispatchEvent(new Event('input', {bubbles:true}));
  };
  const insert = text => {
    const {selectionStart:from, selectionEnd:to, value} = textarea;
    textarea.value = value.slice(0, from) + text + value.slice(to);
    textarea.focus();
    textarea.setSelectionRange(from + text.length, from + text.length);
    textarea.dispatchEvent(new Event('input', {bubbles:true}));
  };

  const tool = (label, title, fn) => h('button', {type:'button', class:'md-tool', title, 'aria-label':title, onclick:fn}, label);

  const picker = h('input', {type:'file', accept:'image/png,image/jpeg,image/webp', class:'is-hidden', 'aria-hidden':'true', tabindex:'-1'});
  const upload = guard(async file => {
    if (!file) return;
    if (!/^image\/(png|jpeg|webp)$/.test(file.type)) { fail('Поддерживаются PNG, JPEG и WebP.'); return; }
    notice('Загружаю изображение…');
    const data = new FormData();
    data.append('site', site);
    data.append('image', file);
    const response = await fetch('/api?action=upload', {method:'POST', headers:{'X-CSRF-Token':session.csrf}, body:data});
    const json = await response.json();
    if (!json.ok) throw new Error(json.message || 'Не удалось загрузить изображение.');
    // The caret lands inside the alt brackets: a description is not optional,
    // and the audit reports every image that has none.
    const before = `![`;
    const after = `](${json.url})`;
    const {selectionStart:at, value} = textarea;
    textarea.value = value.slice(0, at) + before + after + value.slice(textarea.selectionEnd);
    textarea.focus();
    textarea.setSelectionRange(at + before.length, at + before.length);
    textarea.dispatchEvent(new Event('input', {bubbles:true}));
    done('Изображение вставлено. Впишите описание в квадратных скобках — оно нужно поиску и читателям.');
  });
  picker.addEventListener('change', () => { upload(picker.files[0]); picker.value = ''; });

  textarea.addEventListener('paste', event => {
    const file = [...(event.clipboardData?.files || [])][0];
    if (file) { event.preventDefault(); upload(file); }
  });
  textarea.addEventListener('dragover', event => { if (event.dataTransfer?.types.includes('Files')) { event.preventDefault(); wrap.classList.add('is-dropping'); } });
  textarea.addEventListener('dragleave', () => wrap.classList.remove('is-dropping'));
  textarea.addEventListener('drop', event => {
    const file = [...(event.dataTransfer?.files || [])][0];
    wrap.classList.remove('is-dropping');
    if (file) { event.preventDefault(); upload(file); }
  });

  const linkDialog = h('dialog', {class:'link-dialog'});
  const openLinks = guard(async () => {
    const {pages} = await api('pages', undefined, {site});
    const usable = pages.filter(p => p.path);
    const search = h('input', {type:'search', placeholder:'Название, адрес или товар…', 'aria-label':'Найти страницу'});
    const list = h('div', {class:'link-list'});
    const paint = () => {
      const query = search.value.trim().toLowerCase();
      const found = usable.filter(p => `${p.title} ${p.path} ${p.product || ''}`.toLowerCase().includes(query));
      list.replaceChildren(...(found.length ? found.slice(0, 60).map(p => h('button', {type:'button', class:'link-item', onclick:() => {
        linkDialog.close();
        insert(`[${p.title || p.path}](${p.path})`);
      }},
        h('strong', {}, p.title || p.path),
        h('span', {}, p.path),
        p.product ? h('span', {class:'chip'}, p.product) : null)) : [h('p', {class:'muted small'}, 'Ничего не найдено.')]));
    };
    search.addEventListener('input', paint);
    paint();
    linkDialog.replaceChildren(
      h('header', {}, h('strong', {}, 'Ссылка на страницу сайта'), iconButton('close', 'Закрыть', () => linkDialog.close())),
      h('div', {class:'card__body'}, h('div', {class:'search'}, icon('search'), search), list));
    linkDialog.showModal();
    search.focus();
  });

  const modes = h('div', {class:'md-modes', role:'radiogroup', 'aria-label':'Режим редактора'});

  const toolbar = h('div', {class:'md-toolbar', role:'toolbar', 'aria-label':'Форматирование'},
    tool('Ж', 'Полужирный (Ctrl+B)', () => around('**')),
    tool('К', 'Курсив (Ctrl+I)', () => around('*')),
    h('span', {class:'md-sep'}),
    tool('H2', 'Подзаголовок', () => lines('## ')),
    tool('H3', 'Подзаголовок третьего уровня', () => lines('### ')),
    h('span', {class:'md-sep'}),
    tool('•', 'Маркированный список', () => lines('- ')),
    tool('1.', 'Нумерованный список', () => lines('1. ')),
    tool('❝', 'Цитата', () => lines('> ')),
    h('span', {class:'md-sep'}),
    tool('🔗', 'Внешняя ссылка', () => around('[', '](https://)')),
    tool('🖼', 'Вставить изображение — можно также вставить из буфера или перетащить файл', () => picker.click()),
    h('button', {type:'button', class:'md-tool md-tool--wide', onclick:openLinks}, icon('doc'), 'Страница сайта'),
    modes);

  const setMode = mode => {
    wrap.dataset.mode = mode;
    modes.querySelectorAll('button').forEach(b => {
      const on = b.dataset.mode === mode;
      b.classList.toggle('active', on);
      b.setAttribute('aria-checked', on ? 'true' : 'false');
    });
    try { localStorage.setItem('studio:editor-mode', mode); } catch { /* private mode */ }
    if (mode !== 'write') render();
  };
  for (const [mode, label] of [['write', 'Текст'], ['split', 'Рядом'], ['preview', 'Предпросмотр']]) {
    modes.append(h('button', {type:'button', class:'md-mode', role:'radio', 'data-mode':mode, onclick:() => setMode(mode)}, label));
  }

  let timer = null, lastRendered = null;
  const render = () => {
    if (wrap.dataset.mode === 'write' || textarea.value === lastRendered) return;
    clearTimeout(timer);
    timer = setTimeout(async () => {
      const body = textarea.value;
      try {
        const {html} = await api('markdown', {site, body});
        lastRendered = body;
        preview.innerHTML = html || '<p class="muted small">Пусто.</p>';
      } catch { preview.textContent = 'Предпросмотр недоступен.'; }
    }, 400);
  };

  const count = () => {
    const {words, chars} = wordCount(textarea.value);
    counter.textContent = `${words} ${plural(words, 'слово', 'слова', 'слов')} · ${chars} ${plural(chars, 'знак', 'знака', 'знаков')}`;
  };
  textarea.addEventListener('input', () => { count(); render(); });
  textarea.addEventListener('keydown', event => {
    if (!(event.ctrlKey || event.metaKey)) return;
    const key = event.key.toLowerCase();
    if (key === 'b') { event.preventDefault(); around('**'); }
    if (key === 'i') { event.preventDefault(); around('*'); }
  });

  wrap.append(toolbar,
    h('div', {class:'md-body'}, textarea, preview),
    h('div', {class:'md-foot'}, counter, linkDialog, picker));
  count();
  let saved = 'write';
  try { saved = localStorage.getItem('studio:editor-mode') || 'write'; } catch { /* private mode */ }
  setMode(['write', 'split', 'preview'].includes(saved) ? saved : 'write');
  return wrap;
}

function blocksEditor(value, schema) {
  const root = h('section', {class:'card', id:'field-blocks'},
    h('div', {class:'card__head'}, h('div', {}, h('h2', {}, 'Блоки страницы'),
      h('p', {}, 'Страница собирается из готовых секций. Порядок меняется стрелками.'))));
  const body = h('div', {class:'card__body'});
  const list = h('div', {class:'block-list'});
  let values = value || [];
  const collect = () => [...list.children].filter(n => n.blockType).map(node => ({type:node.blockType, ...Object.fromEntries(Object.entries(node.fields).map(([key, f]) => [key, f.read()]))}));
  function paint() {
    list.replaceChildren();
    values.forEach((block, i) => {
      const def = schema.blocks[block.type];
      if (!def) return;
      const row = h('section', {class:'block-card', id:'field-blocks-' + i},
        h('header', {class:'block-header'},
          h('strong', {}, String(i + 1).padStart(2, '0') + ' · ' + def.label),
          h('div', {class:'actions'},
            iconButton('up', 'Выше', () => { values = collect(); if (i) [values[i-1], values[i]] = [values[i], values[i-1]]; paint(); setDirty(true); }),
            iconButton('down', 'Ниже', () => { values = collect(); if (i < values.length-1) [values[i+1], values[i]] = [values[i], values[i+1]]; paint(); setDirty(true); }),
            iconButton('trash', 'Удалить блок', () => { values = collect(); values.splice(i, 1); paint(); setDirty(true); }, 'btn--icon btn--danger'))));
      row.blockType = block.type;
      row.fields = {};
      for (const [key, rule] of Object.entries(def.fields)) { const f = field(key, rule, block[key], `blocks.${i}.${key}`, schema); row.fields[key] = f; row.append(f); }
      list.append(row);
    });
    if (!values.length) list.append(h('p', {class:'muted small'}, 'Блоков нет — страница состоит только из основного текста.'));
  }
  paint();
  body.append(list, h('div', {class:'block-palette'}, Object.entries(schema.blocks).map(([type, def]) =>
    h('button', {type:'button', class:'btn--sm', onclick:() => { values = collect(); values.push({type}); paint(); setDirty(true); }}, icon('plus'), def.label))));
  root.append(body);
  root.read = collect;
  root.write = next => { values = Array.isArray(next) ? next : []; paint(); };
  return root;
}

async function renderLogin() {
  const setup = session.needs_setup;
  const login = h('input', {id:'login', name:'username', autocomplete:'username', required:true, autofocus:true});
  const password = h('input', {id:'password', name:'password', type:'password', autocomplete:'current-password', required:true});
  const error = h('p', {role:'alert', class:'field-error'});
  app.replaceChildren(h('main', {id:'main', class:'login-screen'},
    h('section', {class:'login-card'},
      h('div', {class:'logo'}, icon('logo'), 'MiniCMS Studio'),
      h('h1', {}, setup ? 'Создание администратора' : 'Вход в Studio'),
      setup ? h('p', {class:'muted'}, 'Первый запуск. Придумайте логин и пароль от 12 до 72 байт.') : null,
      h('form', {onsubmit:async event => {
        event.preventDefault();
        try {
          if (setup) await api('setup', {login:login.value, password:password.value});
          session = await api('login', {login:login.value, password:password.value});
          await render();
        } catch (e) { error.textContent = e.message; }
      }},
        h('div', {class:'field'}, h('label', {for:'login'}, 'Логин'), login),
        h('div', {class:'field'}, h('label', {for:'password'}, 'Пароль'), password),
        error,
        h('button', {type:'submit', class:'primary'}, setup ? 'Создать администратора' : 'Войти')))));
}

async function usersView() {
  const {users} = await api('users');
  const rows = users.map(user => {
    const role = h('select', {'aria-label':'Роль ' + user.login}, Object.entries(roles).map(([key, text]) => h('option', {value:key, selected:key === user.role}, text)));
    const scope = h('input', {'aria-label':'Сайты ' + user.login, value:user.sites.join(',')});
    const enabled = h('input', {type:'checkbox', checked:user.enabled, 'aria-label':'Активен ' + user.login});
    return h('tr', {},
      h('td', {}, h('div', {class:'row-center'},
        h('span', {class:'sidebar__avatar'}, monogram(user.login)), h('b', {}, user.login))),
      h('td', {}, role),
      h('td', {}, scope),
      h('td', {class:'shrink'}, enabled),
      h('td', {class:'shrink'}, button('Сохранить', guard(async () => {
        await api('user-update', {login:user.login, role:role.value, sites:scope.value.split(',').map(s => s.trim()).filter(Boolean), enabled:enabled.checked});
        done('Права обновлены.');
        await render();
      }), 'btn--sm')));
  });

  const login = h('input', {id:'new-login', required:true, autocomplete:'off'});
  const password = h('input', {id:'new-password', type:'password', required:true, minlength:12, autocomplete:'new-password'});
  const role = h('select', {id:'new-role'}, Object.entries(roles).map(([k, v]) => h('option', {value:k}, v)));
  const scopes = h('input', {id:'new-scopes', value:sites[0]?.id || '', required:true});
  const dialog = h('dialog', {},
    h('header', {}, h('strong', {}, 'Новый участник'), iconButton('close', 'Закрыть', () => dialog.close())),
    h('form', {onsubmit:guard(async event => {
      event.preventDefault();
      await api('user-create', {login:login.value, password:password.value, role:role.value, sites:scopes.value.split(',').map(s => s.trim()).filter(Boolean)});
      done('Пользователь создан.');
      dialog.close();
      await render();
    })},
      h('div', {class:'field'}, h('label', {for:'new-login'}, 'Логин'), login),
      h('div', {class:'field'}, h('label', {for:'new-password'}, 'Начальный пароль'), password, h('small', {class:'field__hint'}, 'От 12 до 72 байт.')),
      h('div', {class:'field'}, h('label', {for:'new-role'}, 'Роль'), role),
      h('div', {class:'field'}, h('label', {for:'new-scopes'}, 'Доступные сайты'), scopes, h('small', {class:'field__hint'}, 'ID через запятую, * — все сайты.')),
      h('button', {type:'submit', class:'primary'}, 'Создать участника')));

  const add = h('button', {type:'button', class:'primary', onclick:() => dialog.showModal()}, icon('plus'), 'Добавить участника');
  shell({
    title:'Команда',
    subtitle:'Права проверяются сервером при каждом действии. Отключённый аккаунт теряет доступ немедленно.',
    breadcrumb:['Команда'],
    actions:[add],
  }, h('div', {class:'table-card'}, h('table', {class:'table'},
    h('thead', {}, h('tr', {}, h('th', {}, 'Участник'), h('th', {}, 'Роль'), h('th', {}, 'Сайты'), h('th', {}, 'Активен'), h('th', {}))),
    h('tbody', {}, rows))), dialog);
}

async function sitesView() {
  const card = site => h('article', {class:'site-card'},
    h('div', {class:'site-card__top'},
      h('span', {class:'site-card__mark'}, monogram(site.identity?.market || site.title)),
      h('div', {class:'site-card__head'},
        h('h2', {}, site.title),
        h('a', {href:site.baseurl, target:'_blank', rel:'noreferrer'}, site.baseurl.replace(/^https?:\/\//, '').replace(/\/$/, ''))),
      badge(site.staging ? 'staging' : 'production')),
    h('div', {class:'site-card__meta'},
      h('span', {class:'chip'}, site.identity.market.toUpperCase()),
      h('span', {class:'chip'}, site.identity.locale),
      h('span', {class:'chip'}, site.identity.theme)),
    h('div', {class:'site-card__foot'},
      link('Материалы', {view:'pages', site:site.id}, 'button primary'),
      session.user.role === 'admin' ? link('Настройки', {view:'settings', site:site.id}, 'button') : null));

  // One engine, many GEOs: with a dozen sites a flat grid stops being readable,
  // so group by brand. A single-brand workspace gets no headings.
  const byBrand = new Map();
  for (const site of sites) {
    const brand = site.identity?.brand || '—';
    if (!byBrand.has(brand)) byBrand.set(brand, []);
    byBrand.get(brand).push(site);
  }
  const groups = [...byBrand.keys()].sort().map(brand => byBrand.size > 1
    ? h('section', {class:'site-group'},
        h('h2', {class:'site-group__title'}, brand, h('span', {class:'site-group__count'}, byBrand.get(brand).length)),
        h('div', {class:'site-grid'}, byBrand.get(brand).map(card)))
    : h('div', {class:'site-grid'}, byBrand.get(brand).map(card)));

  shell({
    title:'Сайты',
    subtitle:sites.length + (sites.length % 10 === 1 && sites.length % 100 !== 11 ? ' сайт' : [2,3,4].includes(sites.length % 10) && ![12,13,14].includes(sites.length % 100) ? ' сайта' : ' сайтов') + ' в рабочем пространстве',
    breadcrumb:['Сайты'],
    actions:session.user.role === 'admin' ? [link('Новый сайт', {view:'new-site'}, 'button primary')] : [],
  }, sites.length ? groups : emptyState('Сайтов пока нет', 'Создайте первый сайт, чтобы начать.'));
}

async function pagesView(site) {
  const [{pages}, schema] = await Promise.all([api('pages', undefined, {site}), api('schema', undefined, {site})]);
  const current = sites.find(s => s.id === site);

  const search = h('input', {type:'search', placeholder:'Название или путь…', 'aria-label':'Найти материал'});
  const tbody = h('tbody');

  // Workflow stages, not raw states: an editor thinks "что у меня в работе",
  // not "draft or publication_failed". Every state belongs to exactly one
  // stage, so nothing can disappear from all of them at once.
  const stages = [
    {id:'', label:'Все', states:null},
    {id:'source', label:'Исходники', states:['source']},
    {id:'work', label:'В работе', states:['draft', 'publication_failed', 'cancelled']},
    {id:'review', label:'На проверке', states:['review', 'approved']},
    {id:'live', label:'Опубликовано', states:['published', 'publishing', 'queued', 'built']},
  ];
  const inStage = (page, stage) => !stage.states || stage.states.includes(page.state);
  let stage = stages.find(s => s.id === (params().get('stage') || '')) || stages[0];

  const sectionOf = page => page.page.includes('/') ? page.page.slice(0, page.page.lastIndexOf('/')) : '';
  const sectionLabel = key => '/' + (key === '' ? '' : key + '/');

  const paint = () => {
    const query = search.value.trim().toLowerCase();
    const visible = pages.filter(p => inStage(p, stage) && `${p.title} ${p.page}`.toLowerCase().includes(query));

    if (!visible.length) {
      tbody.replaceChildren(h('tr', {}, h('td', {colspan:'3'},
        emptyState(pages.length ? 'Ничего не найдено' : 'Материалов пока нет',
          pages.length ? 'Измените условия поиска или выберите другое состояние.' : 'Создайте первый материал для этого сайта.'))));
      return;
    }

    // Group by folder so the list mirrors the structure of the site.
    const bySection = new Map();
    for (const page of visible) {
      const key = sectionOf(page);
      if (!bySection.has(key)) bySection.set(key, []);
      bySection.get(key).push(page);
    }
    const rows = [];
    for (const key of [...bySection.keys()].sort()) {
      const group = bySection.get(key);
      if (bySection.size > 1) rows.push(h('tr', {class:'table__group'},
        h('th', {colspan:'3', scope:'rowgroup'}, h('span', {}, sectionLabel(key)), h('span', {class:'table__group-count'}, group.length))));
      for (const p of group) rows.push(h('tr', {},
        h('td', {}, link(p.title || 'Без названия', {view:'editor', site, page:p.page}, 'table__title'),
          h('span', {class:'table__path'}, p.page)),
        h('td', {class:'shrink muted'}, schema.types[p.type]?.label || p.type),
        h('td', {class:'shrink'}, badge(p.state))));
    }
    tbody.replaceChildren(...rows);
  };

  // An empty stage is noise; keep the selected one so the strip never jumps.
  const filter = segmented(
    stages.map(s => ({id:s.id, label:s.label, count:pages.filter(p => inStage(p, s)).length}))
      .filter(s => s.count || s.id === '' || s.id === stage.id),
    stage.id,
    id => {
      stage = stages.find(s => s.id === id) || stages[0];
      const query = params();
      id ? query.set('stage', id) : query.delete('stage');
      history.replaceState(null, '', '/?' + query);
      paint();
    });
  search.addEventListener('input', paint);

  const type = h('select', {id:'new-type', 'aria-label':'Тип страницы'}, Object.entries(schema.types).filter(([k]) => k !== 'homepage').map(([k, v]) => h('option', {value:k}, v.label)));
  const slug = h('input', {id:'new-slug', placeholder:'novyy-obzor', 'aria-label':'Адрес страницы', pattern:'[a-z0-9]+(?:-[a-z0-9]+)*', required:true});
  const dialog = h('dialog', {},
    h('header', {}, h('strong', {}, 'Новый материал'), iconButton('close', 'Закрыть', () => dialog.close())),
    h('form', {onsubmit:event => {
      event.preventDefault();
      const section = schema.types[type.value].section;
      dialog.close();
      navigate({view:'editor', site, page:(section ? section + '/' : '') + slug.value + '.md', type:type.value});
    }},
      h('div', {class:'field'}, h('label', {for:'new-type'}, 'Тип страницы'), type),
      h('div', {class:'field'}, h('label', {for:'new-slug'}, 'Адрес'), slug,
        h('small', {class:'field__hint'}, 'Строчные латинские буквы, цифры и дефисы.')),
      h('button', {type:'submit', class:'primary'}, 'Создать и открыть')));

  shell({
    title:'Материалы',
    subtitle:current ? current.title : site,
    breadcrumb:[['Сайты', {view:'sites'}], 'Материалы'],
    actions:[h('button', {type:'button', class:'primary', onclick:() => dialog.showModal()}, icon('plus'), 'Новый материал')],
  },
    h('div', {class:'toolbar'}, filter, h('div', {class:'search'}, icon('search'), search)),
    h('div', {class:'table-card'}, h('table', {class:'table'},
      h('thead', {}, h('tr', {}, h('th', {}, 'Материал'), h('th', {}, 'Тип'), h('th', {}, 'Состояние'))), tbody)),
    dialog);
}

async function editorView(site, page) {
  const [result, schema] = await Promise.all([
    api('document', undefined, {site, page, ...(params().get('type') ? {type:params().get('type')} : {})}),
    api('schema', undefined, {site}),
  ]);
  const doc = result.document;
  currentDocument = doc;
  const errors = h('div');
  const readers = {};
  const form = h('form', {class:'editor-form'});

  // Four jobs, four tabs: write the page, assemble its sections, tune how it
  // looks in search, fill in the metadata you touch once a year.
  const contentBody = h('div', {class:'card__body'});
  const mediaBody = h('div', {class:'card__body'});
  const creditsBody = h('div', {class:'card__body'});
  const relationsBody = h('div', {class:'card__body'});

  const body = h('textarea', {id:'field-body', name:'body', rows:18, class:'body-editor'}, doc.body);
  body.addEventListener('input', () => setDirty(true));

  const groups = {
    image:mediaBody, image_alt:mediaBody,
    author:creditsBody, reviewer:creditsBody, date:creditsBody, updated:creditsBody,
    canonical:relationsBody, breadcrumb_title:relationsBody, related:relationsBody, translation_key:relationsBody,
  };
  for (const [name, rule] of Object.entries(schema.types[doc.front_matter.type].fields)) {
    const f = field(name, rule, doc.front_matter[name], name, schema);
    readers[name] = f;
    if (name === 'seo' || name === 'indexing') continue;
    if (name === 'slug' && doc.front_matter.type === 'homepage') continue;
    (groups[name] || contentBody).append(f);
  }
  contentBody.append(
    h('div', {class:'field'},
      h('label', {for:'field-body'}, 'Основной текст'),
      h('small', {class:'field__hint'}, 'Заголовок страницы берётся из поля выше — в тексте начинайте с «##».'),
      markdownEditor(body, site)));

  const card = (title, hint, ...bodies) => h('section', {class:'card'},
    h('div', {class:'card__head'}, h('div', {}, h('h2', {}, title), hint ? h('p', {}, hint) : null)), ...bodies);

  const blocks = blocksEditor(doc.front_matter.blocks, schema);
  const metaPanel = h('div', {class:'tab-panel'},
    mediaBody.children.length ? card('Обложка', 'Изображение для карточек и шапки статьи.', mediaBody) : null,
    creditsBody.children.length ? card('Авторство и даты', 'Показываются читателю и уходят в разметку Schema.org.', creditsBody) : null,
    relationsBody.children.length ? card('Связи и адреса', 'Связанные материалы, хлебные крошки и ключ перевода для hreflang.', relationsBody) : null);

  // A model without cover, author or relation fields gets no "служебное" tab
  // rather than an empty one.
  const editorTabs = tabs([
    {id:'content', label:'Содержание', icon:'doc',
     panel:h('div', {class:'tab-panel'}, card('Содержание', 'Заголовок, вступление и основной текст страницы.', contentBody))},
    {id:'blocks', label:'Блоки', icon:'grid', panel:h('div', {class:'tab-panel'}, blocks)},
    {id:'seo', label:'Поиск', icon:'search',
     panel:h('div', {class:'tab-panel'}, card('Поисковая выдача',
       'Canonical, hreflang и языковые теги формируются движком автоматически.',
       h('div', {class:'card__body'}, readers.seo, readers.indexing)))},
    metaPanel.children.length ? {id:'meta', label:'Служебное', icon:'gear', panel:metaPanel} : null,
  ].filter(Boolean));
  form.append(editorTabs);

  const collect = () => {
    const fm = Object.fromEntries(Object.entries(readers).map(([k, f]) => [k, f.read()]));
    fm.type = doc.front_matter.type;
    fm.blocks = blocks.read();
    return {front_matter:fm, body:body.value};
  };

  const save = async () => {
    const payload = collect();
    const saved = await api('save', {site, page, revision:doc.revision, ...payload});
    if (!saved.ok) { errorsPanel(saved.errors, errors); fail('Проверьте выделенные поля.'); return false; }
    recovery.forget();
    setDirty(false);
    done('Сохранено.');
    await render();
    return true;
  };
  form.addEventListener('submit', guard(async event => { event.preventDefault(); await save(); }));

  /* A closed tab, a flat battery or a stray reload used to cost an hour of
     writing. Unsaved work is mirrored into this browser and offered back on
     return — never applied silently, because the file may have moved on. */
  const recovery = (() => {
    const key = `studio:draft:${site}:${page}`;
    let timer = null;
    const read = () => {
      try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch { return null; }
    };
    const forget = () => { clearTimeout(timer); try { localStorage.removeItem(key); } catch { /* private mode */ } };
    const keep = () => {
      clearTimeout(timer);
      timer = setTimeout(() => {
        try {
          localStorage.setItem(key, JSON.stringify({revision:doc.revision, at:Date.now(), ...collect()}));
        } catch { /* quota or private mode: autosave is a convenience, not a guarantee */ }
      }, 800);
    };
    const apply = stored => {
      for (const [name, f] of Object.entries(readers)) {
        if (stored.front_matter[name] !== undefined) f.write(stored.front_matter[name]);
      }
      blocks.write(stored.front_matter.blocks);
      body.value = stored.body;
      body.dispatchEvent(new Event('input', {bubbles:true}));
      setDirty(true);
    };
    return {key, read, forget, keep, apply};
  })();

  form.addEventListener('input', recovery.keep);
  body.addEventListener('input', recovery.keep);

  const transition = action => guard(async () => {
    if (dirty) { fail('Сначала сохраните изменения — согласование относится к конкретной ревизии.'); return; }
    const moved = await api('transition', {site, page, revision:doc.revision, transition:action});
    if (!moved.ok) { errorsPanel(moved.errors, errors); return; }
    done(action === 'publish' ? 'Задание поставлено в очередь публикации.' : 'Состояние обновлено.');
    await render();
  });

  const editable = doc.state !== 'publishing' && (session.user.role !== 'author' || !doc.owner || doc.owner === session.user.login);

  const actions = h('div', {class:'rail-actions'});
  if (editable && ['draft','publication_failed'].includes(doc.state)) actions.append(button('Отправить на проверку', transition('submit')));
  if (session.user.role !== 'author') {
    if (doc.state === 'review') actions.append(button('Одобрить ревизию', transition('approve'), 'primary'));
    if (['review','approved','publication_failed'].includes(doc.state)) actions.append(button('Вернуть в работу', transition('reject')));
    if (['approved','publication_failed'].includes(doc.state)) actions.append(button('Опубликовать', transition('publish'), 'primary'));
  }
  const previewButton = h('button', {type:'button', onclick:guard(async () => {
    if (dirty) { fail('Сохраните изменения, чтобы увидеть их в предпросмотре.'); return; }
    notice('Собираю предпросмотр…');
    const preview = await api('preview', {site, page});
    const dialog = h('dialog', {class:'preview-dialog'},
      h('header', {}, h('strong', {}, 'Предпросмотр · ссылка живёт один час'),
        iconButton('close', 'Закрыть', () => { dialog.close(); dialog.remove(); })),
      h('iframe', {src:preview.url, title:'Предпросмотр страницы', sandbox:'allow-same-origin'}));
    document.body.append(dialog);
    dialog.showModal();
    done('Предпросмотр готов.');
  })}, icon('eye'), 'Предпросмотр');
  actions.append(previewButton);

  const rail = h('aside', {class:'editor-rail'},
    h('section', {class:'card'},
      h('div', {class:'rail-section'},
        h('h3', {}, 'Состояние'),
        h('div', {class:'rail-status'}, badge(doc.state),
          h('div', {class:'rail-facts'},
            h('span', {}, 'Ревизия ', h('b', {}, doc.revision)),
            doc.published_revision ? h('span', {}, 'На сайте: ревизия ', h('b', {}, doc.published_revision)) : h('span', {}, 'Ещё не публиковалось'),
            doc.approved_by ? h('span', {}, 'Одобрил: ', h('b', {}, doc.approved_by)) : null))),
      h('div', {class:'rail-section'}, actions)),
    h('section', {class:'card'},
      h('div', {class:'rail-section'},
        h('h3', {}, 'История'),
        doc.history.length ? h('ol', {class:'timeline'}, [...doc.history].reverse().slice(0, 10).map(item =>
          h('li', {},
            h('strong', {}, ({saved:'Сохранено', submit:'Отправлено на проверку', approve:'Одобрено', reject:'Возвращено в работу', publish:'Поставлено в очередь'}[item.action] || labels[item.action] || item.action)),
            h('span', {}, item.actor + ' · ' + ago(item.at))))) : h('p', {class:'muted small'}, 'Правок в Studio ещё не было — материал взят из репозитория.'),
        h('div', {class:'mt-3'}, link('Все публикации', {view:'jobs'}, 'button btn--sm')))));

  const savebar = h('div', {class:'savebar'},
    h('span', {class:'savebar__state'}, 'Все изменения сохранены'),
    h('div', {class:'savebar__actions'},
      link('Отмена', {view:'pages', site}, 'button'),
      editable ? h('button', {type:'button', class:'primary', onclick:guard(save)}, 'Сохранить', h('kbd', {'aria-hidden':'true'}, 'Ctrl+S')) : null));

  if (!editable) form.querySelectorAll('input,textarea,select,button').forEach(control => control.disabled = true);

  const recovered = h('div');
  const stored = editable ? recovery.read() : null;
  if (stored && stored.revision === doc.revision && stored.body !== doc.body) {
    const bar = h('div', {class:'notice-card notice-card--alert'}, icon('alert'),
      h('div', {class:'recovery'},
        h('div', {},
          h('strong', {}, 'В этом браузере остались несохранённые правки'),
          h('p', {class:'muted small'}, 'Автосохранение от ' + ago(new Date(stored.at).toISOString()) + '. На сервере их нет.')),
        h('div', {class:'recovery__actions'},
          h('button', {type:'button', class:'primary btn--sm', onclick:() => { recovery.apply(stored); bar.remove(); done('Правки восстановлены. Проверьте и сохраните.'); }}, 'Восстановить'),
          h('button', {type:'button', class:'btn--sm', onclick:() => { recovery.forget(); bar.remove(); }}, 'Отклонить'))));
    recovered.append(bar);
  } else if (stored) {
    // The document moved on since the autosave, so the copy is stale, not useful.
    recovery.forget();
  }

  shell({
    title:null,
    breadcrumb:[['Сайты', {view:'sites'}], [sites.find(s => s.id === site)?.title || site, {view:'pages', site}], doc.front_matter.title || 'Новый материал'],
    actions:[badge(doc.state)],
  },
    recovered,
    errors,
    h('div', {class:'editor-grid'}, form, rail),
    savebar);

  document.addEventListener('keydown', function handler(event) {
    if (!document.body.contains(savebar)) { document.removeEventListener('keydown', handler); return; }
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') { event.preventDefault(); if (editable) guard(save)(); }
  });

  errorsPanel(doc.errors, errors);
  setDirty(false);
  if (location.hash) revealField(document.getElementById(location.hash.slice(1)));
}

async function settingsView(site, create) {
  const result = await api('settings', undefined, {site:site || sites[0]?.id || 'us'});
  const c = result.config;
  const identity = c.site_identity;
  const fields = {};
  const form = h('form', {class:'settings-form'});
  const error = h('p', {role:'alert', class:'field-error'});

  /** A titled group of fields; `input()` writes into whichever one is open. */
  let bodyEl;
  const section = (title, hint) => {
    bodyEl = h('div', {class:'card__body'});
    return h('section', {class:'card'},
      h('div', {class:'card__head'}, h('div', {}, h('h2', {}, title), hint ? h('p', {}, hint) : null)), bodyEl);
  };

  function input(key, label, value, type = 'text', options = null, hint = null) {
    const control = options
      ? h('select', {id:'setting-' + key}, Object.entries(options).map(([k, v]) => h('option', {value:k, selected:k === value}, v)))
      : h('input', {id:'setting-' + key, type, value:type === 'checkbox' ? undefined : value, checked:type === 'checkbox' ? value : undefined});
    control.name = key;
    fields[key] = control;
    bodyEl.append(h('div', {class:'field'}, h('label', {for:control.id}, label), control, hint ? h('small', {class:'field__hint'}, hint) : null));
  }

  const basics = section('Основное', 'Как сайт называется и по какому адресу живёт.');
  if (create) {
    input('site', 'Уникальный ID сайта', '', 'text', null, 'Латиница, цифры, дефис. Он же — имя каталога контента.');
    input('from', 'Копировать настройки с', sites[0]?.id || 'us', 'text', Object.fromEntries(sites.map(s => [s.id, s.title])));
  }
  input('title', 'Название', create ? '' : c.title);
  input('description', 'Описание', create ? '' : c.description);
  input('baseurl', 'Домен', create ? 'https://' : c.baseurl, 'text', null, 'DNS, Nginx и сертификат настраивает администратор сервера.');

  const market = section('Рынок и язык', 'Определяют валюту, формат дат и связи между языковыми версиями.');
  input('brand', 'Бренд', create ? 'new-brand' : identity.brand);
  input('market', 'Рынок / страна', identity.market);
  input('language', 'Язык', identity.language);
  input('locale', 'Локаль', identity.locale);
  input('hreflang', 'Язык и регион для поиска', c.geo.hreflang);
  input('translation_group', 'Группа переводов', create ? 'new-brand' : identity.translation_group, 'text', null, 'Сайты одной группы связываются между собой через hreflang.');

  const look = section('Оформление', 'Как сайт выглядит для читателя и какие поля есть у его страниц.');
  input('theme', 'Тема оформления', identity.theme, 'text', result.themes, 'Цвета, ширины и скругления всего сайта. Применится при следующей публикации.');
  input('accent', 'Акцентный цвет', c.appearance?.tokens?.['c-accent'] || '#6b42d9', 'color', null, 'Перекрывает акцент выбранной темы.');
  input('model', 'Модель страниц', identity.model, 'text', result.models, 'Какие типы страниц и какие поля у них есть. Менять на живом сайте рискованно.');

  const publishing = section('Публикация', 'Как сайт ведёт себя при сборке сети и в поиске.');
  input('staging', 'Тестовый сайт: запретить индексацию', create ? true : !!c.geo.staging, 'checkbox');
  input('enabled', 'Включать в общую сборку', create ? false : !!c.geo.enabled, 'checkbox');
  bodyEl.append(h('p', {class:'muted small'}, 'Настройки применяются при следующей сборке и публикации.'), error);

  const extra = {};
  // Appearance gets its own tab: it is the thing people look for first, and it
  // has nothing to do with domains or hreflang.
  const panels = [
    {id:'general', label:'Название и домен', icon:'gear', panel:h('div', {class:'tab-panel'}, basics, market, publishing)},
    {id:'appearance', label:'Внешний вид', icon:'palette', panel:h('div', {class:'tab-panel'}, look)},
  ];

  if (!create) {
    const routes = field('routes', {type:'object', label:'Адреса разделов', hideLabel:true, fields:Object.fromEntries(Object.keys(c.routes || {}).map(key => [key, {label:key}]))}, c.routes, 'routes', {});
    extra.routes = routes;
    const routesCard = section('Адреса разделов', 'Префиксы без ведущего и конечного «/». Старые адреса опубликованных страниц получат 301. Изменение сбрасывает одобрение затронутых черновиков.');
    bodyEl.append(routes);
    panels.push({id:'routes', label:'Адреса', icon:'chain', panel:h('div', {class:'tab-panel'}, routesCard)});

    // Interface labels are single-line strings; 'text' would render a textarea.
    const uiSchema = values => Object.fromEntries(Object.entries(values).map(([key, value]) => [key, typeof value === 'object' ? {type:'object', label:key, fields:uiSchema(value)} : {label:key}]));
    const ui = field('ui', {type:'object', label:'Тексты интерфейса', hideLabel:true, fields:uiSchema(c.ui)}, c.ui, 'ui', {});
    extra.ui = ui;
    const uiCard = section('Переводы и подписи', 'Надписи кнопок, разделов и служебных блоков на языке сайта.');
    bodyEl.append(ui);
    panels.push({id:'labels', label:'Тексты', icon:'doc', panel:h('div', {class:'tab-panel'}, uiCard)});

    const navValues = Object.fromEntries(['main','footer'].map(menu => [menu, (c.navigation?.[menu] || []).map(item => ({id:item.id, text:item.text || c.ui.nav[item.label] || item.id}))]));
    const nav = field('navigation', {type:'object', label:'Меню сайта', hideLabel:true, fields:Object.fromEntries([['main','Основное меню'],['footer','Подвал']].map(([key, label]) => [key, {type:'list', label, items:{type:'object', fields:{id:{label:'ID страницы (about или reviews/example)'}, text:{label:'Подпись'}}}}]))}, navValues, 'navigation', {});
    extra.navigation = nav;
    const navCard = section('Навигация', 'Ссылки появятся после публикации соответствующих страниц. ID главной — index.');
    bodyEl.append(nav);
    panels.push({id:'navigation', label:'Навигация', icon:'menu', panel:h('div', {class:'tab-panel'}, navCard)});

    // Only the manual map is editable. The redirects derived from the aliases of
    // a renamed published page are shown, because otherwise a 301 appears out of
    // nowhere and nobody can find where it is configured.
    const manual = Object.entries(c.redirects || {}).map(([from, to]) => ({from, to}));
    const redirects = field('redirects', {type:'list', label:'Редиректы', hideLabel:true,
      items:{type:'object', fields:{from:{label:'Старый адрес, например /old-page/'}, to:{label:'Куда вести, например /reviews/new-page/'}}}}, manual, 'redirects', {});
    extra.redirects = redirects;
    const auto = Object.entries(result.redirects_auto || {});
    const redirectCard = section('Редиректы', 'Постоянные 301 внутри сайта. Адреса пишутся со слэшами: /old/ → /reviews/new/. Цель должна быть опубликована, циклы отклоняются при сохранении.');
    bodyEl.append(redirects);
    if (auto.length) {
      bodyEl.append(h('div', {class:'mt-5'},
        h('p', {class:'field__label'}, 'Появились сами, после переименования страниц'),
        h('p', {class:'muted small'}, 'Они живут в поле aliases соответствующей страницы. Чтобы убрать такой редирект, правьте страницу.'),
        h('div', {class:'table-card mt-3'}, h('table', {class:'table'},
          h('thead', {}, h('tr', {}, h('th', {}, 'Старый адрес'), h('th', {}, 'Ведёт на'))),
          h('tbody', {}, auto.map(([from, to]) => h('tr', {},
            h('td', {}, h('span', {class:'mono'}, from)),
            h('td', {}, h('span', {class:'mono'}, to)))))))));
    }
    panels.push({id:'redirects', label:'Редиректы', icon:'back', panel:h('div', {class:'tab-panel'}, redirectCard)});
  }

  form.addEventListener('input', () => setDirty(true));
  form.append(tabs(panels));

  form.addEventListener('submit', async event => {
    event.preventDefault();
    try {
      const data = Object.fromEntries(Object.entries(fields).map(([k, v]) => [k, v.type === 'checkbox' ? v.checked : v.value]));
      Object.entries(extra).forEach(([key, node]) => { data[key] = node.read(); });
      await api('site-save', {...data, site:create ? data.site : site, create});
      setDirty(false);
      done('Настройки сохранены.');
      navigate({view:'pages', site:create ? data.site : site});
    } catch (e) { error.textContent = e.message; fail(e.message); }
  });

  const savebar = h('div', {class:'savebar'},
    h('span', {class:'savebar__state'}, 'Все изменения сохранены'),
    h('div', {class:'savebar__actions'},
      link('Отмена', {view:'sites'}, 'button'),
      h('button', {type:'submit', class:'primary', onclick:() => form.requestSubmit()}, create ? 'Создать сайт' : 'Сохранить')));

  const open = panels.find(panel => panel.id === (params().get('tab') || 'general')) || panels[0];
  const siteTitle = sites.find(s => s.id === site)?.title || site;
  const hints = {
    general:'Как сайт называется, где он живёт и участвует ли в общей сборке.',
    appearance:'Тема, акцентный цвет и набор полей у страниц.',
    routes:'Из чего складываются адреса разделов.',
    labels:'Подписи кнопок и разделов на языке сайта.',
    navigation:'Что стоит в меню и в подвале.',
    redirects:'Постоянные 301 внутри сайта.',
  };

  shell({
    narrow:true,
    title:create ? 'Новый сайт' : open.label,
    subtitle:create
      ? 'Бренд, рынок, язык и оформление задаются для каждого сайта независимо.'
      : (hints[open.id] || siteTitle),
    breadcrumb:[['Сайты', {view:'sites'}], create ? 'Новый сайт' : siteTitle, create ? null : open.label].filter(Boolean),
  }, form, savebar);
  setDirty(false);
}

async function jobsView() {
  const {jobs} = await api('jobs');
  const rows = jobs.map(job => h('tr', {},
    h('td', {}, link(job.page, {view:'editor', site:job.site, page:job.page}, 'table__title'),
      h('span', {class:'table__path'}, job.site + ' · ' + job.id)),
    h('td', {class:'shrink'}, badge(job.state)),
    h('td', {class:'shrink muted'}, ago(job.created_at)),
    h('td', {class:'shrink'}, session.user.role !== 'author' ? button('Журнал', guard(async () => {
      const result = await api('job-log', undefined, {id:job.id});
      const dialog = h('dialog', {},
        h('header', {}, h('strong', {}, 'Журнал задания ' + job.id), iconButton('close', 'Закрыть', () => { dialog.close(); dialog.remove(); })),
        h('pre', {}, result.log));
      document.body.append(dialog);
      dialog.showModal();
    }), 'btn--sm') : null)));

  const details = jobs.filter(job => job.state === 'built' || (job.errors || []).length).map(job => h('div', {class:'card'},
    h('div', {class:'card__body'},
      h('b', {}, job.site + ' / ' + job.page),
      job.state === 'built' ? h('p', {class:'muted small'}, 'Проверки пройдены, архив создан, деплой не запускался. Для выпуска нужен worker с доступом к серверу.') : null,
      ...(job.errors || []).map(error => h('p', {class:'field-error'}, error.edit_url ? h('a', {href:error.edit_url}, error.message) : error.message)))));

  shell({
    title:'Публикации',
    subtitle:'Состояние очереди, сборки и деплоя.',
    breadcrumb:['Публикации'],
    actions:[h('button', {type:'button', onclick:guard(render)}, 'Обновить')],
  }, jobs.length
    ? h('div', {}, h('div', {class:'table-card'}, h('table', {class:'table'},
        h('thead', {}, h('tr', {}, h('th', {}, 'Материал'), h('th', {}, 'Состояние'), h('th', {}, 'Создано'), h('th', {}))), h('tbody', {}, rows))),
        details.length ? h('div', {class:'stack mt-5'}, details) : null)
    : h('div', {class:'table-card'}, emptyState('Заданий пока нет', 'Они появятся после одобрения и отправки материала на публикацию.')));
}

/* -------------------------------------------------------------- catalogue
   Products and affiliate links are one database shared by the whole network:
   a price or a partner URL is set once per market, not once per site. The two
   files behind it (data/products, data/affiliates) are edited together here,
   because from an editor's point of view they are one record. */

async function catalogView() {
  const {products, markets, redirect_base:redirectBase} = await api('catalog');
  const codes = Object.keys(markets);

  const rows = products.map(product => {
    const missing = codes.filter(code => !product.markets.includes(code));
    return h('tr', {},
      h('td', {}, link(product.name, {view:'product', id:product.id}, 'table__title'),
        h('span', {class:'table__path'}, product.id)),
      h('td', {class:'shrink'}, product.rating == null
        ? h('span', {class:'muted small'}, '—')
        : h('span', {class:'chip'}, String(product.rating))),
      h('td', {}, h('div', {class:'chips'},
        product.markets.map(code => h('span', {class:'chip'}, code.toUpperCase())),
        missing.map(code => h('span', {class:'chip chip--warn', title:'Нет цены и описания для этого рынка'}, code.toUpperCase() + ' ?')))),
      h('td', {class:'shrink'}, product.links
        ? h('span', {class:'chip'}, product.links + ' ' + plural(product.links, 'ссылка', 'ссылки', 'ссылок'))
        : h('span', {class:'chip chip--warn', title:'Без партнёрской ссылки кнопка ведёт на официальный сайт'}, 'нет ссылок')),
      h('td', {class:'shrink muted'}, product.used_by.length
        ? product.used_by.length + ' ' + plural(product.used_by.length, 'страница', 'страницы', 'страниц')
        : 'нигде'));
  });

  shell({
    title:'Товары',
    subtitle:'Один каталог на всю сеть: цена, описание и партнёрская ссылка задаются для каждого рынка.',
    breadcrumb:['Товары'],
    actions:[link('Новый товар', {view:'product', create:'1'}, 'button primary')],
  },
    h('div', {class:'notice-card'}, icon('info'),
      h('div', {},
        h('strong', {}, redirectBase ? 'Ссылки идут через редирект-сервис' : 'Ссылки вшиты в страницы'),
        h('p', {class:'muted small'}, redirectBase
          ? 'Смена партнёрской ссылки применяется после обновления редирект-карты — пересобирать сайты не нужно. Цены попадут на сайт при следующей публикации.'
          : 'Цены и партнёрские ссылки попадают на сайт только при следующей сборке и публикации. Чтобы менять ссылки без пересборки, задайте affiliate.redirect_base в config/common.yml.'))),
    products.length
      ? h('div', {class:'table-card'}, h('table', {class:'table'},
          h('thead', {}, h('tr', {}, h('th', {}, 'Товар'), h('th', {}, 'Рейтинг'), h('th', {}, 'Рынки'), h('th', {}, 'Ссылки'), h('th', {}, 'Используется'))),
          h('tbody', {}, rows)))
      : h('div', {class:'table-card'}, emptyState('Каталог пуст', 'Добавьте первый товар, чтобы ссылаться на него из обзоров и рейтингов.')));
}

async function productView(id, create) {
  const {markets, redirect_base:redirectBase} = await api('catalog');
  const loaded = create ? null : await api('product', undefined, {id});
  const product = loaded?.product || {};
  const affiliate = loaded?.affiliate || {default:'', geo:{}};
  const usedBy = loaded?.used_by || [];
  const fields = {};
  const form = h('form', {class:'settings-form'});
  const error = h('p', {role:'alert', class:'field-error'});

  let bodyEl;
  const section = (title, hint) => {
    bodyEl = h('div', {class:'card__body'});
    return h('section', {class:'card'},
      h('div', {class:'card__head'}, h('div', {}, h('h2', {}, title), hint ? h('p', {}, hint) : null)), bodyEl);
  };
  const input = (key, label, value, type = 'text', options = null, hint = null) => {
    const control = options
      ? h('select', {id:'product-' + key}, Object.entries(options).map(([k, v]) => h('option', {value:k, selected:k === String(value)}, v)))
      : h('input', {id:'product-' + key, type, value:type === 'checkbox' ? undefined : (value ?? ''), checked:type === 'checkbox' ? !!value : undefined});
    fields[key] = control;
    bodyEl.append(h('div', {class:'field'}, h('label', {for:control.id}, label), control, hint ? h('small', {class:'field__hint'}, hint) : null));
    return control;
  };
  const periods = {month:'в месяц', year:'в год', week:'в неделю', once:'разово'};

  const identity = section('Товар', 'Общие данные — они одинаковы на всех рынках.');
  if (create) input('id', 'ID товара', '', 'text', null, 'Латиница, цифры, дефис. По нему на товар ссылаются обзоры и рейтинги.');
  input('name', 'Название', product.name);
  input('website', 'Официальный сайт', product.website, 'url', null, 'Куда ведёт кнопка, если партнёрской ссылки нет.');
  input('logo', 'Логотип', product.logo, 'text', null, 'Путь внутри сайта: /images/products/name.svg');
  input('category', 'Категория Schema.org', product.category || 'SoftwareApplication');
  input('platforms', 'Платформы', product.platforms, 'text', null, 'Через запятую: Web, iOS, Android.');
  input('rating', 'Ваша оценка', product.rating, 'number', null, 'От 0 до 5. Пусто — не показывать.');
  input('launched', 'Год запуска', product.launched, 'number');
  input('free_tier', 'Есть бесплатный тариф', !!product.free_tier, 'checkbox');

  const base = section('Цена по умолчанию', 'Используется, когда для рынка не задана своя цена.');
  input('price_amount', 'Сумма', product.price?.amount, 'number');
  input('price_period', 'Период', product.price?.period || 'month', 'text', periods);

  const featuresField = field('features', {type:'list', label:'Возможности', items:{type:'string'}}, product.features || [], 'features', {});
  const featuresCard = section('Возможности', 'Список, который выводится в карточке товара.');
  bodyEl.append(featuresField);

  const links = section('Партнёрская ссылка по умолчанию', 'Подставляется для рынков без собственной ссылки.');
  input('affiliate_default', 'Ссылка', affiliate.default, 'url');

  // One tab per market: price, wording and partner link live together, because
  // that is the set of things you check before a campaign in that country.
  const marketFields = {};
  const marketPanels = Object.values(markets).map(market => {
    const code = market.code;
    const geo = product.geo?.[code] || {};
    const url = affiliate.geo?.[code]?.url || '';
    const scoped = {};
    const put = (key, label, value, type = 'text', options = null, hint = null) => {
      const control = options
        ? h('select', {id:'market-' + code + '-' + key}, Object.entries(options).map(([k, v]) => h('option', {value:k, selected:k === String(value)}, v)))
        : h('input', {id:'market-' + code + '-' + key, type, value:value ?? ''});
      scoped[key] = control;
      bodyEl.append(h('div', {class:'field'}, h('label', {for:control.id}, label), control, hint ? h('small', {class:'field__hint'}, hint) : null));
    };
    marketFields[code] = scoped;

    const where = market.sites.map(s => s.title).join(', ');
    const offer = section('Предложение · ' + market.name, where ? 'Показывается на: ' + where : 'Пока нет сайтов для этого рынка.');
    put('availability', 'Доступность', geo.availability || 'available', 'text', {available:'Доступен', limited:'Ограниченно', unavailable:'Недоступен'});
    put('tagline', 'Короткое описание', geo.tagline, 'text', null, 'Одна фраза на языке рынка — она идёт в карточку и в подборки.');
    put('best_for', 'Чем хорош', geo.best_for, 'text', null, 'Например: генерация изображений.');
    put('amount', 'Цена' + (market.currency ? ', ' + market.currency : ''), geo.price?.amount, 'number', null, 'Пусто — берётся цена по умолчанию.');
    put('period', 'Период', geo.price?.period || product.price?.period || 'month', 'text', periods);

    const linkCard = section('Партнёрская ссылка · ' + market.name,
      redirectBase ? 'Изменение применится после обновления редирект-карты.' : 'Изменение попадёт на сайт при следующей сборке.');
    put('url', 'Ссылка', url, 'url', null, 'Пусто — используется ссылка по умолчанию.');

    // No icon: four identical glyphs would say less than the market names do.
    return {id:'m-' + code, label:market.name,
      panel:h('div', {class:'tab-panel'}, offer, linkCard)};
  });

  bodyEl.append(error);
  form.addEventListener('input', () => setDirty(true));
  form.append(tabs([
    {id:'general', label:'Товар', icon:'grid',
     panel:h('div', {class:'tab-panel'}, identity, base, featuresCard, links)},
    ...marketPanels,
  ]));

  const collect = () => {
    const data = {
      id:create ? fields.id.value.trim() : id,
      create,
      name:fields.name.value,
      website:fields.website.value,
      logo:fields.logo.value,
      category:fields.category.value,
      platforms:fields.platforms.value,
      rating:fields.rating.value,
      launched:fields.launched.value,
      free_tier:fields.free_tier.checked,
      features:featuresField.read().filter(Boolean),
      price:{amount:fields.price_amount.value, period:fields.price_period.value},
      geo:{},
      affiliate:{default:fields.affiliate_default.value, geo:{}},
    };
    for (const [code, scoped] of Object.entries(marketFields)) {
      const filled = scoped.tagline.value || scoped.best_for.value || scoped.amount.value || scoped.availability.value !== 'available';
      if (filled) {
        data.geo[code] = {
          availability:scoped.availability.value,
          tagline:scoped.tagline.value,
          best_for:scoped.best_for.value,
          price:{amount:scoped.amount.value, period:scoped.period.value},
        };
      }
      if (scoped.url.value.trim()) data.affiliate.geo[code] = {url:scoped.url.value.trim()};
    }
    return data;
  };

  const save = guard(async () => {
    try {
      const data = collect();
      await api('product-save', data);
      setDirty(false);
      done('Каталог сохранён. На сайты изменения попадут при следующей публикации.');
      navigate({view:'product', id:data.id});
    } catch (e) { error.textContent = e.message; throw e; }
  });
  form.addEventListener('submit', event => { event.preventDefault(); save(); });

  const rail = h('aside', {class:'editor-rail'},
    h('section', {class:'card'},
      h('div', {class:'rail-section'},
        h('h3', {}, 'Где используется'),
        usedBy.length
          ? h('ul', {class:'rail-list'}, usedBy.slice(0, 12).map(use =>
              h('li', {}, link(use.title, {view:'editor', site:use.site, page:use.page}),
                h('span', {}, use.site.toUpperCase() + ' · ' + use.page))))
          : h('p', {class:'muted small'}, create ? 'Товар ещё не создан.' : 'Ни один материал не ссылается на этот товар.'),
        usedBy.length > 12 ? h('p', {class:'muted small mt-3'}, 'и ещё ' + (usedBy.length - 12)) : null)),
    create ? null : h('section', {class:'card'},
      h('div', {class:'rail-section'},
        h('h3', {}, 'Удаление'),
        h('p', {class:'muted small'}, usedBy.length
          ? 'Товар используется в материалах — сначала уберите ссылки на него.'
          : 'Товар нигде не используется, его можно удалить.'),
        h('div', {class:'mt-3'}, h('button', {type:'button', class:'btn--danger btn--sm', disabled:usedBy.length > 0, onclick:guard(async () => {
          if (!confirm('Удалить товар «' + (product.name || id) + '» вместе с партнёрскими ссылками?')) return;
          await api('product-delete', {id});
          dirty = false;
          done('Товар удалён.');
          navigate({view:'catalog'});
        })}, 'Удалить товар')))));

  const savebar = h('div', {class:'savebar'},
    h('span', {class:'savebar__state'}, 'Все изменения сохранены'),
    h('div', {class:'savebar__actions'},
      link('Отмена', {view:'catalog'}, 'button'),
      h('button', {type:'button', class:'primary', onclick:save}, create ? 'Создать товар' : 'Сохранить')));

  shell({
    narrow:true,
    title:create ? 'Новый товар' : (product.name || id),
    subtitle:create ? 'Каталог общий для всей сети.' : 'ID: ' + id,
    breadcrumb:[['Товары', {view:'catalog'}], create ? 'Новый товар' : (product.name || id)],
  }, h('div', {class:'editor-grid'}, form, rail), savebar);
  setDirty(false);
}

const plural = (n, one, few, many) => n % 10 === 1 && n % 100 !== 11 ? one
  : [2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100) ? few : many;

/* --------------------------------------------------------------------- seo
   Two questions the build cannot answer, because both only exist between
   pages: where the translations have holes, and what is wrong across a site. */

const auditCodes = {
  duplicate:'Дубли', orphan:'Нет входящих ссылок', thin:'Мало текста',
  alt:'Изображения без alt', noindex:'Закрыта от индексации',
};

async function seoView(site) {
  const {audit, hreflang} = await api('seo', undefined, site ? {site} : {});

  /* ---- audit ---- */
  const errors = audit.findings.filter(f => f.level === 'error').length;
  const tone = errors ? 'alert' : audit.findings.length ? 'info' : 'check';
  const auditRows = audit.findings.map(finding => h('tr', {},
    h('td', {}, link(finding.title || finding.page, {view:'editor', site:finding.site, page:finding.page, ...(finding.field ? {} : {})}, 'table__title'),
      h('span', {class:'table__path'}, finding.site + ' · ' + finding.page)),
    h('td', {class:'shrink'}, h('span', {class:'chip chip--' + (finding.level === 'error' ? 'danger' : 'warn')}, auditCodes[finding.code] || finding.code)),
    h('td', {}, h('span', {class:'muted small'}, finding.message)),
    h('td', {class:'shrink'}, finding.status === 'published' ? badge('published') : badge('draft'))));

  const auditPanel = h('div', {class:'tab-panel'},
    h('div', {class:'notice-card notice-card--' + tone}, icon(tone),
      h('div', {},
        h('strong', {}, audit.findings.length
          ? `${audit.findings.length} ${plural(audit.findings.length, 'замечание', 'замечания', 'замечаний')} на ${audit.checked} ${plural(audit.checked, 'странице', 'страницах', 'страницах')}`
          : 'Замечаний нет'),
        h('p', {class:'muted small'}, 'Это не блокирует публикацию. Сборка отдельно проверяет обязательные поля, ссылки и разметку — здесь только то, что видно между страницами.'))),
    audit.findings.length
      ? h('div', {class:'table-card'}, h('table', {class:'table'},
          h('thead', {}, h('tr', {}, h('th', {}, 'Страница'), h('th', {}, 'Что'), h('th', {}, 'Подробности'), h('th', {}, 'Состояние'))),
          h('tbody', {}, auditRows)))
      : h('div', {class:'table-card'}, emptyState('Всё чисто', 'Дублей, пустых alt и страниц без входящих ссылок не нашлось.')));

  /* ---- hreflang ---- */
  const groups = hreflang.map(group => {
    const head = h('tr', {}, h('th', {}, 'Ключ перевода'),
      ...group.sites.map(s => h('th', {}, s.market.toUpperCase(), s.staging ? h('span', {class:'chip chip--warn'}, 'тест') : null)));
    const rows = group.keys.map(row => h('tr', {},
      h('td', {}, h('span', {class:'table__title'}, row.title), h('span', {class:'table__path'}, row.key)),
      ...group.sites.map(s => {
        const cell = row.sites[s.id];
        if (!cell) return h('td', {class:'shrink'}, h('span', {
          class:'chip chip--' + (s.staging ? 'muted' : 'danger'),
          title:s.staging ? 'Перевода нет, но сайт тестовый — в hreflang он не участвует' : 'Перевода нет: страница выпадает из hreflang этой группы',
        }, s.staging ? '—' : 'нет'));
        return h('td', {class:'shrink'}, link(cell.status === 'published' ? 'есть' : 'черновик',
          {view:'editor', site:s.id, page:cell.page},
          'chip chip--' + (cell.status === 'published' ? 'ok' : 'warn')));
      })));
    return h('section', {class:'card'},
      h('div', {class:'card__head'}, h('div', {},
        h('h2', {}, 'Группа переводов: ' + group.group),
        h('p', {}, group.sites.length + ' ' + plural(group.sites.length, 'сайт', 'сайта', 'сайтов')
          + ' · ' + group.keys.length + ' ' + plural(group.keys.length, 'ключ', 'ключа', 'ключей')
          + (group.untranslatable.length ? ' · без ключа: ' + group.untranslatable.length : '')))),
      h('div', {class:'table-wrap'}, h('table', {class:'table'}, h('thead', {}, head), h('tbody', {}, rows))),
      group.untranslatable.length
        ? h('div', {class:'card__body'}, h('p', {class:'muted small'},
            'Без translation_key, поэтому не связываются с другими странами: '
            + group.untranslatable.slice(0, 6).map(u => u.site + '/' + u.page).join(', ')
            + (group.untranslatable.length > 6 ? ' и ещё ' + (group.untranslatable.length - 6) : '')))
        : null);
  });

  shell({
    title:'SEO',
    subtitle:'Проверки, которые видны только поверх всей сети.',
    breadcrumb:['SEO'],
  }, tabs([
    {id:'audit', label:'Аудит', icon:'check', panel:auditPanel},
    {id:'hreflang', label:'Переводы', icon:'sites', panel:h('div', {class:'tab-panel'}, groups.length ? groups : emptyState('Групп переводов нет', 'Свяжите сайты общей группой переводов в настройках.'))},
  ]));
}

/* ----------------------------------------------------------------- history
   What Git used to provide. Every write Studio makes to content, config or
   data is recorded, so a publication can be compared with what it replaced
   and put back. */

const historyActions = {
  import:'Снимок при подключении', publish:'Публикация', restore:'Восстановление',
  'site.created':'Сайт создан', 'site.updated':'Настройки сайта',
  'product.created':'Товар создан', 'product.updated':'Товар изменён', 'product.deleted':'Товар удалён',
};

function bytes(n) {
  if (n < 1024) return n + ' Б';
  if (n < 1024 * 1024) return Math.round(n / 1024) + ' КБ';
  return (n / 1024 / 1024).toFixed(1) + ' МБ';
}

async function historyView(pathFilter) {
  const {entries, stats, untracked} = await api('history', undefined, pathFilter ? {path:pathFilter} : {});

  const rows = entries.map(entry => h('tr', {},
    h('td', {}, entry.sha
      ? link(entry.path, {view:'version', path:entry.path, sha:entry.sha}, 'table__title')
      : h('span', {class:'table__title'}, entry.path),
      h('span', {class:'table__path'}, historyActions[entry.action] || entry.action, entry.note ? ' · ' + entry.note : '')),
    h('td', {class:'shrink'}, entry.sha ? h('span', {class:'chip'}, entry.sha.slice(0, 8)) : h('span', {class:'chip chip--warn'}, 'удалён')),
    h('td', {class:'shrink muted'}, entry.actor),
    h('td', {class:'shrink muted'}, ago(entry.at))));

  shell({
    title:'История правок',
    subtitle:`${stats.versions} ${plural(stats.versions, 'версия', 'версии', 'версий')} по ${stats.files} ${plural(stats.files, 'файлу', 'файлам', 'файлам')} · ${bytes(stats.bytes)} в хранилище`,
    breadcrumb:pathFilter ? [['История', {view:'history'}], pathFilter] : ['История'],
    actions:pathFilter ? [link('Вся история', {view:'history'}, 'button')] : [],
  },
    untracked ? h('div', {class:'notice-card'}, icon('alert'),
      h('div', {},
        h('strong', {}, untracked + ' ' + plural(untracked, 'файл ещё не в истории', 'файла ещё не в истории', 'файлов ещё не в истории')),
        h('p', {class:'muted small'}, 'Они появились в рабочем каталоге мимо Studio. Снимите слепок, чтобы дальше отслеживать их изменения.'),
        h('div', {class:'mt-3'}, h('button', {type:'button', class:'btn--sm', onclick:guard(async () => {
          const {recorded} = await api('history-baseline', {});
          done('Записано версий: ' + recorded + '.');
          await render();
        })}, 'Снять слепок')))) : null,
    entries.length
      ? h('div', {class:'table-card'}, h('table', {class:'table'},
          h('thead', {}, h('tr', {}, h('th', {}, 'Файл'), h('th', {}, 'Версия'), h('th', {}, 'Кто'), h('th', {}, 'Когда'))),
          h('tbody', {}, rows)))
      : h('div', {class:'table-card'}, emptyState('Правок пока нет', 'Здесь появится каждое изменение контента, настроек и товаров.')));
}

async function versionView(path, sha) {
  const data = await api('history-version', undefined, sha ? {path, sha} : {path});
  const isCurrent = data.sha === data.current;

  const diff = h('div', {class:'diff'}, data.diff.length
    ? data.diff.map(line => h('div', {class:'diff__line diff__line--' + ({' ':'same', '-':'del', '+':'add'}[line.op])},
        h('span', {class:'diff__gutter'}, line.op === ' ' ? '' : line.op),
        h('code', {}, line.text || ' ')))
    : h('p', {class:'muted small'}, 'Файл пуст.'));

  const versions = h('ol', {class:'timeline'}, data.versions.map(version => {
    const active = version.sha === data.sha;
    return h('li', {class:active ? 'is-active' : ''},
      version.sha
        ? link(historyActions[version.action] || version.action, {view:'version', path, sha:version.sha})
        : h('strong', {}, 'Файл удалён'),
      h('span', {}, version.actor + ' · ' + ago(version.at)));
  }));

  shell({
    title:path.split('/').pop(),
    subtitle:path,
    breadcrumb:[['История', {view:'history'}], path],
    actions:[link('Все правки файла', {view:'history', path}, 'button')],
  },
    h('div', {class:'editor-grid'},
      h('section', {class:'card'},
        h('div', {class:'card__head'}, h('div', {},
          h('h2', {}, data.previous ? 'Что изменилось' : 'Первая версия'),
          h('p', {}, data.previous
            ? 'Слева — эта версия по сравнению с предыдущей.'
            : 'Предыдущей версии нет, показано всё содержимое.'))),
        diff),
      h('aside', {class:'editor-rail'},
        h('section', {class:'card'},
          h('div', {class:'rail-section'},
            h('h3', {}, 'Версия'),
            h('div', {class:'rail-facts'},
              h('span', {}, 'ID ', h('b', {}, (data.sha || '').slice(0, 12))),
              h('span', {}, isCurrent ? 'Сейчас на диске' : 'Отличается от файла на диске')),
            h('div', {class:'mt-3'},
              isCurrent
                ? h('p', {class:'muted small'}, 'Это текущее состояние файла.')
                : h('button', {type:'button', class:'primary', onclick:guard(async () => {
                    if (!confirm('Вернуть файл ' + path + ' к этой версии? Текущее состояние тоже сохранится в истории.')) return;
                    await api('history-restore', {path, sha:data.sha});
                    done('Файл восстановлен. Изменения попадут на сайт при следующей публикации.');
                    navigate({view:'history', path});
                  })}, 'Восстановить эту версию')))),
        h('section', {class:'card'},
          h('div', {class:'rail-section'}, h('h3', {}, 'Версии файла'), versions)))));
}

async function render() {
  try {
    session = await api('session');
    if (!session.user) { await renderLogin(); return; }
    ({sites} = await api('sites'));
    const p = params(), view = p.get('view') || 'sites', site = p.get('site') || sites[0]?.id;
    if (view === 'pages') await pagesView(site);
    else if (view === 'editor') await editorView(site, p.get('page'));
    else if (view === 'settings' || view === 'new-site') await settingsView(site, view === 'new-site');
    else if (view === 'catalog') await catalogView();
    else if (view === 'product') await productView(p.get('id') || '', p.get('create') === '1');
    else if (view === 'seo') await seoView(p.get('site'));
    else if (view === 'history') await historyView(p.get('path'));
    else if (view === 'version') await versionView(p.get('path') || '', p.get('sha'));
    else if (view === 'jobs') await jobsView();
    else if (view === 'users') await usersView();
    else await sitesView();
  } catch (error) { fail(error.message); }
}
render();
