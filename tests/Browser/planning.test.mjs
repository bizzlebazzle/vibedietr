import { before, after, test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { setTimeout as delay } from 'node:timers/promises';
import { Builder, By, Key, until, error as webdriverError } from 'selenium-webdriver';
import chrome from 'selenium-webdriver/chrome.js';
import { Pointer } from 'selenium-webdriver/lib/input.js';
import axe from 'axe-core';

const base = process.env.PLANNING_BROWSER_URL || 'http://laravel.test:8015';
const selenium = process.env.SELENIUM_URL || 'http://selenium:4444/wd/hub';
const artifacts = process.env.PLANNING_BROWSER_ARTIFACTS || 'storage/app/testing/ux05';
const env = { ...process.env, APP_ENV: 'testing', APP_URL: base, DB_CONNECTION: 'mysql', DB_DATABASE: 'testing', SESSION_DRIVER: 'file', CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array', APP_DEBUG: 'false', TRUSTED_HOSTS: new URL(base).hostname, PULSE_ENABLED: 'false', TELESCOPE_ENABLED: 'false', NIGHTWATCH_ENABLED: 'false' };
let browser, server, fixture;
const find = selector => browser.findElement(By.css(selector));
const entry = (kind, id) => `[data-entry="entry-${kind}-${id}"]`;

before(async () => {
    mkdirSync(artifacts, { recursive: true });
    // Additive migrations in the existing testing DB; no migrate:fresh/truncate.
    execFileSync('php', ['artisan', 'migrate', '--force', '--no-interaction'], { env });
    const fixtureOutput = execFileSync('php', ['tests/Browser/fixture.php'], { env, encoding: 'utf8' });
    assert.ok(fixtureOutput.startsWith('{'), `Fixture creation failed: ${fixtureOutput}`);
    fixture = JSON.parse(fixtureOutput);
    server = spawn('php', ['-S', '0.0.0.0:8015', '-t', 'public', 'tests/Browser/router.php'], { env, stdio: ['ignore', 'ignore', 'pipe'] });
    let serverLog = '';
    server.stderr.on('data', chunk => { serverLog += chunk; });
    let ready = false;
    for (let attempt = 0; attempt < 50; attempt++) {
        try { ready = (await fetch('http://127.0.0.1:8015/login', { signal: AbortSignal.timeout(500) })).ok; } catch {}
        if (ready) break;
        await delay(100);
    }
    assert.ok(ready, `Testing server failed: ${serverLog}`);
    browser = await new Builder().forBrowser('chrome').usingServer(selenium)
        .setChromeOptions(new chrome.Options().addArguments('--headless=new', '--no-sandbox', '--disable-dev-shm-usage', '--window-size=1280,900')).build();
    await browser.manage().setTimeouts({ implicit: 0, pageLoad: 30000, script: 30000 });
    await browser.get(`${base}/login`);
    await (await find('#email')).sendKeys(fixture.email);
    await (await find('#password')).sendKeys('browser-fixture-password', Key.ENTER);
    await browser.wait(until.urlContains('/dashboard'), 15000);
    await openPlan();
});

after(async () => {
    if (browser) await browser.quit();
    if (server) server.kill();
    if (fixture) execFileSync('php', ['tests/Browser/fixture.php', 'cleanup', String(fixture.owner), fixture.email], { env });
});

async function openPlan() { await browser.get(`${base}/meal-plans/${fixture.plan}`); }
async function text(selector) { return (await find(selector)).getText(); }
async function activate(selector) { await (await find(selector)).sendKeys(Key.ENTER); }
async function tap(selector) {
    const element = await find(selector);
    await browser.executeScript('arguments[0].scrollIntoView({block:"center"})', element);
    const finger = new Pointer('finger', Pointer.Type.TOUCH);
    await browser.actions().insert(finger, finger.move({ origin: element }), finger.press(), finger.release()).perform();
}
async function outcome(message) {
    await browser.wait(async () => {
        try { return (await text('[role="status"]')).includes(message); }
        catch (error) {
            if (error instanceof webdriverError.NoSuchElementError || error instanceof webdriverError.StaleElementReferenceError) return false;
            throw error;
        }
    }, 10000).catch(async error => { throw new Error(`Expected ${message}: ${await text('main')}`, { cause: error }); });
    assert.match(await text('[role="status"]'), new RegExp(message));
}
async function scan() {
    await browser.executeScript(axe.source);
    const results = await browser.executeAsyncScript('const done = arguments[arguments.length-1]; axe.run(".planning-content").then(r => done(r.violations.map(v => ({id:v.id, nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))})))).catch(e=>done({error:e.message}));');
    assert.deepEqual(results, []);
}
async function reflow() {
    const failures = await browser.executeScript(`
        const root = document.querySelector('.planning-content');
        return [...root.querySelectorAll('*')].filter(el => {
            const r = el.getBoundingClientRect();
            return el.checkVisibility() && r.width && r.height && (r.right > innerWidth + 1 || r.left < -1);
        }).map(el => el.tagName + '.' + el.className);
    `);
    assert.deepEqual(failures, [], 'Essential planning content must stay inside the viewport');
    assert.equal(await browser.executeScript('return document.documentElement.scrollWidth <= innerWidth + 1'), true);
}
async function screenshot(name) {
    // WebDriver captures the actual zoomed viewport; CDP full-page capture at
    // non-default browser zoom can crop the bitmap to unscaled CSS coordinates.
    const data = await browser.executeScript('return devicePixelRatio') === 1
        ? (await browser.sendAndGetDevToolsCommand('Page.captureScreenshot', { captureBeyondViewport: true })).data
        : await browser.takeScreenshot();
    writeFileSync(`${artifacts}/${name}.png`, Buffer.from(data, 'base64'));
}
async function expandPlanningForms() {
    for (const summary of await browser.findElements(By.css('.planning-content details:not([open]) > summary'))) {
        await summary.sendKeys(Key.SPACE);
    }
}

test('keyboard moves recipe, catalogue and one-off entries with focus and pinned quantities intact', async () => {
    for (const [kind, id] of [['recipe', fixture.recipe], ['item', fixture.catalogue], ['item', fixture.item]]) {
        const root = entry(kind, id);
        const select = await find(`${root} select`);
        assert.match(await select.getAccessibleName(), /Move .* to day and slot/);
        await select.sendKeys(Key.HOME, Key.ARROW_DOWN, Key.TAB);
        assert.equal(await browser.executeScript('return document.activeElement.textContent.trim()'), 'Move');
        await browser.actions().sendKeys(Key.ENTER).perform();
        await outcome(kind === 'recipe' ? 'Recipe entry moved' : 'Plan item moved');
        assert.equal(await browser.executeScript('return document.activeElement.id'), `entry-${kind}-${id}`);
        assert.equal(await (await find(`${root} select`)).getAttribute('value'), String(fixture.dinner));
    }
    assert.match(await text('[aria-label="Fat daily comparison"]'), /Planned\n0.0 g\nNo target/);
    assert.match(await text('[aria-label="Fibre daily comparison"]'), /Planned\nNot available/);
    const unnamed = await browser.executeScript(`return [...document.querySelectorAll('.planning-content input:not([type="hidden"]),.planning-content select')].filter(el=>!el.labels?.length).map(el=>el.name)`);
    assert.deepEqual(unnamed, []);
    await scan();
});

test('consumption validation, actual corrections, reversal and re-consumption preserve planned nutrition and history', async () => {
    const root = entry('recipe', fixture.recipe);
    await activate(`${root} summary`);
    await activate(`${root} details button`); // Yesterday requires explicit time.
    await browser.wait(until.elementLocated(By.css('[data-validation-summary]')), 10000);
    assert.equal(await browser.executeScript('return document.activeElement.hasAttribute("data-validation-summary")'), true);
    assert.equal(await (await find(`${root} input[name="consumed_local_at"]`)).getAttribute('aria-invalid'), 'true');
    assert.equal(await (await find(`${root} input[name="actual_amount"]`)).getAttribute('value'), '2.00');
    await scan();
    // WebDriver cannot portably type segmented date controls; set its native value,
    // then submit and exercise the server's actual datetime-local boundary.
    await browser.executeScript('arguments[0].value = arguments[1]', await find(`${root} input[name="consumed_local_at"]`), `${fixture.date}T12:30:45`);
    await activate(`${root} details button`);
    await outcome('Consumption recorded');
    assert.match(await text(`${root} [data-entry-state]`), /Consumed/);
    assert.equal((await browser.findElements(By.css(`${root} select[name="target_slot_id"]`))).length, 0);
    assert.match(await text(root), /Actual: 2 servings/);
    await activate(`${root} summary`);
    const amount = await find(`${root} input[name="actual_amount"]`);
    await amount.clear(); await amount.sendKeys('1.25');
    await activate(`${root} details button`);
    await outcome('Consumption corrected');
    assert.match(await text(root), /Actual: 1.25 servings/);
    assert.match(await text('main'), /2.00 planned servings/);
    const protein = '[aria-label="Protein daily comparison"]';
    assert.match(await text(protein), /Consumed\n10.0 g\nMeets minimum/);
    assert.match(await text(protein), /Planned\n16.0 g\nComparison unavailable/);
    assert.match(await text(protein), /Estimate/);
    assert.match(await text(protein), /Partial total/);
    await scan();
    await activate(`${root} form button[aria-describedby]`);
    await outcome('Consumption reversed');
    assert.match(await text(`${root} [data-entry-state]`), /Planned — consumption reversed/);
    assert.match(await text(protein), /Consumed\nNot available/);
    await activate(`${root} summary`);
    assert.equal(await (await find(`${root} input[name="actual_amount"]`)).getAttribute('value'), '');
    await (await find(`${root} input[name="actual_amount"]`)).sendKeys('1.5');
    await browser.executeScript('arguments[0].value = arguments[1]', await find(`${root} input[name="consumed_local_at"]`), `${fixture.date}T13:00`);
    await activate(`${root} details button`);
    await outcome('Consumption recorded');
    const ax = await browser.sendAndGetDevToolsCommand('Accessibility.getFullAXTree');
    assert.ok(ax.nodes.some(node => node.name?.value?.includes('Consumed') && node.name.value.includes('Soup')));
    await scan();
});

test('mobile touch move and consumption have native keyboard equivalents and usable targets', async () => {
    await browser.sendDevToolsCommand('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    await browser.sendDevToolsCommand('Emulation.setTouchEmulationEnabled', { enabled: true });
    const root = entry('item', fixture.item);
    await tap(`${root} select`);
    await (await find(`${root} select`)).sendKeys(Key.HOME, Key.ESCAPE);
    await tap(`${root} form button`);
    await outcome('Plan item moved');
    assert.equal(await (await find(`${root} select`)).getAttribute('value'), String(fixture.breakfast));
    await tap(`${root} summary`);
    await (await find(`${root} input[name="actual_amount"]`)).clear();
    await (await find(`${root} input[name="actual_amount"]`)).sendKeys('75');
    await browser.executeScript('arguments[0].value = arguments[1]', await find(`${root} input[name="consumed_local_at"]`), `${fixture.date}T18:00`);
    await tap(`${root} details button`);
    await outcome('Consumption recorded');
    assert.match(await text(root), /Actual: 75 g/);
    await activate(`${root} summary`); // The touch disclosure is equally operable by keyboard.
    await reflow();
    const smallTargets = await browser.executeScript(`return [...document.querySelectorAll('.planning-content a, .planning-content button, .planning-content summary, .planning-content select, .planning-content input:not([type="hidden"]):not([type="checkbox"])')].filter(el => {const r=el.getBoundingClientRect();return el.checkVisibility() && r.width && r.height && (r.height < 43 || r.width < 24);}).map(el=>el.outerHTML)`);
    assert.deepEqual(smallTargets, []);
    await scan(); await screenshot('mobile-light');
    await browser.executeScript('localStorage.setItem("theme","dark"); window.applyThemePreference()');
    await scan(); await screenshot('mobile-dark');
});

test('slot naming, ordering, and target-phase edits work with native keyboard controls', async () => {
    await browser.sendDevToolsCommand('Emulation.clearDeviceMetricsOverride');
    await browser.sendDevToolsCommand('Emulation.setTouchEmulationEnabled', { enabled: false });
    await openPlan();
    const rename = `form[action$="/slots/${fixture.dinner}"]`;
    await (await find(`${rename} input[name="name"]`)).clear();
    await (await find(`${rename} input[name="name"]`)).sendKeys('Evening meal', Key.TAB);
    await browser.actions().sendKeys(Key.ENTER).perform();
    await outcome('Slot renamed');
    const order = `form[action$="/slots/order"]`;
    const positions = await browser.findElements(By.css(`${order} select`));
    await positions[0].sendKeys(Key.HOME, Key.ARROW_DOWN);
    await positions[1].sendKeys(Key.HOME);
    await activate(`${order} button`);
    await outcome('Slots reordered');
    assert.equal(await (await find(`${order} select`)).getAttribute('value'), String(fixture.dinner));
    const phase = await find('form[action*="/target-phases/"] input[name="ends_on"]');
    // Keep the same inclusive boundaries; the service retains historical values.
    assert.match(await phase.getAccessibleName(), /End date \(optional\)/);
    await activate('form[action*="/target-phases/"] button[type="submit"]');
    await outcome('Future target phase dates updated');
    assert.match(await text('[aria-label="Protein daily comparison"]'), /At least 10.0 g/);
    await scan();
});

test('entry moving remains usable with application JavaScript disabled', async () => {
    await browser.sendDevToolsCommand('Emulation.setScriptExecutionDisabled', { value: true });
    try {
        await openPlan();
        const root = entry('item', fixture.catalogue);
        await (await find(`${root} select`)).sendKeys(Key.HOME);
        await activate(`${root} form button`);
        await outcome('Plan item moved');
        assert.equal(await (await find(`${root} select`)).getAttribute('value'), String(fixture.dinner));
    } finally {
        await browser.sendDevToolsCommand('Emulation.setScriptExecutionDisabled', { value: false });
        await openPlan();
    }
});

test('200% real browser zoom reflows daily comparisons and target controls in both themes', async () => {
    await browser.sendDevToolsCommand('Emulation.clearDeviceMetricsOverride');
    await browser.sendDevToolsCommand('Emulation.setTouchEmulationEnabled', { enabled: false });
    await browser.get('chrome://settings/appearance');
    await browser.executeAsyncScript('chrome.settingsPrivate.setDefaultZoom(2, arguments[arguments.length-1])');
    await openPlan();
    assert.equal(await browser.executeScript('return devicePixelRatio'), 2, 'Actual Chrome zoom must be 200%');
    assert.ok(await browser.executeScript('return innerWidth < 650'));
    await expandPlanningForms();
    await reflow(); await scan(); await screenshot('desktop-200-dark');
    await browser.executeScript('localStorage.setItem("theme","light"); window.applyThemePreference()');
    await reflow(); await scan(); await screenshot('desktop-200-light');
    await browser.get(`${base}/nutrition-target-profiles/${fixture.profile}/edit`);
    await reflow(); await scan(); await screenshot('targets-200');
    assert.match(await (await find('[name="targets[protein][minimum_value]"]')).getAccessibleName(), /Protein minimum \(g\)/);
    const labels = await browser.executeScript(`return [...document.querySelectorAll('.planning-content input:not([type="hidden"]),.planning-content select')].filter(el=>!el.labels?.length).map(el=>el.name)`);
    assert.deepEqual(labels, []);
    const minimum = await find('[name="targets[protein][minimum_value]"]');
    await minimum.clear(); await minimum.sendKeys('12');
    await activate('.planning-content button[type="submit"]');
    await outcome('Target profile updated');
    await openPlan();
    assert.match(await text('[aria-label="Protein daily comparison"]'), /At least 10.0 g/, 'Editing a profile must preserve historical target snapshots');
    await browser.get('chrome://settings/appearance');
    await browser.executeAsyncScript('chrome.settingsPrivate.setDefaultZoom(1, arguments[arguments.length-1])');
    await openPlan(); await reflow(); await screenshot('desktop-light');
    await browser.sendDevToolsCommand('Emulation.setDeviceMetricsOverride', { width: 320, height: 640, deviceScaleFactor: 1, mobile: true });
    await expandPlanningForms();
    await reflow(); await scan(); await screenshot('narrow-320');
});
