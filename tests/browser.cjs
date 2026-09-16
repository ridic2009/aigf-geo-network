// Run after: npm install --prefix .cache/browser --no-audit --no-fund playwright
const {chromium} = require('../.cache/browser/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const {spawn, spawnSync} = require('node:child_process');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const fixture = path.join(root,'.studio','browser-tests',String(Date.now()));
fs.mkdirSync(fixture,{recursive:true});
for(const name of ['config','content','data','engine','static']) fs.cpSync(path.join(root,name),path.join(fixture,name),{recursive:true});
const env={...process.env,MINICMS_ROOT:fixture,STUDIO_DATA:path.join(fixture,'.studio')};
delete env.STUDIO_HTTPS; delete env.DEPLOY_HOSTS; delete env.DEPLOY_HOST;
const php=process.env.PHP_BINARY || 'php';
let server,browser;
let checks=0;
function check(ok,message){assert.ok(ok,message);checks++;console.log('PASS '+message);}
(async()=>{
  const port=await new Promise(resolve=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
  const url='http://127.0.0.1:'+port;
  server=spawn(php,['-S','127.0.0.1:'+port,'-t',path.join(root,'studio/public'),path.join(root,'studio/public/index.php')],{env,cwd:root,stdio:['ignore','pipe','pipe'],windowsHide:true});
  let serverLog='';server.stderr.on('data',d=>{serverLog+=d.toString();});
  for(let i=0;i<100;i++){try{await fetch(url+'/api?action=session');break;}catch{await new Promise(r=>setTimeout(r,100));}}
  const executablePath=process.env.CHROME_BINARY || (process.platform==='win32'?'C:/Program Files/Google/Chrome/Application/chrome.exe':undefined);
  browser=await chromium.launch({headless:true,...(executablePath?{executablePath}:{})});
  const context=await browser.newContext({viewport:{width:1440,height:1000}});
  const page=await context.newPage();const jsErrors=[];page.on('pageerror',e=>jsErrors.push(e.message));
  await page.goto(url);await page.getByLabel('Логин',{exact:true}).fill('admin');await page.getByLabel('Пароль',{exact:true}).fill('Browser-tests-only-123!');
  await page.getByRole('button',{name:'Создать администратора'}).click();await page.getByRole('heading',{name:'Сайты',exact:true}).waitFor();
  check(true,'first-run setup and login work in browser');
  fs.mkdirSync(path.join(root,'reports'),{recursive:true});await page.screenshot({path:path.join(root,'reports/studio-sites.png'),fullPage:true});
  async function request(ctx,action,data){const s=await (await ctx.request.get(url+'/api?action=session')).json();return ctx.request.post(url+'/api?action='+action,{headers:{'X-CSRF-Token':s.csrf},data});}
  const csrf=await context.request.post(url+'/api?action=site-save',{data:{site:'us'}});check(csrf.status()===403,'HTTP mutations require CSRF token');
  const again=await request(context,'setup',{login:'second-admin',password:'Browser-tests-only-123!'});check(again.status()===409,'bootstrap closes after first account');
  await page.locator('.topbar__actions').getByRole('link',{name:'Новый сайт',exact:true}).click();await page.getByRole('heading',{name:'Новый сайт'}).waitFor();
  const settings={site:'browser-us',title:'Northstar Reviews',description:'Independent reviews and detailed comparisons for curious readers.',baseurl:'https://browser.example.com/',brand:'northstar',market:'us',language:'en',locale:'en_US',hreflang:'en-US',translation_group:'northstar'};
  for(const [key,value] of Object.entries(settings))await page.locator('#setting-'+key).fill(value);
  await page.locator('#setting-theme').selectOption('editorial');await page.getByRole('button',{name:'Создать сайт',exact:true}).click();
  await page.getByText('Northstar Reviews').first().waitFor();check(true,'new brand/site created through UI');
  await page.goto(url+'/?view=settings&site=browser-us');
  await page.getByRole('tab',{name:'Тексты',exact:true}).click();await page.locator('#field-ui-labels-home').fill('Our home');
  await page.getByRole('tab',{name:'Адреса',exact:true}).click();await page.locator('#field-routes-reviews').fill('tested');
  await page.locator('.savebar .primary').click();await page.getByText('Настройки сохранены.').waitFor();
  const updatedSettings=await (await context.request.get(url+'/api?action=settings&site=browser-us')).json();
  check(updatedSettings.config.ui.labels.home==='Our home' && updatedSettings.config.routes.reviews==='tested','site settings expose translations and route prefixes');
  for(const [login,role] of [['author','author'],['editor','editor']]){const r=await request(context,'user-create',{login,password:'Browser-tests-only-123!',role,sites:['browser-us']});check(r.ok(),'admin creates '+role+' with scoped access');}
  const author=await browser.newContext();const editor=await browser.newContext();
  for(const [ctx,login]of [[author,'author'],[editor,'editor']]){const r=await request(ctx,'login',{login,password:'Browser-tests-only-123!'});assert.equal(r.status(),200);}
  const scope=await author.request.get(url+'/api?action=pages&site=us');check(scope.status()===403,'site scope enforced over HTTP');
  const denied=await request(author,'site-save',{site:'browser-us',title:'Hijacked'});check(denied.status()===403,'author cannot change site settings over HTTP');
  // Approve a homepage so the disabled new site can be built as an explicit target.
  const home={site:'browser-us',page:'index.md',revision:0,front_matter:{type:'homepage',title:'Northstar Reviews',seo:{title:'Northstar Reviews',description:'Independent reviews and detailed comparisons for curious readers.'}},body:'Welcome to Northstar Reviews.'};
  const saved=await (await request(author,'save',home)).json();check(saved.ok,'author saves page through HTTP');
  const submit=await (await request(author,'transition',{site:home.site,page:home.page,revision:1,transition:'submit'})).json();check(submit.document.state==='review','review state comes from persisted workflow');
  const forged=await request(author,'transition',{site:home.site,page:home.page,revision:1,transition:'approve'});check(forged.status()===403,'forged author approval rejected over HTTP');
  await request(editor,'transition',{site:home.site,page:home.page,revision:1,transition:'approve'});
  await request(editor,'transition',{site:home.site,page:home.page,revision:1,transition:'publish'});
  const worker=spawnSync(php,[path.join(root,'scripts/studio.php'),'worker'],{env,cwd:root,encoding:'utf8',windowsHide:true});
  if(worker.status!==0) console.log(worker.stdout+worker.stderr);
  check(worker.status===0 && worker.stdout.includes('built'),'HTTP queue consumed by real CLI worker without deploying');
  await page.goto(url+'/?view=editor&site=browser-us&page=index.md');await page.getByRole('heading',{name:'Содержание',exact:true}).waitFor();
  await page.getByRole('tab',{name:'Блоки',exact:true}).click();await page.getByRole('button',{name:'Текст',exact:true}).click();await page.locator('#field-blocks-0-heading').fill('Why trust our reviews');await page.locator('#field-blocks-0-body').fill('Every review follows a clear editorial checklist.');
  await page.locator('.savebar .primary').click();await page.getByText(/Ревизия\s*2/).first().waitFor();
  check(true,'block editor saves typed data and invalidates approval');
  // Inactive tabs are hidden, never detached: saving reads every field back from
  // the DOM, so a removed panel would be written out as empty.
  const kept=await (await context.request.get(url+'/api?action=document&site=browser-us&page=index.md')).json();
  check(kept.document.front_matter.seo.title==='Northstar Reviews','fields on an inactive tab survive a save');
  check(kept.document.body.includes('Welcome to Northstar Reviews'),'body on an inactive tab survives a save');
  check(new URL(page.url()).searchParams.get('tab')==='blocks','the open tab is shareable through the URL');
  const submittedUI=await (await request(context,'transition',{site:home.site,page:home.page,revision:2,transition:'submit'})).json();
  check(submittedUI.ok,'browser-generated form with blank optional fields passes submission');
  await request(editor,'transition',{site:home.site,page:home.page,revision:2,transition:'approve'});
  await request(editor,'transition',{site:home.site,page:home.page,revision:2,transition:'publish'});
  const secondWorker=spawnSync(php,[path.join(root,'scripts/studio.php'),'worker'],{env,cwd:root,encoding:'utf8',windowsHide:true});
  if(secondWorker.status!==0) console.log(secondWorker.stdout+secondWorker.stderr);
  check(secondWorker.status===0 && secondWorker.stdout.includes('built'),'full browser-generated document and blocks build without optional-field errors');
  await page.reload();await page.getByRole('tab',{name:'Содержание',exact:true}).click();
  // An <input> with no type attribute once matched none of the form selectors
  // and rendered at the browser's default 27px next to 37px siblings.
  const short=await page.evaluate(()=>[...document.querySelectorAll('input:not([type=checkbox]):not([type=color]):not([type=file])')].filter(i=>i.offsetParent).map(i=>[i.id||i.name||i.type,Math.round(i.getBoundingClientRect().height)]).filter(([,h])=>h<34));
  check(short.length===0,'every text field carries the form styling: '+JSON.stringify(short));
  await page.screenshot({path:path.join(root,'reports/studio-editor.png'),fullPage:true});
  await page.getByRole('button',{name:'Предпросмотр',exact:true}).click();await page.getByRole('dialog').waitFor({timeout:20000});
  await page.frameLocator('iframe').getByRole('heading',{name:'Why trust our reviews'}).waitFor();check(true,'Cecil preview renders the saved block with actual site template');
  const frame=page.frames().find(f=>f.url().includes('/preview/'));const previewUrl=frame.url();
  const anonymous=await browser.newContext();const blocked=await anonymous.request.get(previewUrl);check(blocked.status()===401,'preview URL cannot be read anonymously');
  await page.getByRole('dialog').getByRole('button',{name:'Закрыть',exact:true}).click();
  await page.goto(url+'/?view=sites');await page.getByRole('heading',{name:'Сайты',exact:true}).waitFor();
  check((await page.locator('.site-group__title').count())===2,'sites are grouped by brand once the workspace holds more than one');
  await page.screenshot({path:path.join(root,'reports/studio-sites.png'),fullPage:true});
  await page.goto(url+'/?view=editor&site=browser-us&page=index.md');await page.getByRole('tab',{name:'Содержание',exact:true}).waitFor();
  await page.setViewportSize({width:390,height:844});
  // let the drawer transition finish and the toasts expire, otherwise the
  // reference screenshot catches the sidebar mid-slide
  await page.waitForFunction(()=>!document.querySelector('.toast'),null,{timeout:12000}).catch(()=>{});
  await page.screenshot({path:path.join(root,'reports/studio-mobile.png'),fullPage:true});
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'editor fits a mobile viewport without horizontal overflow');
  check(jsErrors.length===0,'no browser JavaScript exceptions: '+jsErrors.join('; '));
  console.log(checks+' browser/HTTP checks passed.');
})().catch(error=>{console.error(error);process.exitCode=1;}).finally(async()=>{if(browser)await browser.close();if(server)server.kill();});
