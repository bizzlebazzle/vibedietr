import { before, after, test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { mkdirSync, writeFileSync, readFileSync } from 'node:fs';
import { setTimeout as delay } from 'node:timers/promises';
import { Builder, By, Key, until } from 'selenium-webdriver';
import chrome from 'selenium-webdriver/chrome.js';
import axe from 'axe-core';

const base = process.env.ACCESSIBILITY_BROWSER_URL || 'http://laravel.test:8015';
const artifacts = process.env.ACCESSIBILITY_BROWSER_ARTIFACTS || 'storage/app/testing/ux07';
const env = { ...process.env, APP_ENV: 'testing', APP_URL: base, DB_CONNECTION: 'mysql', DB_DATABASE: 'testing', SESSION_DRIVER: 'file', CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array', APP_DEBUG: 'false', TRUSTED_HOSTS: new URL(base).hostname, PULSE_ENABLED: 'false', TELESCOPE_ENABLED: 'false', NIGHTWATCH_ENABLED: 'false' };
let browser, server, fixture;
const find = selector => browser.findElement(By.css(selector));
const reports = [];

before(async () => {
    mkdirSync(artifacts, { recursive: true });
    execFileSync('php', ['artisan', 'migrate', '--force', '--no-interaction'], { env });
    fixture = JSON.parse(execFileSync('php', ['tests/Browser/accessibility-fixture.php'], { env, encoding: 'utf8' }));
    server = spawn('php', ['-S', '0.0.0.0:8015', '-t', 'public', 'tests/Browser/router.php'], { env, stdio: ['ignore', 'ignore', 'pipe'] });
    let log = '';
    server.stderr.on('data', chunk => { log += chunk; });
    let ready = false;
    for (let attempt = 0; attempt < 50; attempt++) {
        try { ready = (await fetch('http://127.0.0.1:8015/login', { signal: AbortSignal.timeout(500) })).ok; } catch {}
        if (ready) break;
        await delay(100);
    }
    assert.ok(ready, log);
    // Keep font antialiasing stable when prior keyboard interaction changes compositing.
    browser = await new Builder().forBrowser('chrome').usingServer(process.env.SELENIUM_URL || 'http://selenium:4444/wd/hub')
        .setChromeOptions(new chrome.Options().addArguments('--headless=new', '--no-sandbox', '--disable-dev-shm-usage', '--disable-lcd-text', '--window-size=1280,900')).build();
    await browser.manage().setTimeouts({ pageLoad: 30000, script: 30000 });
});
after(async () => {
    writeFileSync(`${artifacts}/axe.json`, JSON.stringify(reports, null, 2));
    try {
        if (browser) await browser.quit();
    } finally {
        if (server) server.kill();
        if (fixture) execFileSync('php', ['tests/Browser/accessibility-fixture.php', 'cleanup', JSON.stringify(fixture)], { env });
    }
});
async function open(path) { await browser.get(`${base}${path}`); }
async function login(role = 'owner') {
    await browser.manage().deleteAllCookies();
    await open('/login');
    await (await find('#email')).sendKeys(fixture.users[role].email, Key.TAB);
    await browser.actions().sendKeys('browser-fixture-password', Key.ENTER).perform();
    await browser.wait(until.urlContains('/dashboard'), 15000);
}
async function scan(name) {
    await browser.executeScript(axe.source);
    const result = await browser.executeAsyncScript(`const done = arguments[arguments.length-1];
        axe.run(document, {runOnly: {type:'tag', values:['wcag2a','wcag2aa','wcag21aa','wcag22aa','best-practice']}})
        .then(r=>done({violations:r.violations.map(v=>({id:v.id,impact:v.impact,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))})), incomplete:r.incomplete.map(v=>({id:v.id,targets:v.nodes.map(n=>n.target)}))})).catch(e=>done({error:e.message}));`);
    reports.push({ name, ...result });
    writeFileSync(`${artifacts}/axe.json`, JSON.stringify(reports, null, 2));
    assert.equal(result.error, undefined, name);
}
async function screenshot(name) {
    writeFileSync(`${artifacts}/${name}.png`, Buffer.from(await browser.takeScreenshot(), 'base64'));
}
async function tabTo(selector, limit = 100) {
    for (let i = 0; i < limit; i++) {
        if (await browser.executeScript('return document.activeElement.matches(arguments[0])', selector)) {
            assert.ok(await browser.executeScript(`const el=document.activeElement,r=el.getBoundingClientRect();
                const x=Math.max(0,Math.min(innerWidth-1,r.x+r.width/2)),y=Math.max(0,Math.min(innerHeight-1,r.y+r.height/2));
                const top=document.elementFromPoint(x,y);return !!top&&(el.contains(top)||top.contains(el));`),`Focused ${selector} is obscured`);
            return;
        }
        await browser.actions().sendKeys(Key.TAB).perform();
    }
    assert.fail(`Tab order never reached ${selector}`);
}
async function press(key = Key.ENTER) { await browser.actions().sendKeys(key).perform(); }
async function waitText(selector, wording) {
    await browser.wait(async () => browser.executeScript('return document.querySelector(arguments[0])?.innerText.includes(arguments[1]) || false',selector,wording), 10000);
}
async function structure() {
    const defects = await browser.executeScript(`
        const ids = [...document.querySelectorAll('[id]')].map(el=>el.id);
        const duplicateIds = ids.filter((id,i)=>ids.indexOf(id)!==i);
        const missingRefs = [...document.querySelectorAll('[aria-describedby],[aria-labelledby],[aria-controls],label[for]')]
            .flatMap(el=>['aria-describedby','aria-labelledby','aria-controls','for'].flatMap(attr=>
                (el.getAttribute(attr)||'').split(/\\s+/).filter(Boolean).filter(id=>!document.getElementById(id))));
        return {duplicateIds, missingRefs, mains:document.querySelectorAll('main').length, headings:document.querySelectorAll('h1').length,
            nestedControls:document.querySelectorAll('button a, a button, button button, a a').length};
    `);
    assert.deepEqual(defects, { duplicateIds: [], missingRefs: [], mains: 1, headings: 1, nestedControls: 0 });
}
async function reflow() {
    assert.ok(await browser.executeScript('return document.documentElement.scrollWidth <= innerWidth + 1'), 'Page must fit the viewport');
    const overflow = await browser.executeScript(`return [...document.querySelectorAll('main *,header *,nav *')].filter(el=>{
        const r=el.getBoundingClientRect();return el.checkVisibility()&&r.width&&r.height&&(r.right>innerWidth+1||r.left<-1);
    }).map(el=>el.tagName+'.'+el.className)`);
    assert.deepEqual(overflow, []);
}
async function visual(name, selector) {
    await browser.executeAsyncScript('document.fonts.ready.then(arguments[arguments.length-1])');
    await delay(200); // Capture the settled state after the existing 150ms color transitions.
    const clip = await browser.executeScript(`const el=document.querySelector(arguments[0]);
        el.scrollIntoView({block:'center'}); const r=el.getBoundingClientRect();
        return {x:r.x+scrollX,y:r.y+scrollY,width:r.width,height:r.height,scale:1};`, selector);
    const png = (await browser.sendAndGetDevToolsCommand('Page.captureScreenshot',{clip,captureBeyondViewport:true})).data;
    writeFileSync(`${artifacts}/${name}.png`,Buffer.from(png,'base64'));
    const baseline = new URL(`./snapshots/${name}.png`,import.meta.url);
    if (process.env.UPDATE_ACCESSIBILITY_SNAPSHOTS === '1') {
        mkdirSync(new URL('./snapshots/',import.meta.url),{recursive:true});
        writeFileSync(baseline,Buffer.from(png,'base64'));
    }
    const expected = readFileSync(baseline).toString('base64');
    const comparison = await browser.executeAsyncScript(`
        const [expected,actual,done]=arguments;
        Promise.all([expected,actual].map(src=>new Promise((resolve,reject)=>{
            const img=new Image(); img.onload=()=>resolve(img); img.onerror=()=>reject(new Error('Image decode failed'));
            img.src='data:image/png;base64,'+src;
        }))).then(([a,b])=>{
            if(a.width!==b.width||a.height!==b.height) return done({size:false});
            const pixels=img=>{const c=document.createElement('canvas');c.width=img.width;c.height=img.height;
                const ctx=c.getContext('2d');ctx.drawImage(img,0,0);return ctx.getImageData(0,0,c.width,c.height).data;};
            const x=pixels(a), y=pixels(b);let changed=0;
            for(let i=0;i<x.length;i+=4) if(Math.max(...[0,1,2].map(n=>Math.abs(x[i+n]-y[i+n])))>30) changed++;
            done({size:true,changed:changed/(x.length/4)});
        }).catch(e=>done({error:e.message}));`,expected,png);
    assert.equal(comparison.size,true,`${name}: dimensions changed`);
    assert.ok(comparison.changed<=0.02,`${name}: ${(comparison.changed*100).toFixed(2)}% pixels changed (limit 2%)`);
}

test('rendered primary-button text, field boundaries and focus outlines meet contrast ratios', async () => {
    await browser.manage().deleteAllCookies(); await open('/login');
    const evidence = [];
    for (const theme of ['light','dark']) {
        await browser.executeScript('localStorage.setItem("theme",arguments[0])',theme); await open('/login');
        await tabTo('#email');
        const ratios = await browser.executeScript(`
            const luminance=color=>color.match(/[\\d.]+/g).slice(0,3).map(v=>v/255)
                .map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4)
                .reduce((sum,v,i)=>sum+v*[.2126,.7152,.0722][i],0);
            const contrast=(a,b)=>{const [hi,lo]=[luminance(a),luminance(b)].sort((a,b)=>b-a);return (hi+.05)/(lo+.05);};
            const input=getComputedStyle(document.querySelector('#email')),button=getComputedStyle(document.querySelector('button[type=submit]'));
            return {text:contrast(button.color,button.backgroundColor),border:contrast(input.borderTopColor,input.backgroundColor),focus:contrast(input.outlineColor,input.backgroundColor)};
        `);
        assert.ok(ratios.text>=4.5,`${theme} button text ${ratios.text}`);
        assert.ok(ratios.border>=3,`${theme} field boundary ${ratios.border}`);
        assert.ok(ratios.focus>=3,`${theme} focus ${ratios.focus}`);
        evidence.push({theme,...ratios});
    }
    writeFileSync(`${artifacts}/contrast.json`,JSON.stringify(evidence,null,2));
});

test('full-page guest authentication, catalogue, recipe and public-profile scans in both themes', async () => {
    for (const theme of ['light', 'dark']) {
        await open('/');
        await browser.executeScript('localStorage.setItem("theme",arguments[0]); window.applyThemePreference()', theme);
        for (const path of ['/', '/login', '/register', '/forgot-password', '/recipes', '/catalogue', `/catalogue/${fixture.items.approved}`, `/recipes/${fixture.publicRecipe}`, `/profiles/${fixture.profile}`]) {
            await open(path);
            await structure();
            await scan(`${theme} guest ${path}`);
        }
    }
    assert.deepEqual(reports.filter(r=>r.name.includes('guest') && r.violations.length), []);
});

test('full-page owner and administrator states include dense forms, sharing and moderation', async () => {
    await login();
    for (const theme of ['light', 'dark']) {
        await browser.executeScript('localStorage.setItem("theme",arguments[0]); window.applyThemePreference()', theme);
        for (const path of ['/dashboard', '/profile', '/recipes/create', `/recipes/${fixture.draft}/edit`, `/recipes/${fixture.publicRecipe}`, '/recipe-imports/create', '/catalogue/manual/create', `/catalogue/${fixture.items.pending}`, `/catalogue/${fixture.items.rejected}`, `/catalogue/${fixture.items.approved}/corrections/create`, '/meal-plans', '/meal-plans/create', `/meal-plans/${fixture.plan}`, '/nutrition-target-profiles/create', '/security/two-step', '/confirm-password', '/verify-email', `/recipe-imports/${fixture.failedImport}`, `/recipe-imports/${fixture.reviewImport}`]) {
            await open(path);
            await structure();
            await scan(`${theme} owner ${path}`);
        }
    }
    await login('admin');
    for (const theme of ['light', 'dark']) {
        await browser.executeScript('localStorage.setItem("theme",arguments[0]); window.applyThemePreference()', theme);
        for (const path of ['/admin/catalogue', `/admin/catalogue/submissions/${fixture.items.pending}`, '/admin/managed-recipe-terms', '/security/administrator-lifecycle']) {
            await open(path);
            await structure();
            await scan(`${theme} admin ${path}`);
        }
    }
    assert.deepEqual(reports.filter(r=>!r.name.includes('guest') && r.violations.length), []);
});

test('keyboard navigation has a skip link, visible focus, dropdown state, Escape and mobile return focus', async () => {
    await login();
    await open('/dashboard');
    await tabTo('.skip-link');
    assert.ok(await browser.executeScript('return document.activeElement.getBoundingClientRect().top >= 0'));
    const focused = await browser.executeScript('const s=getComputedStyle(document.activeElement); return [s.outlineStyle,s.outlineWidth]');
    assert.deepEqual(focused, ['solid','2px']);
    await press();
    assert.equal(await browser.executeScript('return document.activeElement.id'), 'main-content');
    await tabTo('nav .relative button[aria-controls]');
    await press(Key.SPACE);
    assert.equal(await (await find('nav .relative button[aria-controls]')).getAttribute('aria-expanded'), 'true');
    await press(Key.TAB);
    assert.equal(await (await browser.switchTo().activeElement()).getAccessibleName(), 'Profile');
    await press(Key.ESCAPE);
    assert.equal(await (await find('nav .relative button[aria-controls]')).getAttribute('aria-expanded'), 'false');
    assert.ok(await browser.executeScript('return document.activeElement.matches("nav .relative button[aria-controls]")'));
    await browser.sendDevToolsCommand('Emulation.setDeviceMetricsOverride', {width:320,height:800,deviceScaleFactor:1,mobile:true});
    await tabTo('button[aria-controls="mobile-navigation"]');
    await press();
    await press(Key.TAB);
    assert.equal(await (await browser.switchTo().activeElement()).getAccessibleName(), 'Dashboard');
    await press(Key.ESCAPE);
    assert.equal(await (await find('button[aria-controls="mobile-navigation"]')).getAttribute('aria-expanded'), 'false');
    await reflow();
    await browser.sendDevToolsCommand('Emulation.clearDeviceMetricsOverride');
});

test('authentication errors retain associated descriptions and focus; recovery succeeds by keyboard', async () => {
    await browser.manage().deleteAllCookies();
    await open('/login');
    await (await find('#email')).sendKeys(fixture.users.owner.email, Key.TAB);
    await browser.actions().sendKeys('incorrect-password', Key.ENTER).perform();
    await browser.wait(until.elementLocated(By.css('[data-validation-summary]')), 10000);
    assert.ok(await browser.executeScript('return document.activeElement.matches("[data-validation-summary]")'));
    assert.equal(await (await find('#email')).getAttribute('aria-invalid'), 'true');
    assert.ok(await (await find('#email')).getAttribute('aria-describedby'));
    assert.equal(await (await find('#email')).getAttribute('value'), fixture.users.owner.email);
    await scan('login-error'); await structure(); await screenshot('login-error');
    await tabTo('#password');
    await browser.actions().keyDown(Key.CONTROL).sendKeys('a').keyUp(Key.CONTROL).sendKeys('browser-fixture-password', Key.ENTER).perform();
    await browser.wait(until.urlContains('/dashboard'),15000);
});

test('recipe editing, matching, ordering, validation, save and resize work by keyboard', async () => {
    await login();
    await open(`/recipes/${fixture.draft}/edit`);
    await tabTo('#title');
    await browser.actions().keyDown(Key.CONTROL).sendKeys('a').keyUp(Key.CONTROL).sendKeys('Keyboard recipe').perform();
    await tabTo('button[wire\\:click="addIngredient"]'); await press();
    await browser.wait(async()=> (await browser.findElements(By.css('fieldset[id^="ingredient-line-"]'))).length===2,10000);
    await tabTo('#ingredient-line-2 textarea');
    await browser.actions().sendKeys('A long original ingredient '+ 'vegetable '.repeat(40)).perform();
    await tabTo('button[type="submit"][wire\\:target="save"]'); await press();
    await waitText('main','All changes saved');
    await tabTo('[aria-label="Move ingredient 2 up"]'); await press();
    await browser.wait(async()=> (await (await find('#ingredient-line-1 textarea')).getAttribute('value')).startsWith('A long original'),10000);
    await tabTo('button[type="submit"][wire\\:target="save"]'); await press();
    await waitText('main','All changes saved');
    await tabTo('#ingredient-line-1 input[type="search"]');
    await browser.actions().sendKeys('Accessibility approved food').perform();
    await tabTo('[aria-label="Search catalogue for ingredient 1"]'); await press();
    await browser.wait(until.elementLocated(By.css('#ingredient-line-1 button[aria-label^="Select food:"]')),10000);
    await tabTo('#ingredient-line-1 button[aria-label^="Select food:"]'); await press();
    await browser.wait(async()=> (await browser.executeScript('return document.activeElement.id'))==='ingredient-review-1',10000);
    await scan('recipe-selected-food'); await structure();
    await tabTo('[aria-label="Clear catalogue match for ingredient 1"]'); await press();
    await browser.wait(until.alertIsPresent(),5000); await browser.switchTo().alert().dismiss();
    assert.match(await (await find('#ingredient-review-1')).getText(),/Accessibility approved food/);
    await scan('recipe-clear-cancelled');
    await tabTo('#servings');
    await browser.actions().keyDown(Key.CONTROL).sendKeys('a').keyUp(Key.CONTROL).sendKeys('0').perform();
    await tabTo('button[type="submit"][wire\\:target="save"]'); await press();
    // Native validity prevents submission and keeps the invalid control focused.
    assert.equal(await browser.executeScript('return document.activeElement.id'),'servings');
    await browser.actions().keyDown(Key.CONTROL).sendKeys('a').keyUp(Key.CONTROL).sendKeys('2').perform();
    await tabTo('button[type="submit"][wire\\:target="save"]'); await press();
    await waitText('main','All changes saved');
    await open(`/recipes/${fixture.draft}`);
    await tabTo('#display-servings');
    await browser.actions().keyDown(Key.CONTROL).sendKeys('a').keyUp(Key.CONTROL).sendKeys('4',Key.TAB,Key.ENTER).perform();
    await browser.wait(until.urlContains('servings=4'),10000);
    await scan('recipe-resized'); await screenshot('recipe-resized');
});

test('catalogue submission validates, keeps input and completes with keyboard controls', async () => {
    await login();
    await open('/catalogue/manual/create');
    await tabTo('#name'); await browser.actions().sendKeys('Keyboard submitted food').perform();
    await tabTo('#amount_per_item'); await browser.actions().sendKeys('invalid').perform();
    await tabTo('button[type="submit"]'); await press();
    await browser.wait(until.elementLocated(By.css('[data-validation-summary]')),10000);
    assert.ok(await browser.executeScript('return document.activeElement.matches("[data-validation-summary]")'));
    assert.equal(await (await find('#amount_per_item')).getAttribute('aria-invalid'),'true');
    assert.equal(await (await find('#name')).getAttribute('value'),'Keyboard submitted food');
    await scan('catalogue-submission-error'); await structure();
    await tabTo('#amount_per_item');
    await browser.actions().keyDown(Key.CONTROL).sendKeys('a').keyUp(Key.CONTROL).sendKeys(Key.BACK_SPACE).perform();
    await tabTo('button[type="submit"]'); await press();
    await browser.wait(until.urlMatches(/\/catalogue\/\d+$/),10000);
    assert.match(await (await find('main')).getText(),/Pending review/);
    await scan('catalogue-submitted');
});

test('sharing, read-only access, copy and revocation retain keyboard and privacy boundaries', async () => {
    await login(); await open(`/meal-plans/${fixture.plan}`);
    await tabTo('#recipient_email'); await browser.actions().sendKeys(fixture.users.reader.email).perform();
    await tabTo('form[action$="/shares"] button'); await press();
    await waitText('main','Selected-user share 1'); await scan('plan-shared');
    await login('reader'); await open(`/meal-plans/${fixture.plan}`);
    assert.equal((await browser.findElements(By.css('#recipient_email'))).length,0);
    await scan('plan-read-only'); await structure();
    await tabTo('form[action$="/copy"] button'); await press();
    await browser.wait(async()=>{ const url=await browser.getCurrentUrl(); return /\/meal-plans\/\d+$/.test(url) && url!==`${base}/meal-plans/${fixture.plan}`; },10000);
    assert.notEqual(await browser.getCurrentUrl(),`${base}/meal-plans/${fixture.plan}`);
    await scan('plan-copied');
    await login(); await open(`/meal-plans/${fixture.plan}`);
    await tabTo('form[action*="/shares/"] button'); await press();
    await waitText('main','No selected-user shares.'); await scan('plan-share-revoked');
});

test('profile validation, theme selection and deletion dialog trap focus, isolate background and restore opener', async () => {
    await login(); await open('/profile');
    await tabTo('form[wire\\:submit="updateProfileInformation"] button'); await press();
    await scan('profile-saved');
    await tabTo('button[x-on\\:click="setTheme(\'light\')"]'); await press();
    assert.equal(await (await find('button[x-on\\:click="setTheme(\'light\')"]')).getAttribute('aria-pressed'),'true');
    assert.match(await (await find('button[x-on\\:click="setTheme(\'light\')"]')).getText(),/✓/);
    assert.doesNotMatch(await (await find('button[x-on\\:click="setTheme(\'dark\')"]')).getText(),/✓/);
    await scan('profile-theme-light'); await screenshot('profile-light');
    await tabTo('button[x-on\\:click="setTheme(\'dark\')"]'); await press();
    assert.equal(await (await find('button[x-on\\:click="setTheme(\'dark\')"]')).getAttribute('aria-pressed'),'true');
    assert.match(await (await find('button[x-on\\:click="setTheme(\'dark\')"]')).getText(),/✓/);
    await scan('profile-theme-dark'); await screenshot('profile-dark');
    const opener = 'button[x-on\\:click\\.prevent]';
    await tabTo(opener); await press();
    await browser.wait(until.elementIsVisible(await find('[role="dialog"]')),10000);
    assert.ok(await browser.executeScript('return document.activeElement.closest("[role=dialog]")!==null'));
    assert.ok(await browser.executeScript('return document.querySelector("nav").inert'));
    for (let i=0;i<8;i++) { await press(Key.TAB); assert.ok(await browser.executeScript('return document.activeElement.closest("[role=dialog]")!==null')); }
    await scan('deletion-open'); await screenshot('deletion-open');
    const accessibilityTree = await browser.sendAndGetDevToolsCommand('Accessibility.getFullAXTree');
    const exposed = accessibilityTree.nodes.filter(node=>!node.ignored);
    assert.ok(exposed.some(node=>node.role?.value==='dialog' && node.name?.value==='Delete account'));
    assert.ok(!exposed.some(node=>node.role?.value==='navigation'));
    assert.match(await (await find('[role="dialog"] input')).getAccessibleName(),/Password/);
    await tabTo('[role="dialog"] input[name="password"]');
    await browser.actions().sendKeys('incorrect-password',Key.ENTER).perform();
    await browser.wait(until.elementLocated(By.css('[role="dialog"] [data-validation-summary]')),10000);
    assert.ok(await browser.executeScript('return document.activeElement.matches("[data-validation-summary]")'));
    await scan('deletion-invalid'); await structure(); await screenshot('deletion-invalid');
    await browser.actions().keyDown(Key.SHIFT).sendKeys(Key.TAB).keyUp(Key.SHIFT).perform();
    assert.ok(await browser.executeScript('return document.activeElement.closest("[role=dialog]")!==null'));
    await press(Key.ESCAPE);
    await browser.wait(async()=>await browser.executeScript('return !document.querySelector("nav").inert'),5000);
    await browser.wait(async()=>browser.executeScript('return document.activeElement.matches(arguments[0])',opener),5000)
        .catch(async error=>{throw new Error(await browser.executeScript('return JSON.stringify({active:document.activeElement.tagName+"#"+document.activeElement.id,opener:Alpine.$data(document.querySelector("[role=dialog]").parentElement).openerId})'),{cause:error});});
    await scan('deletion-cancelled');
    await login('deletion'); await open('/profile'); await tabTo(opener); await press();
    await tabTo('[role="dialog"] input[name="password"]');
    await browser.actions().sendKeys('browser-fixture-password',Key.ENTER).perform();
    await browser.wait(until.urlIs(`${base}/`),15000);
    await open('/profile'); await browser.wait(until.urlContains('/login'),10000);
});

test('administrator filtering and detail controls are reachable without bypassing factor verification', async () => {
    await login('admin'); await open('/admin/catalogue');
    await tabTo('#state'); await browser.actions().sendKeys('pending',Key.TAB,Key.ENTER).perform();
    await browser.wait(until.urlContains('state=pending'),10000);
    await scan('moderation-filtered');
    await open(`/admin/catalogue/submissions/${fixture.items.pending}`);
    await tabTo('#moderation-code');
    assert.match(await (await browser.switchTo().activeElement()).getAccessibleName(),/Six-digit authenticator code/);
    await tabTo('#reason-approve'); await tabTo('#note-approve');
    await scan('moderation-decision-form'); await structure();
});

test('reviewed visual baselines preserve primary-button contrast, theme state and dialog header', async () => {
    for (const theme of ['light','dark']) {
        await browser.manage().deleteAllCookies(); await open('/login');
        await browser.executeScript('localStorage.setItem("theme",arguments[0])',theme); await open('/login');
        await visual(`primary-button-${theme}`,'button[type="submit"]');
        await login(); await open('/profile');
        await visual(`theme-controls-${theme}`,'div.flex.flex-wrap.gap-2');
        await tabTo('button[x-on\\:click\\.prevent]'); await press();
        await browser.wait(until.elementIsVisible(await find('[role="dialog"]')),10000);
        await delay(350); // Wait for the existing enter transition before comparing pixels.
        await visual(`dialog-header-${theme}`,'[role="dialog"] > div:first-child');
        await press(Key.ESCAPE);
    }
});

test('320px, actual 200%/400% zoom and reduced motion retain forms, names and focus', async () => {
    await login();
    await browser.sendDevToolsCommand('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
    assert.ok(await browser.executeScript('return matchMedia("(prefers-reduced-motion: reduce)").matches'));
    for (const scale of [1,2,4]) {
        await browser.get('chrome://settings/appearance');
        await browser.executeAsyncScript('chrome.settingsPrivate.setDefaultZoom(arguments[0], arguments[arguments.length-1])',scale);
        for (const path of ['/login', '/profile', `/recipes/${fixture.draft}/edit`, '/catalogue/manual/create', `/meal-plans/${fixture.plan}`]) {
            await open(path);
            assert.equal(await browser.executeScript('return devicePixelRatio'),scale);
            await reflow(); await scan(`zoom-${scale} ${path}`);
            await screenshot(`zoom-${scale}-${path.split('/')[1]}`);
        }
    }
    await browser.get('chrome://settings/appearance');
    await browser.executeAsyncScript('chrome.settingsPrivate.setDefaultZoom(1,arguments[arguments.length-1])');
    await browser.sendDevToolsCommand('Emulation.setDeviceMetricsOverride',{width:320,height:800,deviceScaleFactor:1,mobile:true});
    await open('/profile');
    await tabTo('button[x-on\\:click\\.prevent]'); await press();
    await browser.wait(until.elementIsVisible(await find('[role="dialog"]')),10000);
    assert.equal(Number.parseFloat(await browser.executeScript('return getComputedStyle(document.querySelector("[role=dialog]")).transitionDuration')),0.00001);
    await reflow(); await scan('mobile-reduced-motion-modal'); await screenshot('mobile-reduced-motion-modal');
    await press(Key.ESCAPE);
    await browser.sendDevToolsCommand('Emulation.clearDeviceMetricsOverride');
    await browser.sendDevToolsCommand('Emulation.setEmulatedMedia',{features:[]});
});

test('every scanned representative state has no accessibility violations', () => {
    assert.deepEqual(reports.filter(r=>r.violations.length),[]);
});
