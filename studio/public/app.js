'use strict';
const app = document.querySelector('#app');
const labels = {draft:'Черновик', review:'На проверке', approved:'Одобрено', publishing:'Публикуется', published:'Опубликовано', publication_failed:'Ошибка публикации', queued:'В очереди', built:'Собрано — без деплоя', source:'Исходник', cancelled:'Отменено', staging:'Тестовый сайт', production:'Production'};
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
const button = (text, fn, kind = '') => h('button', {type:'button', class:kind, onclick:fn}, text);
const badge = state => h('span', {class:'badge state-' + state}, labels[state] || state);
const notice = text => { document.querySelector('#notice').textContent = text; };
const params = () => new URLSearchParams(location.search);
function revealField(target) {
  for (let parent=target?.parentElement; parent; parent=parent.parentElement) if(parent.tagName==='DETAILS')parent.open=true;
  target?.scrollIntoView({block:'center'});
}
async function api(action, data, query = {}) {
  const options = data === undefined ? {} : {method:'POST', headers:{'Content-Type':'application/json', 'X-CSRF-Token':session.csrf}, body:JSON.stringify(data)};
  const result = await fetch('/api?' + new URLSearchParams({action, ...query}), options);
  const json = await result.json();
  if (!result.ok || (!json.ok && !json.errors)) throw new Error(json.message || 'Не удалось выполнить действие.');
  return json;
}
function guard(fn) { return async (...args) => { try { await fn(...args); } catch (error) { notice(error.message); } }; }
function navigate(query = {}) {
  if (dirty && !confirm('Есть несохранённые изменения. Покинуть страницу?')) return;
  dirty = false; history.pushState(null, '', '/?' + new URLSearchParams(query)); render();
}
window.addEventListener('popstate', () => { dirty = false; render(); });
window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
function link(text, query, cls = '') { return h('a', {href:'/?' + new URLSearchParams(query), class:cls, onclick:event => { event.preventDefault(); navigate(query); }}, text); }
function shell(title, subtitle, ...children) {
  const site = params().get('site') || sites[0]?.id;
  const view = params().get('view') || 'sites';
  app.replaceChildren(h('div', {class:'shell'},
    h('aside', {class:'sidebar'}, link('◈ MiniCMS', {}, 'logo'), h('p', {class:'workspace-label'}, 'STUDIO / РАБОЧЕЕ ПРОСТРАНСТВО'),
      h('nav', {'aria-label':'Главное меню'}, link('Все сайты', {view:'sites'}, view === 'sites' ? 'active' : ''),
        site ? link('Материалы', {view:'pages', site}, ['pages','editor'].includes(view) ? 'active' : '') : null,
        link('Публикации', {view:'jobs'}, view === 'jobs' ? 'active' : ''),
        session.user.role === 'admin' ? link('Создать сайт', {view:'new-site'}, view === 'new-site' ? 'active' : '') : null,
        session.user.role === 'admin' ? link('Команда', {view:'users'}, view === 'users' ? 'active' : '') : null),
      h('div', {class:'account'}, h('strong', {}, session.user.login), h('small', {}, {admin:'Администратор',editor:'Выпускающий редактор',author:'Автор'}[session.user.role]),
        button('Выйти', guard(async () => { await api('logout', {}); dirty = false; location.href = '/'; }), 'quiet'))),
    h('main', {id:'main', class:'workspace'}, h('header', {class:'page-heading'}, h('div', {}, h('p', {class:'eyebrow'}, 'ВАШ КОНТЕНТ. ВАШИ ПРАВИЛА.'), h('h1', {}, title), h('p', {class:'muted'}, subtitle))), ...children)));
}
function errorsPanel(issues, container) {
  document.querySelectorAll('[aria-invalid="true"]').forEach(control => { control.removeAttribute('aria-invalid'); control.removeAttribute('aria-describedby'); document.getElementById(control.id + '-error')?.remove(); });
  container.replaceChildren();
  if (!issues?.length) return;
  container.append(h('section', {class:'error-summary', tabindex:'-1', role:'alert'}, h('h2', {}, 'Исправьте перед продолжением'),
    h('ul', {}, issues.map(issue => h('li', {}, h('a', {href:issue.edit_url || '#field-' + (issue.field || 'body').replaceAll('.', '-'), onclick:event => {
      if (issue.site && (issue.site !== currentDocument?.site || issue.page !== currentDocument?.page)) return;
      event.preventDefault();
      const target = document.getElementById('field-' + (issue.field || 'body').replaceAll('.', '-')) || document.getElementById('field-body');
      revealField(target); (target?.matches('input,textarea,select') ? target : target?.querySelector('input,textarea,select,button'))?.focus();
    }}, issue.message))))));
  for (const issue of issues) {
    const field = document.getElementById('field-' + (issue.field || '').replaceAll('.', '-'));
    const control = field?.matches('input,textarea,select') ? field : field?.querySelector('input,textarea,select');
    if (control) { control.setAttribute('aria-invalid','true'); const id = control.id + '-error'; control.setAttribute('aria-describedby',id); control.after(h('small', {id, class:'field-error'}, issue.message)); }
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
    const group = h('fieldset', {id}, h('legend', {}, label)); const readers = {};
    for (const [key, child] of Object.entries(rule.fields || {})) { const f = field(key, child, value?.[key], path + '.' + key, schema); group.append(f); readers[key] = f; }
    wrap.append(group); wrap.read = () => Object.fromEntries(Object.entries(readers).map(([k,f]) => [k,f.read()])); return wrap;
  }
  if (type === 'list') {
    const group = h('fieldset', {id}, h('legend', {}, label)); const list = h('div', {class:'repeat-list'});
    let values = Array.isArray(value) ? value : [];
    const collect = () => [...list.children].map(row => row.reader.read());
    const paint = () => {
      list.replaceChildren(); values.forEach((item, i) => {
        const child = field(String(i + 1), rule.items || {type:'string'}, item, path + '.' + i, schema);
        const row = h('div', {class:'repeat-item'}, h('div', {class:'repeat-tools'}, h('span', {}, String(i + 1).padStart(2,'0')),
          button('↑', () => { values = collect(); if (i > 0) [values[i-1],values[i]] = [values[i],values[i-1]]; paint(); dirty = true; }),
          button('↓', () => { values = collect(); if (i < values.length-1) [values[i+1],values[i]] = [values[i],values[i+1]]; paint(); dirty = true; }),
          button('Удалить', () => { values = collect(); values.splice(i,1); paint(); dirty = true; }, 'quiet')), child);
        row.reader = child; list.append(row);
      });
    };
    group.append(list, button('+ Добавить', () => { values = collect(); values.push(rule.items?.type === 'object' ? {} : rule.items?.type === 'list' ? [] : ''); paint(); dirty = true; }));
    paint(); wrap.append(group); wrap.read = collect; return wrap;
  }
  let control;
  if (type === 'product' || rule.options) {
    const options = type === 'product' ? Object.entries(schema.products) : rule.options.map(x => [x,x]);
    control = h('select', {id}, h('option', {value:''}, 'Выберите…'), options.map(([key,text]) => h('option', {value:key, selected:key === value},text)));
  } else if (type === 'boolean') {
    control = h('input', {id, type:'checkbox', checked:value === undefined ? true : !!value});
  } else if (type === 'text') {
    control = h('textarea', {id, rows:4}, value || '');
  } else {
    let v = value ?? '';
    if (type === 'date' && typeof v === 'number') v = new Date(v*1000).toISOString().slice(0,10);
    control = h('input', {id, type:type === 'number' ? 'number' : type === 'date' ? 'date' : 'text', value:v, step:type === 'number' ? 'any' : null});
  }
  control.name = path;
  control.addEventListener('input', () => { control.removeAttribute('aria-invalid'); dirty = true; });
  wrap.append(h('label', {for:id},label),control);
  if (type === 'image') {
    const upload = h('input', {type:'file', accept:'image/png,image/jpeg,image/webp', 'aria-label':'Загрузить изображение', onchange:guard(async event => {
      if (!event.target.files[0]) return;
      const data = new FormData(); data.append('site',currentDocument.site); data.append('image',event.target.files[0]);
      const result = await fetch('/api?action=upload',{method:'POST',headers:{'X-CSRF-Token':session.csrf},body:data}); const json = await result.json();
      if (!json.ok) throw new Error(json.message); control.value = json.url; dirty = true; notice('Изображение загружено. Добавьте его описание.');
    })}); wrap.append(upload);
  }
  wrap.read = () => type === 'boolean' ? control.checked : type === 'number' ? (control.value === '' ? null : Number(control.value)) : control.value;
  return wrap;
}
function blocksEditor(value, schema) {
  const root = h('section', {class:'panel', id:'field-blocks'}, h('h2', {},'Блоки страницы'), h('p', {class:'muted'},'Соберите страницу из готовых секций. Порядок можно менять стрелками.'));
  const list = h('div', {class:'block-list'}); let values = value || [];
  const collect = () => [...list.children].map(node => ({type:node.blockType, ...Object.fromEntries(Object.entries(node.fields).map(([key,f]) => [key,f.read()]))}));
  function paint() {
    list.replaceChildren(); values.forEach((block,i) => {
      const def = schema.blocks[block.type]; if (!def) return;
      const row = h('section', {class:'block-card', id:'field-blocks-' + i}, h('header', {class:'block-header'}, h('strong', {},`${String(i+1).padStart(2,'0')} / ${def.label}`),
        h('div', {class:'actions'}, button('↑', () => { values = collect(); if (i) [values[i-1],values[i]] = [values[i],values[i-1]]; paint(); dirty = true; }),
          button('↓', () => { values = collect(); if (i<values.length-1) [values[i+1],values[i]] = [values[i],values[i+1]]; paint(); dirty = true; }),
          button('Удалить', () => { values = collect(); values.splice(i,1); paint(); dirty = true; },'quiet'))));
      row.blockType = block.type; row.fields = {};
      for (const [key,rule] of Object.entries(def.fields)) { const f = field(key,rule,block[key],`blocks.${i}.${key}`,schema); row.fields[key] = f; row.append(f); }
      list.append(row);
    });
  }
  paint(); root.append(list, h('div', {class:'block-palette'}, Object.entries(schema.blocks).map(([type,def]) => button('+ ' + def.label, () => { values = collect(); values.push({type}); paint(); dirty = true; }))));
  root.read = collect; return root;
}
async function renderLogin() {
  const setup = session.needs_setup;
  const login = h('input',{id:'login',name:'username',autocomplete:'username',required:true});
  const password = h('input',{id:'password',name:'password',type:'password',autocomplete:'current-password',required:true});
  const error = h('p',{role:'alert',class:'field-error'});
  app.replaceChildren(h('main',{id:'main',class:'login-screen'},h('section',{class:'login-card'},h('div',{class:'logo'},'◈ MiniCMS Studio'),h('h1',{},'Место, где рождаются сайты'),h('p',{class:'muted'},'Материалы, оформление и публикации — в одном пространстве.'),
    setup ? h('p',{},'Первый запуск: придумайте логин администратора и пароль от 12 до 72 байт.') : null,
    h('form',{onsubmit:async event=>{event.preventDefault();try { if(setup) await api('setup',{login:login.value,password:password.value}); const result = await api('login',{login:login.value,password:password.value});session=result;await render();} catch(e){error.textContent=e.message;} }},
      h('label',{for:'login'},'Логин'),login,h('label',{for:'password'},'Пароль'),password,error,h('button',{type:'submit',class:'primary'},setup?'Создать администратора':'Войти в Studio')),
    h('p',{class:'muted small'},'Первую учётную запись создаёт администратор.'))));
}
async function usersView() {
  const {users} = await api('users');
  const rows = users.map(user => {
    const role = h('select', {'aria-label':'Роль ' + user.login}, Object.entries({author:'Автор',editor:'Редактор',admin:'Администратор'}).map(([key,text])=>h('option',{value:key,selected:key===user.role},text)));
    const scope = h('input', {'aria-label':'Сайты ' + user.login,value:user.sites.join(',')});
    const enabled = h('input',{type:'checkbox',checked:user.enabled,'aria-label':'Активен ' + user.login});
    return h('div',{class:'panel'},h('h2',{},user.login),h('div',{class:'toolbar'},role,scope,enabled,button('Сохранить',guard(async()=>{await api('user-update',{login:user.login,role:role.value,sites:scope.value.split(',').map(s=>s.trim()).filter(Boolean),enabled:enabled.checked});notice('Права обновлены.');await render();}))));
  });
  const login=h('input',{id:'new-login',required:true,autocomplete:'off'}),password=h('input',{id:'new-password',type:'password',required:true,minlength:12,autocomplete:'new-password'});
  const role=h('select',{id:'new-role'},h('option',{value:'author'},'Автор'),h('option',{value:'editor'},'Редактор'),h('option',{value:'admin'},'Администратор'));
  const scopes=h('input',{id:'new-scopes',value:sites[0]?.id||'',required:true});
  const form=h('form',{class:'panel settings-form',onsubmit:guard(async event=>{event.preventDefault();await api('user-create',{login:login.value,password:password.value,role:role.value,sites:scopes.value.split(',').map(s=>s.trim()).filter(Boolean)});notice('Пользователь создан.');await render();})},h('h2',{},'Добавить участника'),h('label',{for:'new-login'},'Логин'),login,h('label',{for:'new-password'},'Начальный пароль'),password,h('label',{for:'new-role'},'Роль'),role,h('label',{for:'new-scopes'},'ID сайтов через запятую; * — все сайты'),scopes,h('button',{type:'submit',class:'primary'},'Создать участника'));
  shell('Команда','Роли проверяются сервером на каждом действии. Отключение аккаунта прекращает доступ.',h('div',{class:'job-list'},rows,form));
}
async function sitesView() {
  const cards = sites.map(site => h('article',{class:'site-card'},h('div',{class:'site-card-top'},h('span',{class:'site-monogram'},site.title.slice(0,1)),badge(site.staging?'staging':'production')),
    h('h2',{},site.title),h('p',{class:'muted domain'},site.baseurl),h('div',{class:'metadata'},h('span',{},site.identity.market.toUpperCase()),h('span',{},site.identity.locale),h('span',{},site.identity.theme)),
    h('div',{class:'actions'},link('Материалы →',{view:'pages',site:site.id},'button primary'),session.user.role === 'admin' ? link('Настройки',{view:'settings',site:site.id},'button') : null)));
  shell('Ваши сайты','Независимые бренды, рынки и языки. Один рабочий процесс.',h('div',{class:'stats'},h('strong',{},sites.length),h('span',{},'сайтов в вашем пространстве')),h('div',{class:'site-grid'},cards));
}
async function pagesView(site) {
  const [{pages},schema] = await Promise.all([api('pages',undefined,{site}),api('schema',undefined,{site})]);
  const search = h('input',{type:'search',placeholder:'Название или адрес…','aria-label':'Найти материал'});
  const state = h('select',{'aria-label':'Статус'},h('option',{value:''},'Все состояния'),Object.entries(labels).map(([k,v])=>h('option',{value:k},v)));
  const type = h('select',{'aria-label':'Тип новой страницы'},Object.entries(schema.types).filter(([k])=>k!=='homepage').map(([k,v])=>h('option',{value:k},v.label)));
  const slug = h('input',{placeholder:'novyy-obzor','aria-label':'Адрес новой страницы',pattern:'[a-z0-9]+(?:-[a-z0-9]+)*',required:true});
  const table = h('div',{class:'page-list'});
  const paint = () => {
    const visible = pages.filter(p=>(!state.value || p.state===state.value) && `${p.title} ${p.page}`.toLowerCase().includes(search.value.toLowerCase()));
    table.replaceChildren(...visible.map(p=>h('article',{class:'page-row'},h('div',{},link(p.title || 'Без названия',{view:'editor',site,page:p.page},'page-title'),h('small',{class:'muted'},p.page)),
      h('span',{class:'muted'},schema.types[p.type]?.label || p.type),badge(p.state))));
    if (!visible.length) table.append(h('p',{class:'empty'},'Материалов пока нет. Создайте первый.'));
  };
  search.addEventListener('input',paint);state.addEventListener('change',paint);paint();
  shell(sites.find(s=>s.id===site)?.title || site,'Пишите, согласовывайте и публикуйте. Исходники ещё не подтверждают фактический деплой.',
    h('div',{class:'toolbar'},search,state),h('details',{class:'panel'},h('summary',{},'+ Новый материал'),h('form',{class:'new-page',onsubmit:event=>{event.preventDefault();const section=schema.types[type.value].section; navigate({view:'editor',site,page:(section?section+'/':'')+slug.value+'.md',type:type.value});}},type,slug,h('button',{type:'submit',class:'primary'},'Создать'))),table);
}
async function editorView(site,page) {
  const [result,schema] = await Promise.all([api('document',undefined,{site,page,...(params().get('type')?{type:params().get('type')}:{})}),api('schema',undefined,{site})]);
  const doc = result.document; currentDocument = doc;
  const errors = h('div'); const readers = {}; const form = h('form',{class:'editor-form'});
  const common = h('section',{class:'panel'},h('h2',{},'Содержание'));
  const advanced = h('details',{},h('summary',{},'Обложка, авторы и дополнительные поля'));
  const body = h('textarea',{id:'field-body',name:'body',rows:16,class:'body-editor'},doc.body);
  body.addEventListener('input',()=>{dirty=true;});
  for (const [name,rule] of Object.entries(schema.types[doc.front_matter.type].fields)) {
    const f = field(name,rule,doc.front_matter[name],name,schema); readers[name]=f;
    if (name==='seo' || name==='indexing') continue;
    if (name==='slug' && doc.front_matter.type==='homepage') continue;
    if (['canonical','image','image_alt','breadcrumb_title','date','updated','author','reviewer','related','translation_key'].includes(name)) { advanced.append(f);continue; }
    common.append(f);
  }
  common.append(h('label',{for:'field-body'},'Основной текст · Markdown'),h('small',{class:'muted'},'## Заголовок, **выделение**, [ссылка](/adres/). Главный заголовок уже берётся из названия.'),body);
  common.append(advanced);
  const blocks = blocksEditor(doc.front_matter.blocks,schema);
  form.append(common,h('section',{class:'panel'},h('h2',{},'Поисковая выдача'),readers.seo,readers.indexing),blocks);
  const workflow = h('aside',{class:'workflow panel'},h('p',{class:'eyebrow'},'РЕДАКТОРСКИЙ ПРОЦЕСС'),badge(doc.state),h('p',{class:'muted'},'Ревизия '+doc.revision),
    doc.published_revision ? h('p',{},'На сайте: ревизия '+doc.published_revision) : h('p',{class:'muted'},'Studio ещё не подтверждала публикацию этой страницы.'),
    doc.approved_by ? h('p',{},'Одобрил: '+doc.approved_by) : null);
  const save = async () => {
    const fm = Object.fromEntries(Object.entries(readers).map(([k,f])=>[k,f.read()])); fm.type=doc.front_matter.type; fm.blocks=blocks.read();
    const result = await api('save',{site,page,revision:doc.revision,front_matter:fm,body:body.value});
    if (!result.ok) {errorsPanel(result.errors,errors);return false;}
    dirty=false;notice('Сохранено. Для публикации отправьте ревизию на проверку.');await render();return true;
  };
  form.addEventListener('submit',guard(async event=>{event.preventDefault();await save();}));
  const transition = action => guard(async()=>{
    if(dirty){notice('Сначала сохраните изменения. Согласование относится к конкретной ревизии.');return;}
    const result = await api('transition',{site,page,revision:doc.revision,transition:action});
    if(!result.ok){errorsPanel(result.errors,errors);return;}
    notice(action==='publish'?'Задание поставлено в очередь. Статус обновится после работы публикационного процесса.':'Состояние обновлено.');await render();
  });
  const editable = doc.state !== 'publishing' && (session.user.role!=='author' || !doc.owner || doc.owner===session.user.login);
  if(editable) workflow.append(button('Сохранить черновик',guard(save),'primary'));
  if(editable && ['draft','publication_failed'].includes(doc.state)) workflow.append(button('Отправить на проверку',transition('submit')));
  if(session.user.role!=='author') {
    if(doc.state==='review') workflow.append(button('Одобрить ревизию',transition('approve'),'primary'));
    if(['review','approved','publication_failed'].includes(doc.state)) workflow.append(button('Вернуть в работу',transition('reject')));
    if(['approved','publication_failed'].includes(doc.state)) workflow.append(button('Опубликовать',transition('publish'),'primary'));
  }
  workflow.append(button('Предпросмотр',guard(async()=>{
    if(dirty){notice('Сохраните изменения, чтобы увидеть их в предпросмотре.');return;}
    notice('Собираю предпросмотр…');const result=await api('preview',{site,page});
    const dialog=h('dialog',{class:'preview-dialog'},h('header',{class:'actions'},h('strong',{},'Предпросмотр · доступен один час'),button('Закрыть',()=>{dialog.close();dialog.remove();})),h('iframe',{src:result.url,title:'Предпросмотр страницы',sandbox:'allow-same-origin'}));
    document.body.append(dialog);dialog.showModal();notice('Предпросмотр готов.');
  })),link('История публикаций',{view:'jobs'},'button quiet'));
  workflow.append(h('hr'),h('h3',{},'История'),h('ol',{class:'history'},[...doc.history].reverse().slice(0,12).map(item=>h('li',{},h('strong',{},({saved:'Сохранено',submit:'На проверке',approve:'Одобрено',reject:'Возвращено',publish:'В очереди'}[item.action]||labels[item.action]||item.action)),h('small',{class:'muted'},item.actor+' · '+new Date(item.at).toLocaleString('ru'))))));
  if(!editable) form.querySelectorAll('input,textarea,select,button').forEach(control=>control.disabled=true);
  shell(doc.front_matter.title || 'Новый материал',site+' / '+page,link('← К материалам',{view:'pages',site},'back'),errors,h('div',{class:'editor-grid'},form,workflow));
  errorsPanel(doc.errors,errors);
  if(location.hash) revealField(document.getElementById(location.hash.slice(1)));
}
async function settingsView(site,create) {
  const result=await api('settings',undefined,{site:site||sites[0]?.id||'us'});const c=result.config;const identity=c.site_identity;
  const fields={};const form=h('form',{class:'panel settings-form'}); const error=h('p',{role:'alert',class:'field-error'});
  function input(key,label,value,type='text',options=null) {
    const control=options?h('select',{id:'setting-'+key},Object.entries(options).map(([k,v])=>h('option',{value:k,selected:k===value},v))):h('input',{id:'setting-'+key,type,value:type==='checkbox'?undefined:value,checked:type==='checkbox'?value:undefined});
    control.name=key;fields[key]=control;form.append(h('div',{class:'field'},h('label',{for:control.id},label),control));
  }
  if(create){input('site','Уникальный ID сайта','');input('from','Копировать настройки',sites[0]?.id||'us','text',Object.fromEntries(sites.map(s=>[s.id,s.title])));}
  input('title','Название',create?'':c.title);input('description','Описание',create?'':c.description);input('baseurl','Домен',create?'https://':c.baseurl);
  input('brand','Бренд',create?'new-brand':identity.brand);input('market','Рынок / страна',identity.market);input('language','Язык',identity.language);input('locale','Локаль',identity.locale);input('hreflang','Язык и регион для поиска',c.geo.hreflang);input('translation_group','Группа переводов',create?'new-brand':identity.translation_group);
  input('model','Модель страниц',identity.model,'text',result.models);input('theme','Тема',identity.theme,'text',result.themes);
  input('accent','Свой акцентный цвет',c.appearance?.tokens?.['c-accent']||'#6b42d9','color');
  input('staging','Тестовый сайт: запретить индексацию',create?true:!!c.geo.staging,'checkbox');input('enabled','Включить в общую сборку',create?false:!!c.geo.enabled,'checkbox');
  const extra = {};
  if (!create) {
    const routes = field('routes',{type:'object',label:'Адреса разделов',fields:Object.fromEntries(Object.keys(c.routes||{}).map(key=>[key,{label:key}]))},c.routes,'routes',{});
    form.append(h('details',{},h('summary',{},'Адреса разделов'),h('p',{class:'muted'},'Префиксы без начального и конечного /. Старые адреса опубликованных страниц получат 301. Изменения сбрасывают одобрение затронутых черновиков.'),routes)); extra.routes=routes;
    const uiSchema = values => Object.fromEntries(Object.entries(values).map(([key,value])=>[key,typeof value === 'object' ? {type:'object',label:key,fields:uiSchema(value)} : {type:'text',label:key}]));
    const ui = field('ui',{type:'object',label:'Тексты интерфейса',fields:uiSchema(c.ui)},c.ui,'ui',{});
    form.append(h('details',{},h('summary',{},'Переводы и подписи'),ui)); extra.ui=ui;
    const navValues = Object.fromEntries(['main','footer'].map(menu=>[menu,(c.navigation?.[menu]||[]).map(item=>({id:item.id,text:item.text||c.ui.nav[item.label]||item.id}))]));
    const nav = field('navigation',{type:'object',label:'Меню сайта',fields:Object.fromEntries([['main','Основное меню'],['footer','Подвал']].map(([key,label])=>[key,{type:'list',label,items:{type:'object',fields:{id:{label:'ID страницы (например about или reviews/example)'},text:{label:'Подпись'}}}}]))},navValues,'navigation',{});
    form.append(h('details',{},h('summary',{},'Навигация'),h('p',{class:'muted'},'Ссылки появятся после публикации соответствующих страниц. ID главной — index.'),nav)); extra.navigation=nav;
  }
  form.addEventListener('input',()=>{dirty=true;});
  form.append(h('p',{class:'muted'},'Настройки применяются при следующей сборке и публикации. Для нового домена администратор сервера настраивает DNS, Nginx и HTTPS.'),error,h('button',{type:'submit',class:'primary'},create?'Создать сайт':'Сохранить настройки'));
  form.addEventListener('submit',async event=>{event.preventDefault();try{const data=Object.fromEntries(Object.entries(fields).map(([k,v])=>[k,v.type==='checkbox'?v.checked:v.value]));Object.entries(extra).forEach(([key,node])=>{data[key]=node.read();});await api('site-save',{...data,site:create?data.site:site,create});dirty=false;notice('Настройки сохранены.');navigate({view:'pages',site:create?data.site:site});}catch(e){error.textContent=e.message;}});
  shell(create?'Новый сайт':'Настройки сайта','Бренд, рынок, язык и оформление задаются независимо.',form);
}
async function jobsView() {
  const {jobs}=await api('jobs');
  shell('Публикации','Статус отражает работу очереди, сборки и деплоя.',button('Обновить',guard(render)),h('div',{class:'job-list'},jobs.length?jobs.map(job=>h('article',{class:'panel job'},h('div',{class:'actions'},badge(job.state),h('small',{class:'muted'},new Date(job.created_at).toLocaleString('ru'))),link(job.site+' / '+job.page,{view:'editor',site:job.site,page:job.page},'page-title'),h('small',{class:'muted'},job.id),
    job.state==='built'?h('p',{},'Проверки пройдены, архив создан. Деплой не запускался. Для выпуска нужен worker с настроенным доступом к серверу.'):null,
    (job.errors||[]).map(error=>h('p',{class:'field-error'},error.edit_url?h('a',{href:error.edit_url},error.message):error.message)),
    session.user.role!=='author'?button('Журнал задания',guard(async()=>{const result=await api('job-log',undefined,{id:job.id});const dialog=h('dialog',{},button('Закрыть',()=>{dialog.close();dialog.remove();}),h('pre',{},result.log));document.body.append(dialog);dialog.showModal();})):null)):h('p',{class:'empty'},'Здесь появятся задания после одобрения и отправки на публикацию.')));
}
async function render() {
  try {
    session=await api('session');
    if(!session.user){await renderLogin();return;}
    ({sites}=await api('sites'));
    const p=params(),view=p.get('view')||'sites',site=p.get('site')||sites[0]?.id;
    if(view==='pages')await pagesView(site);
    else if(view==='editor')await editorView(site,p.get('page'));
    else if(view==='settings'||view==='new-site')await settingsView(site,view==='new-site');
    else if(view==='jobs')await jobsView();
    else if(view==='users')await usersView();
    else await sitesView();
  }catch(error){notice(error.message);}
}
render();
