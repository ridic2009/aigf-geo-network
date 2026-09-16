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

function revealField(target) {
  for (let parent = target?.parentElement; parent; parent = parent.parentElement) if (parent.tagName === 'DETAILS') parent.open = true;
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
 * options: {title, subtitle, breadcrumb: [[text, query]|text], actions: [Node]}
 */
function shell(options, ...children) {
  const {title, subtitle, breadcrumb = [], actions = []} = options;
  const site = params().get('site') || sites[0]?.id;
  const view = params().get('view') || 'sites';
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
    h('nav', {class:'nav', 'aria-label':'Разделы'},
      navLink('grid', 'Все сайты', {view:'sites'}, view === 'sites'),
      site ? navLink('doc', 'Материалы', {view:'pages', site}, ['pages', 'editor'].includes(view)) : null,
      navLink('rocket', 'Публикации', {view:'jobs'}, view === 'jobs'),
      site && admin ? navLink('gear', 'Настройки сайта', {view:'settings', site}, view === 'settings') : null,
      admin ? h('p', {class:'nav__label'}, 'Администрирование') : null,
      admin ? navLink('plus', 'Новый сайт', {view:'new-site'}, view === 'new-site') : null,
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

  const root = h('div', {class:'app'}, aside,
    h('div', {},
      h('div', {class:'topbar'},
        iconButton('menu', 'Меню', () => root.classList.toggle('drawer-open'), 'btn--icon drawer-toggle'),
        crumbs,
        h('div', {class:'topbar__actions'}, ...actions)),
      h('main', {id:'main', class:'content'},
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

function errorsPanel(issues, container) {
  document.querySelectorAll('[aria-invalid="true"]').forEach(control => {
    control.removeAttribute('aria-invalid');
    control.removeAttribute('aria-describedby');
    document.getElementById(control.id + '-error')?.remove();
  });
  container.replaceChildren();
  if (!issues?.length) return;
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
        const child = field(String(i + 1), {...(rule.items || {type:'string'}), hideLabel:true}, item, path + '.' + i, schema);
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
  if (!rule.hideLabel) wrap.append(h('label', {for:id}, label));
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
  dialog.querySelector('header button svg path').setAttribute('d', 'M6 6l12 12M18 6 6 18');

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
  const cards = sites.map(site => h('article', {class:'site-card'},
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
      session.user.role === 'admin' ? link('Настройки', {view:'settings', site:site.id}, 'button') : null)));

  shell({
    title:'Сайты',
    subtitle:sites.length + (sites.length % 10 === 1 && sites.length % 100 !== 11 ? ' сайт' : [2,3,4].includes(sites.length % 10) && ![12,13,14].includes(sites.length % 100) ? ' сайта' : ' сайтов') + ' в рабочем пространстве',
    breadcrumb:['Сайты'],
    actions:session.user.role === 'admin' ? [link('Новый сайт', {view:'new-site'}, 'button primary')] : [],
  }, sites.length ? h('div', {class:'site-grid'}, cards) : emptyState('Сайтов пока нет', 'Создайте первый сайт, чтобы начать.'));
}

async function pagesView(site) {
  const [{pages}, schema] = await Promise.all([api('pages', undefined, {site}), api('schema', undefined, {site})]);
  const current = sites.find(s => s.id === site);

  const search = h('input', {type:'search', placeholder:'Название или путь…', 'aria-label':'Найти материал'});
  const state = h('select', {'aria-label':'Фильтр по состоянию'}, h('option', {value:''}, 'Любое состояние'),
    Object.entries(labels).filter(([k]) => !['staging','production'].includes(k)).map(([k, v]) => h('option', {value:k}, v)));
  const count = h('span', {class:'toolbar__count'});
  const tbody = h('tbody');

  const paint = () => {
    const visible = pages.filter(p => (!state.value || p.state === state.value) && `${p.title} ${p.page}`.toLowerCase().includes(search.value.toLowerCase()));
    count.textContent = visible.length === pages.length ? `${pages.length} материалов` : `${visible.length} из ${pages.length}`;
    tbody.replaceChildren(...visible.map(p => h('tr', {},
      h('td', {}, link(p.title || 'Без названия', {view:'editor', site, page:p.page}, 'table__title'),
        h('span', {class:'table__path'}, p.page)),
      h('td', {class:'shrink muted'}, schema.types[p.type]?.label || p.type),
      h('td', {class:'shrink'}, badge(p.state)))));
    if (!visible.length) tbody.replaceChildren(h('tr', {}, h('td', {colspan:'3'},
      emptyState(pages.length ? 'Ничего не найдено' : 'Материалов пока нет',
        pages.length ? 'Измените условия поиска.' : 'Создайте первый материал для этого сайта.'))));
  };
  search.addEventListener('input', paint);
  state.addEventListener('change', paint);
  paint();

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
  dialog.querySelector('header button svg path').setAttribute('d', 'M6 6l12 12M18 6 6 18');

  shell({
    title:'Материалы',
    subtitle:current ? current.title : site,
    breadcrumb:[['Сайты', {view:'sites'}], 'Материалы'],
    actions:[h('button', {type:'button', class:'primary', onclick:() => dialog.showModal()}, icon('plus'), 'Новый материал')],
  },
    h('div', {class:'toolbar'},
      h('div', {class:'search'}, icon('search'), search),
      state, count),
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

  const contentCard = h('section', {class:'card'},
    h('div', {class:'card__head'}, h('div', {}, h('h2', {}, 'Содержание'))));
  const contentBody = h('div', {class:'card__body'});
  contentCard.append(contentBody);

  const advanced = h('details', {}, h('summary', {}, 'Обложка, авторы и служебные поля'));
  const body = h('textarea', {id:'field-body', name:'body', rows:16, class:'body-editor'}, doc.body);
  body.addEventListener('input', () => setDirty(true));

  for (const [name, rule] of Object.entries(schema.types[doc.front_matter.type].fields)) {
    const f = field(name, rule, doc.front_matter[name], name, schema);
    readers[name] = f;
    if (name === 'seo' || name === 'indexing') continue;
    if (name === 'slug' && doc.front_matter.type === 'homepage') continue;
    if (['canonical','image','image_alt','breadcrumb_title','date','updated','author','reviewer','related','translation_key'].includes(name)) { advanced.append(f); continue; }
    contentBody.append(f);
  }
  contentBody.append(
    h('div', {class:'field'},
      h('label', {for:'field-body'}, 'Основной текст'),
      h('small', {class:'field__hint'}, 'Markdown: ## подзаголовок, **выделение**, [ссылка](/adres/). Заголовок страницы берётся из поля выше.'),
      body),
    advanced);

  const seoCard = h('section', {class:'card'},
    h('div', {class:'card__head'}, h('div', {}, h('h2', {}, 'Поисковая выдача'),
      h('p', {}, 'Canonical, hreflang и языковые теги формируются движком автоматически.'))),
    h('div', {class:'card__body'}, readers.seo, readers.indexing));

  const blocks = blocksEditor(doc.front_matter.blocks, schema);
  form.append(contentCard, seoCard, blocks);

  const save = async () => {
    const fm = Object.fromEntries(Object.entries(readers).map(([k, f]) => [k, f.read()]));
    fm.type = doc.front_matter.type;
    fm.blocks = blocks.read();
    const saved = await api('save', {site, page, revision:doc.revision, front_matter:fm, body:body.value});
    if (!saved.ok) { errorsPanel(saved.errors, errors); fail('Проверьте выделенные поля.'); return false; }
    setDirty(false);
    done('Сохранено.');
    await render();
    return true;
  };
  form.addEventListener('submit', guard(async event => { event.preventDefault(); await save(); }));

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
    dialog.querySelector('header button svg path').setAttribute('d', 'M6 6l12 12M18 6 6 18');
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

  shell({
    title:null,
    breadcrumb:[['Сайты', {view:'sites'}], [sites.find(s => s.id === site)?.title || site, {view:'pages', site}], doc.front_matter.title || 'Новый материал'],
    actions:[badge(doc.state)],
  },
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
  const form = h('form', {class:'card'});
  const bodyEl = h('div', {class:'card__body'});
  const error = h('p', {role:'alert', class:'field-error'});

  function input(key, label, value, type = 'text', options = null, hint = null) {
    const control = options
      ? h('select', {id:'setting-' + key}, Object.entries(options).map(([k, v]) => h('option', {value:k, selected:k === value}, v)))
      : h('input', {id:'setting-' + key, type, value:type === 'checkbox' ? undefined : value, checked:type === 'checkbox' ? value : undefined});
    control.name = key;
    fields[key] = control;
    bodyEl.append(h('div', {class:'field'}, h('label', {for:control.id}, label), control, hint ? h('small', {class:'field__hint'}, hint) : null));
  }

  if (create) {
    input('site', 'Уникальный ID сайта', '', 'text', null, 'Латиница, цифры, дефис. Он же — имя каталога контента.');
    input('from', 'Копировать настройки с', sites[0]?.id || 'us', 'text', Object.fromEntries(sites.map(s => [s.id, s.title])));
  }
  input('title', 'Название', create ? '' : c.title);
  input('description', 'Описание', create ? '' : c.description);
  input('baseurl', 'Домен', create ? 'https://' : c.baseurl, 'text', null, 'DNS, Nginx и сертификат настраивает администратор сервера.');
  input('brand', 'Бренд', create ? 'new-brand' : identity.brand);
  input('market', 'Рынок / страна', identity.market);
  input('language', 'Язык', identity.language);
  input('locale', 'Локаль', identity.locale);
  input('hreflang', 'Язык и регион для поиска', c.geo.hreflang);
  input('translation_group', 'Группа переводов', create ? 'new-brand' : identity.translation_group, 'text', null, 'Сайты одной группы связываются между собой через hreflang.');
  input('model', 'Модель страниц', identity.model, 'text', result.models);
  input('theme', 'Тема оформления', identity.theme, 'text', result.themes);
  input('accent', 'Акцентный цвет', c.appearance?.tokens?.['c-accent'] || '#6b42d9', 'color');
  input('staging', 'Тестовый сайт: запретить индексацию', create ? true : !!c.geo.staging, 'checkbox');
  input('enabled', 'Включать в общую сборку', create ? false : !!c.geo.enabled, 'checkbox');

  const extra = {};
  if (!create) {
    const routes = field('routes', {type:'object', label:'Адреса разделов', fields:Object.fromEntries(Object.keys(c.routes || {}).map(key => [key, {label:key}]))}, c.routes, 'routes', {});
    bodyEl.append(h('details', {}, h('summary', {}, 'Адреса разделов'),
      h('p', {class:'muted small'}, 'Префиксы без ведущего и конечного «/». Старые адреса опубликованных страниц получат 301. Изменение сбрасывает одобрение затронутых черновиков.'),
      routes));
    extra.routes = routes;

    const uiSchema = values => Object.fromEntries(Object.entries(values).map(([key, value]) => [key, typeof value === 'object' ? {type:'object', label:key, fields:uiSchema(value)} : {type:'text', label:key}]));
    const ui = field('ui', {type:'object', label:'Тексты интерфейса', fields:uiSchema(c.ui)}, c.ui, 'ui', {});
    bodyEl.append(h('details', {}, h('summary', {}, 'Переводы и подписи'), ui));
    extra.ui = ui;

    const navValues = Object.fromEntries(['main','footer'].map(menu => [menu, (c.navigation?.[menu] || []).map(item => ({id:item.id, text:item.text || c.ui.nav[item.label] || item.id}))]));
    const nav = field('navigation', {type:'object', label:'Меню сайта', fields:Object.fromEntries([['main','Основное меню'],['footer','Подвал']].map(([key, label]) => [key, {type:'list', label, items:{type:'object', fields:{id:{label:'ID страницы (about или reviews/example)'}, text:{label:'Подпись'}}}}]))}, navValues, 'navigation', {});
    bodyEl.append(h('details', {}, h('summary', {}, 'Навигация'),
      h('p', {class:'muted small'}, 'Ссылки появятся после публикации соответствующих страниц. ID главной — index.'),
      nav));
    extra.navigation = nav;
  }

  form.addEventListener('input', () => setDirty(true));
  bodyEl.append(h('p', {class:'muted small'}, 'Настройки применяются при следующей сборке и публикации.'), error);
  form.append(h('div', {class:'card__head'}, h('div', {}, h('h2', {}, create ? 'Новый сайт' : 'Настройки сайта'),
    h('p', {}, 'Бренд, рынок, язык и оформление задаются для каждого сайта независимо.'))), bodyEl);

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

  shell({
    breadcrumb:[['Сайты', {view:'sites'}], create ? 'Новый сайт' : (sites.find(s => s.id === site)?.title || site), create ? null : 'Настройки'].filter(Boolean),
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
      dialog.querySelector('header button svg path').setAttribute('d', 'M6 6l12 12M18 6 6 18');
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

async function render() {
  try {
    session = await api('session');
    if (!session.user) { await renderLogin(); return; }
    ({sites} = await api('sites'));
    const p = params(), view = p.get('view') || 'sites', site = p.get('site') || sites[0]?.id;
    if (view === 'pages') await pagesView(site);
    else if (view === 'editor') await editorView(site, p.get('page'));
    else if (view === 'settings' || view === 'new-site') await settingsView(site, view === 'new-site');
    else if (view === 'jobs') await jobsView();
    else if (view === 'users') await usersView();
    else await sitesView();
  } catch (error) { fail(error.message); }
}
render();
