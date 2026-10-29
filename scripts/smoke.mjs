#!/usr/bin/env node
// Dashboard smoke test: seed a demo database (with hostile names), serve it,
// log in with headless Chrome over the DevTools protocol and walk through the
// dashboard. Fails on any JavaScript error, any Content Security Policy
// violation, markup from the data rendering as markup, or a missing element.
//
// Usage: node scripts/smoke.mjs [--screenshots=<dir>]   (CHROME=/path/to/chrome to override)
// Needs: Node 22+ (built-in WebSocket), PHP with pdo_sqlite, Chrome or Chromium.
// No npm dependencies: this is a dev tool, Vaka itself stays dependency-free.

import { spawn, execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
// A port the system hands out, never a fixed one: another project's test server on the same machine may hold
// a fixed port, and then a check that something answers there passes against the wrong server
const freePort = () => new Promise((resolvePort, reject) => {
  const srv = createServer();
  srv.once('error', reject);
  srv.listen(0, '127.0.0.1', () => { const { port } = srv.address(); srv.close(() => resolvePort(port)); });
});
const PHP_PORT = await freePort();
const EMPTY_PORT = await freePort();
const NO_USERS_PORT = await freePort();
const TWELVE_PORT = await freePort();
const CDP_PORT = await freePort();
// Vaka's zone in the test. The demo's outages are minutes old, so in the first
// hour after midnight in Stockholm they fall on two days and the day bars'
// checks fail; London is an hour behind, so its day has not turned yet.
const ZONE = Number(new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Stockholm', hour: 'numeric', hourCycle: 'h23' }).format(new Date())) === 0 ? 'Europe/London' : 'Europe/Stockholm';
const CITY = ZONE.split('/')[1];
const shotsArg = process.argv.find((a) => a.startsWith('--screenshots='));
const shotsDir = shotsArg ? resolve(shotsArg.slice('--screenshots='.length)) : null;
const tmp = mkdtempSync(join(tmpdir(), 'vaka-smoke-'));
const children = [];

function findChrome() {
  const candidates = [
    process.env.CHROME,
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/usr/bin/google-chrome',
    '/usr/bin/google-chrome-stable',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
  ].filter(Boolean);
  const found = candidates.find((p) => existsSync(p));
  if (!found) throw new Error('Chrome not found. Set CHROME=/path/to/chrome');
  return found;
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function waitForHttp(url, tries = 50) {
  for (let i = 0; i < tries; i++) {
    try {
      const r = await fetch(url);
      if (r.status < 500) return r;
    } catch {}
    await sleep(100);
  }
  throw new Error(`Timed out waiting for ${url}`);
}

async function cleanup() {
  await Promise.all(children.map((c) => new Promise((done) => {
    if (c.exitCode !== null) return done();
    c.once('exit', done);
    try { c.kill('SIGKILL'); } catch { done(); }
  })));
  try { rmSync(tmp, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 }); } catch {}
}

async function main() {
  // 1. A demo database and a user
  const dbPath = join(tmp, 'demo.sqlite');
  const usersFile = join(tmp, 'users.json');
  execFileSync('php', [join(root, 'scripts/seed-demo.php'), dbPath, '--hostile'], { stdio: 'ignore' });
  execFileSync('php', ['-r', 'file_put_contents($argv[1], json_encode(["smoke" => ["password" => password_hash("smoke password", PASSWORD_BCRYPT, ["cost" => 4]), "groups" => []]]));', usersFile]);

  // 2. Serve it
  const env = { ...process.env, VAKA_ENV_FILE: '/dev/null', DB_PATH: dbPath, USERS_FILE: usersFile, TIMEZONE: ZONE };
  children.push(spawn('php', ['-S', `127.0.0.1:${PHP_PORT}`, '-t', join(root, 'public')], { env, stdio: 'ignore' }));
  const base = `http://127.0.0.1:${PHP_PORT}`;
  await waitForHttp(`${base}/?health`);

  // 3. Headless Chrome with remote debugging
  const chrome = spawn(findChrome(), [
    '--headless=new', `--remote-debugging-port=${CDP_PORT}`, `--user-data-dir=${join(tmp, 'chrome')}`,
    '--no-first-run', '--no-default-browser-check', '--disable-gpu', '--window-size=1280,1400', 'about:blank',
  ], { stdio: ['ignore', 'ignore', 'pipe'] });
  children.push(chrome);
  let chromeLog = '';
  chrome.stderr.on('data', (d) => { chromeLog = (chromeLog + d).slice(-4000); });
  try {
    await waitForHttp(`http://127.0.0.1:${CDP_PORT}/json/version`, 300);
  } catch (e) {
    throw new Error(`${e.message}\nChrome stderr (tail):\n${chromeLog}`);
  }
  const targets = await (await fetch(`http://127.0.0.1:${CDP_PORT}/json/list`)).json();
  const page = targets.find((t) => t.type === 'page');

  const ws = new WebSocket(page.webSocketDebuggerUrl);
  await new Promise((done, fail) => { ws.onopen = done; ws.onerror = fail; });
  let id = 0;
  const pending = new Map();
  const errors = [];
  const listeners = [];
  ws.onmessage = (msg) => {
    const data = JSON.parse(msg.data);
    if (data.id && pending.has(data.id)) {
      const { resolve: ok, reject } = pending.get(data.id);
      pending.delete(data.id);
      data.error ? reject(new Error(data.error.message)) : ok(data.result);
      return;
    }
    if (data.method === 'Runtime.exceptionThrown') {
      const d = data.params.exceptionDetails;
      errors.push(`Exception: ${d.exception?.description || d.text}`);
    }
    if (data.method === 'Runtime.consoleAPICalled' && data.params.type === 'error') {
      errors.push(`console.error: ${data.params.args.map((a) => a.value ?? a.description).join(' ')}`);
    }
    if (data.method === 'Log.entryAdded') {
      const entry = data.params.entry;
      if (/Content Security Policy|Refused to/i.test(entry.text)) errors.push(`CSP: ${entry.text}`);
      // A refused form answers 422 on purpose: the page shows why
      else if (entry.level === 'error' && !/favicon/.test(entry.url || '') && !(/status of 422/.test(entry.text) && /api=(site-save|group-save|maintenance-add)/.test(entry.url || ''))) errors.push(`Log error: ${entry.text} ${entry.url || ''}`);
    }
    listeners.forEach((l) => l(data));
  };
  const send = (method, params = {}) => new Promise((ok, reject) => {
    const msgId = ++id;
    pending.set(msgId, { resolve: ok, reject });
    ws.send(JSON.stringify({ id: msgId, method, params }));
  });
  const loaded = () => new Promise((done) => {
    const l = (d) => { if (d.method === 'Page.loadEventFired') { listeners.splice(listeners.indexOf(l), 1); done(); } };
    listeners.push(l);
  });
  const evaluate = async (fn, ...args) => {
    const r = await send('Runtime.evaluate', { expression: `(${fn.toString()})(...${JSON.stringify(args)})`, awaitPromise: true, returnByValue: true });
    if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
    return r.result.value;
  };
  const screenshot = async (name) => {
    if (!shotsDir) return;
    mkdirSync(shotsDir, { recursive: true });
    const { data } = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
    writeFileSync(join(shotsDir, `${name}.png`), Buffer.from(data, 'base64'));
  };

  await send('Page.enable');
  await send('Runtime.enable');
  // The browser in Vaka's zone, which is not UTC, so a time read in the wrong zone shows (and CI's UTC never hides it)
  await send('Emulation.setTimezoneOverride', { timezoneId: ZONE });
  // Focus events as in a window that has focus, which a headless one does not
  await send('Emulation.setFocusEmulationEnabled', { enabled: true });
  await send('Log.enable');
  // A violation report the page cannot hide: CDP-added scripts are not subject to the CSP
  await send('Page.addScriptToEvaluateOnNewDocument', {
    source: "window.__csp = []; document.addEventListener('securitypolicyviolation', function (e) { window.__csp.push(e.violatedDirective + ' ' + e.blockedURI); });",
  });

  // 4. Log in through the form, as a person would
  let wait = loaded();
  await send('Page.navigate', { url: `${base}/?login` });
  await wait;
  await screenshot('login');
  wait = loaded();
  await evaluate(() => {
    document.querySelector('#username').value = 'smoke';
    document.querySelector('#password').value = 'smoke password';
    document.querySelector('form').submit();
  });
  await wait;

  // 5. Walk through the dashboard
  const steps = async () => {
    const fails = [];
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    const q = (s) => document.querySelector(s);
    const all = (s) => [...document.querySelectorAll(s)];
    const waitFor = async (fn, what, ms = 5000) => {
      for (let t = 0; t < ms; t += 50) { if (fn()) return true; await sleep(50); }
      fails.push(`timed out waiting for ${what}`);
      return false;
    };
    const expect = (cond, what) => { if (!cond) fails.push(what); };

    if (!await waitFor(() => q('#app[aria-busy="false"]'), 'the dashboard to render')) return fails;

    // What is wrong comes first, in plain words
    expect(/2 sites are down/.test(q('.watch h1')?.textContent || ''), 'the watch says how many sites are down and need you');
    expect(/1 more is down but snoozed/.test(q('.watch-line')?.textContent || ''), 'the watch says how many more are snoozed');
    expect(document.title === '(2) Vaka', `the tab title counts the outages (got "${document.title}")`);
    // The owl perches on the panel's bottom edge, its talons over it, under tonight's moon
    const panel = q('.watch')?.getBoundingClientRect();
    const talons = q('.watch .owl-talons');
    expect(talons && getComputedStyle(talons).display !== 'none' && getComputedStyle(q('.watch .owl-feet')).display === 'none', 'the owl grips the edge with its talons, not its standing feet');
    expect(talons && talons.getBoundingClientRect().bottom > panel.bottom && talons.getBoundingClientRect().top < panel.bottom, 'the talons reach over the panel\'s bottom edge');
    const moonWords = q('.watch-moon')?.getAttribute('data-tip') || '';
    expect(/^Tonight's moon: (new moon|waxing crescent|first quarter|waxing gibbous|full moon|waning gibbous|last quarter|waning crescent), \d+% lit$/.test(moonWords), `the moon knows which it is (got "${moonWords}")`);
    const moonBox = q('.watch-moon')?.getBoundingClientRect();
    if (moonBox) {
      q('.watch-moon').dispatchEvent(new PointerEvent('pointermove', { bubbles: true, pointerType: 'mouse', clientX: moonBox.left + 10, clientY: moonBox.top + 10 }));
      const moonTip = q('.hover-tip.is-visible.is-moonlight');
      expect(moonTip?.textContent === moonWords && moonTip.getBoundingClientRect().right <= moonBox.left, 'hovering the moon names it at once, beside it in moonlight');
      q('.watch-moon').dispatchEvent(new PointerEvent('pointerout', { bubbles: true, pointerType: 'mouse', relatedTarget: q('.watch h1') }));
      expect(!q('.hover-tip.is-visible'), 'the moon\'s tooltip goes when the pointer leaves it');
    }
    expect(/favicon-alert\.svg$/.test(q('[data-favicon]').getAttribute('href')), 'the favicon turns to the alarmed owl');
    expect(q('.brand-name')?.textContent === 'Vaka' && q('.brand .mark'), 'the mark and the name, Capitalised, in the top bar');
    expect(q('.watch-owl.is-awake'), 'the owl in the night panel is wide awake while a site is down');
    expect(/Made by\s*Webready\s*in Sweden/.test(q('.foot .credit')?.textContent || '') && q('.foot .credit a[href="https://webready.se"]'), 'the footer credits Webready, linked');
    expect(q('.foot .credit a[href="https://github.com/webready-se/puls"] svg[aria-label="Puls"]'), 'the footer shows Puls, the released sibling, linked');
    expect(all('.foot .credit-mark').every((mark) => mark.closest('a')), 'every mark in the footer is a link');
    expect(/^Vaka .+ · checks run every minute, the last at \d\d:\d\d$/.test(q('[data-foot]')?.textContent || ''), 'the footer says the version and how fresh the results are');
    expect(/Watching 14 sites · checked/.test(q('[data-region="run-state"]').textContent), 'top bar says how many sites Vaka watches and when it checked');
    // Needs your attention: one compact row each, outages first, the quiet ones folded away last
    const rows = all('.attention > .incidents > .incident');
    const summary = (row) => row?.querySelector('summary')?.textContent || '';
    expect(rows.length === 7, `seven rows need attention (got ${rows.length})`);
    expect(/shop\.example\s*is down/.test(summary(rows[0])), 'an outage comes first');
    expect(/The site answered 503/.test(summary(rows[0])), 'the row says why in one line');
    expect(/The site itself answers with an error/.test(rows[0]?.textContent || ''), 'the advice is inside the row');
    expect(/blog\.example\s*failed a check/.test(summary(rows[2])), 'a failing check comes after the outages');
    expect(/cafe\.example\s*2 private files are public/.test(summary(rows[3])) && /\.env, \.git\//.test(summary(rows[3])), 'public files come after them, and say which');
    expect(/the environment file/.test(rows[3]?.textContent || '') && /change every password and key/.test(rows[3]?.textContent || ''), 'the row says what each file holds and what to do');
    expect(/booking\.example\s*certificate expires in 6 days/.test(summary(rows[4])), 'an expiring certificate comes after what is broken now');
    expect(/docs\.example\s*domain expires in 12 days/.test(summary(rows[5])), 'a domain that runs out soon comes with the other warnings');
    expect(/Renew it with the registrar/.test(rows[5]?.textContent || ''), 'the domain row says what to do');
    expect(/1 site is down, snoozed/.test(summary(rows[6])) && /deals2\.example/.test(rows[6].textContent), 'snoozed outages are folded into one row at the end');


    expect(!q('.attention details[open]'), 'with two outages, every row starts closed');
    expect(rows.every((row) => row.getBoundingClientRect().height < 90), 'closed rows are compact');

    // A row opens on click and stays open across a refresh
    rows[4].querySelector('summary').click();
    expect(q('.attention details[data-key^="cert:"]').open, 'a click opens the row');
    document.dispatchEvent(new Event('visibilitychange'));
    await sleep(400);
    expect(q('.attention details[data-key^="cert:"]')?.open, 'an open row stays open when the page refreshes');

    // Every site, by group, with its numbers
    expect(all('.site-row').length === 15, `15 site rows (got ${all('.site-row').length})`);
    expect(all('.group').length === 5, 'five groups, sites without a group last');
    expect(/No group/.test(all('.group').pop()?.textContent || ''), 'sites without a group come last');
    expect(all('.site-row .days i').length === 15 * 30, 'thirty days of bars on every row');
    expect(all('.site-row .days i.is-partial, .site-row .days i.is-down').length > 0, 'days with downtime stand out');
    expect(all('.site-row .days i.is-planned').length >= 8, 'a day down only in maintenance has its own colour');
    // A bar tells its day at once on hover, and the tooltip goes when the pointer leaves
    const point = (node, type, extra = {}) => {
      const r = node.getBoundingClientRect();
      node.dispatchEvent(new PointerEvent(type, { bubbles: true, pointerType: 'mouse', clientX: r.left + r.width / 2, clientY: r.top + r.height / 2, ...extra }));
    };
    const cafe = all('.site-row').find((r) => /cafe\.example/.test(r.textContent));
    const longOutage = cafe?.querySelector('.days i.is-down');
    if (longOutage) {
      point(longOutage, 'pointermove');
      expect(q('.hover-tip.is-visible')?.textContent === longOutage.getAttribute('data-tip'), 'hovering a bar shows its day in a tooltip');
      expect(/^\w{3} \d{1,2} \w{3}: down once, 1 h 40 min$/.test(longOutage.getAttribute('data-tip')), `a bar says when and how long it was down (got "${longOutage.getAttribute('data-tip')}")`);
      point(longOutage, 'pointerout', { relatedTarget: cafe });
      expect(!q('.hover-tip.is-visible'), 'the tooltip goes when the pointer leaves the bars');
    } else {
      fails.push('cafe.example has a red day 16 days ago');
    }
    expect(/: down \d+ min in maintenance \(planned, not counted\)$/.test(q('.site-row .days i.is-planned')?.getAttribute('data-tip') || ''), 'a maintenance day says it is planned and not counted');
    expect(all('.site-row').some((r) => /behind Cloudflare/.test(r.textContent)), 'a CDN site says so');
    expect(all('.site-row').some((r) => /cafe\.example\s*2 private files are public/.test(r.textContent)), 'a site with public files says so in its row');
    expect(all('.columns').every((c) => /Headers/.test(c.textContent)), 'a column for the security headers grade');
    expect(all('.site-row').some((r) => /www\.example\.com/.test(r.textContent) && r.querySelectorAll('.site-figure')[2]?.textContent === 'Aheaders'), 'a site row shows its headers grade');

    // Hostile names and messages are text, never markup
    expect(!q('#app [onerror]') && !q('#app img') && !q('#app script'), 'no markup from the data in the page');
    expect(window.__xss === undefined, 'no script from the data ran');
    expect(all('.site-name small').some((n) => n.textContent === '<img src=x onerror="window.__xss=2">'), 'a hostile site name shows as plain text');
    expect(all('.group-head h3').some((n) => n.textContent === '"><img src=x data-xss="group">'), 'a hostile group name shows as plain text');

    // Filter
    const filter = q('[data-filter]');
    filter.value = 'deals';
    filter.dispatchEvent(new Event('input'));
    expect(all('.site-row').length === 3, 'filtering by name narrows the list');
    filter.value = 'zzz';
    filter.dispatchEvent(new Event('input'));
    expect(/No sites match "zzz"/.test(q('.no-match')?.textContent || ''), 'a filter with no hits says so');
    filter.value = '';
    filter.dispatchEvent(new Event('input'));

    // Site detail: history and response times
    all('.site-row').find((r) => /shop\.example/.test(r.textContent)).click();
    if (await waitFor(() => q('dialog.drawer[open] h2'), 'the site detail to open')) {
      expect(q('dialog.drawer h2').textContent === 'shop.example', 'the detail is for the site clicked');
      expect(getComputedStyle(document.documentElement).overflow === 'hidden', 'the page behind the detail does not scroll');
      expect(q('dialog.drawer .chart'), 'a response time chart');
      const bars = all('dialog.drawer .days button');
      const note = q('dialog.drawer .day-note');
      expect(bars.length === 30, 'thirty days in the detail');
      expect(note?.textContent === 'Down on 1 of 30 days, 14 min in all.', `the line under the bars sums up the 30 days (got "${note?.textContent}")`);
      expect(all('dialog.drawer .day-key li').length === 3, 'the colours are explained: no outage, under an hour, an hour or more');
      // Hover, then the keyboard: only today is in the tab order, the arrow keys move along
      point(bars[20], 'pointermove');
      expect(note?.textContent === bars[20].getAttribute('data-tip') && bars[20].classList.contains('is-picked'), 'hovering a bar in the detail tells its day in the line');
      point(bars[20], 'pointerout', { relatedTarget: note });
      expect(note?.textContent === note?.getAttribute('data-day-note'), 'moving away brings back the summary');
      expect(bars.filter((b) => b.tabIndex === 0).length === 1 && bars[29].tabIndex === 0, 'one bar, today, is in the tab order');
      expect(/^Today: down once, 14 min$/.test(bars[29].getAttribute('aria-label') || ''), `today's bar says it was down (got "${bars[29].getAttribute('aria-label')}")`);
      bars[29].focus();
      bars[29].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }));
      expect(document.activeElement === bars[28] && bars[28].tabIndex === 0 && bars[29].tabIndex === -1, 'the left arrow moves to yesterday');
      expect(note?.textContent === 'Yesterday: up all day', `the line follows the keyboard (got "${note?.textContent}")`);
      // What Vaka sees, a tile per check, and the settings in a card of their own
      const tiles = all('dialog.drawer .tile');
      const tileText = (i, part) => tiles[i]?.querySelector(part)?.textContent || '';
      expect(tiles.length === 6, `six tiles of what Vaka sees (got ${tiles.length})`);
      expect(tileText(0, '.tile-value') === 'web-1' && tileText(0, '.tile-sub') === '203.0.113.10', 'the server tile names the server and its address');
      expect(tiles.every((t) => t.querySelector('.tile-label')?.textContent && t.querySelector('.tile-value')?.textContent), 'every tile says what it is and how it is');
      expect(tileText(4, '.tile-value') === 'Needs a look: Grade C' && tileText(4, '.tile-sub') === '4 of 6 in place', `the headers tile gives the grade and how many are in place (got "${tileText(4, '.tile-value')}", "${tileText(4, '.tile-sub')}")`);
      const card = q('dialog.drawer .settings-card');
      expect(card?.querySelector('[data-action="edit-site"]') && card.querySelectorAll('.check-chips li').length === 6, 'the settings card has its Change button and lists the six checks');
      expect(all('dialog.drawer .settings dt').every((dt) => !/^(Server|Certificate|Domain|Exposed files)$/.test(dt.textContent)), 'what Vaka found is in the tiles, not in the settings');
      expect(all('dialog.drawer .timeline li').length >= 2, 'the history lists what happened');
      expect(/Grade C: 4 of 6 in place, missing HSTS and CSP/.test(q('dialog.drawer .headers-summary')?.textContent || ''), 'the detail says the headers grade in words');
      const [headerList, mailList] = all('dialog.drawer .header-list');
      expect(headerList?.querySelectorAll('li').length === 6, 'the detail lists the six protections');
      expect([...(headerList?.querySelectorAll('code') || [])].some((c) => /^Content-Security-Policy-Report-Only:/.test(c.textContent)), 'a missing protection comes with the line that adds it (a CSP to try first)');
      const nosniff = [...(headerList?.querySelectorAll('li') || [])].find((li) => /X-Content-Type-Options/.test(li.textContent));
      expect(/Weak/.test(nosniff?.textContent || '') && /a location with its own add_header/.test(nosniff?.textContent || ''), 'nosniff missing on the scripts and styles shows as weak, with where the line goes');
      expect(nosniff?.textContent.includes('/build/<img src=x onerror="window.__xss=4">.js') && window.__xss === undefined, 'a file name from the site shows as plain text');
      expect(all('dialog.drawer .headers-summary')[1]?.textContent === 'Mail for shop.example: SPF and DMARC in place', 'the detail says how the mail records are in words');
      expect(mailList?.querySelectorAll('li').length === 3 && /10 mx1\.mail\.example/.test(mailList.textContent), 'the detail lists MX, SPF and DMARC with what the domain has');
      expect(all('dialog.drawer .tile-when').length >= 4 && all('dialog.drawer .tile-when').every((p) => /^Checked \d/.test(p.textContent)), 'each check\'s tile says when it ran');
      q('dialog.drawer [data-action="recheck"]').click();
      await waitFor(() => /Checking/.test(q('dialog.drawer [data-action="recheck"]')?.textContent || '') && q('dialog.drawer [data-action="recheck"]').disabled, 'Check again to say it is checking');
      expect(/Checking shop\.example again/.test(q('.toast')?.textContent || ''), 'Check again says when the results come');
      expect(location.hash === '#site-1', 'the detail has its own address');
      q('dialog.drawer [data-action="close"]').click();
      expect(!q('dialog.drawer[open]') && location.hash === '', 'closing the detail closes it and its address');
      expect(getComputedStyle(document.documentElement).overflow !== 'hidden', 'the page scrolls again once the detail is closed');
    }

    // Behind Cloudflare the certificate is Cloudflare's, and the tile says who renews it
    all('.site-row').find((r) => /store\.example/.test(r.textContent)).click();
    if (await waitFor(() => q('dialog.drawer[open] h2')?.textContent === 'store.example', 'the CDN site\'s detail to open')) {
      expect(all('dialog.drawer .tile').some((t) => /Certificate/.test(t.textContent) && /renewed by Cloudflare/.test(t.textContent)), 'the certificate tile says Cloudflare renews it');
      q('dialog.drawer [data-action="close"]').click();
    }

    // Adding, changing and removing a site in the dashboard
    q('[data-add-site]').click();
    if (await waitFor(() => q('dialog.drawer[open] [data-form="site"]'), 'the add-site form to open')) {
      expect(document.activeElement === q('#f-url'), 'the form starts in the address field');
      expect(all('[data-form="site"] [name="checks"]').every((box) => box.checked), 'every check is on to start with');
      q('[data-form="site"]').requestSubmit();
      if (await waitFor(() => q('#f-url-error'), 'the form to say what is missing')) {
        expect(/Enter the address/.test(q('#f-url-error').textContent) && q('#f-url').getAttribute('aria-invalid') === 'true', 'an empty address is explained under its field');
        expect(document.activeElement === q('#f-url'), 'the field with the error gets the focus');
      }
      // A typo in the address: not saved, said under the field, and the button offers to add it anyway
      q('#f-url').value = 'shop.invalid';
      q('[data-form="site"]').requestSubmit();
      if (await waitFor(() => /does not resolve/.test(q('#f-url-error')?.textContent || ''), 'the form to say the address does not resolve')) {
        expect(/^shop\.invalid does not resolve: no DNS record says where it is\. Check the spelling/.test(q('#f-url-error').textContent), 'an address that is not in DNS is explained under its field');
        expect(q('[data-form="site"] [type="submit"]')?.textContent === 'Add anyway', 'the button offers to add it anyway');
        expect(!all('.site-row').some((r) => /shop\.invalid/.test(r.textContent)), 'the address that does not resolve was not added');
      }
      q('#f-url').value = 'new.example';
      q('#f-name').value = 'Front <i>door</i>';
      q('#f-keyword').value = '<b>Welcome</b>';
      q('[data-form="site"]').requestSubmit();
      expect(q('[data-form="site"] [type="submit"]')?.textContent === 'Adding…', 'the button says it is adding, right away');
      if (await waitFor(() => q('dialog.drawer[open] h2')?.textContent === 'new.example', 'the new site to open after saving')) {
        expect(q('.toast')?.textContent === 'Added Front <i>door</i> (new.example): the first check runs within a minute', 'the message after adding names the site by its name and address, as text');
        expect(/^Front <i>door<\/i> · /.test(q('dialog.drawer .drawer-head p')?.textContent || ''), 'the detail shows the name under the address');
        expect(all('dialog.drawer dt').some((dt) => dt.textContent === 'Name' && dt.nextElementSibling?.textContent === 'Front <i>door</i>'), 'the settings list the name');
        expect(all('.site-row').some((r) => /new\.example/.test(r.textContent)), 'the new site is in the list');
        expect(all('dialog.drawer .timeline li').some((li) => /Added by smoke/.test(li.textContent)), 'the history says who added it');
        q('[data-action="edit-site"]').click();
        if (await waitFor(() => q('#f-keyword'), 'the edit form')) {
          expect(q('#f-keyword').value === '<b>Welcome</b>' && !q('[data-form="site"] b'), 'the edit form shows the keyword as text');
          q('[data-action="remove-ask"]').click();
          if (await waitFor(() => q('[data-action="remove-site"]'), 'the remove confirmation')) {
            expect(/cannot be undone/.test(q('.confirm').textContent), 'removing asks first, in the page');
            q('[data-action="remove-site"]').click();
            await waitFor(() => !q('dialog.drawer[open]') && !all('.site-row').some((r) => /new\.example/.test(r.textContent)), 'the removed site to leave the list');
          }
        }
      }
    }

    // Groups: a webhook shows masked, a new group shows empty with a way to fill it
    q('[data-action="edit-group"][aria-label="Change the group Customers"]')?.click();
    if (await waitFor(() => q('dialog.drawer[open] [data-form="group"]'), 'the group form to open')) {
      expect(q('#g-name').value === 'Customers' && q('[name="policy"][value="immediate"]').checked, 'the group form shows the group as it is');
      expect(/^Set: hooks\.example\/\.\.\.1234/.test(q('#g-slack').getAttribute('placeholder') || '') && q('#g-slack').value === '', 'the Slack webhook shows masked, never in full');
      expect(!document.documentElement.innerHTML.includes('demo/demo/demo1234'), 'the webhook is nowhere in the page');
      q('[data-action="close"]').click();
      await waitFor(() => !q('dialog.drawer[open]'), 'the group form to close');
    }
    q('[data-add-group]').click();
    if (await waitFor(() => q('dialog.drawer[open] [data-form="group"]'), 'the add-group form to open')) {
      q('#g-name').value = 'Smoke group';
      q('[name="policy"][value="delayed"]').checked = true;
      q('#g-delay').value = '';
      q('[data-form="group"]').requestSubmit();
      if (await waitFor(() => q('#g-delay-error'), 'the group form to say the delay is missing')) {
        expect(document.activeElement === q('#g-delay'), 'the missing delay gets the focus');
        q('#g-delay').value = '15';
        q('[data-form="group"]').requestSubmit();
        if (await waitFor(() => all('.group-head h3').some((n) => n.textContent === 'Smoke group'), 'the new group to show')) {
          const section = all('.group').find((g) => g.querySelector('h3').textContent === 'Smoke group');
          expect(/after 15 min down/.test(section.textContent) && section.querySelector('[data-action="add-site"][data-group="Smoke group"]'), 'an empty group says when it alerts and offers to add a site to it');
          section.querySelector('[data-action="edit-group"]').click();
          if (await waitFor(() => q('[data-action="group-remove-ask"]'), 'the group to open for a change')) {
            q('[data-action="group-remove-ask"]').click();
            await waitFor(() => q('[data-action="remove-group"]'), 'the remove confirmation for the group');
            q('[data-action="remove-group"]').click();
            await waitFor(() => !all('.group-head h3').some((n) => n.textContent === 'Smoke group'), 'the removed group to leave the list');
          }
        }
      }
    }

    // A maintenance window from a site's detail, and the server's name
    all('.site-row').find((r) => /blog\.example/.test(r.textContent)).click();
    if (await waitFor(() => q('dialog.drawer[open] [data-action="add-window"]'), 'the detail with its actions')) {
      q('[data-action="add-window"]').click();
      if (await waitFor(() => q('[data-form="window"]'), 'the maintenance window form')) {
        q('[name="kind"][value="days"]').click();
        await waitFor(() => q('.day-list'), 'the days to show for some days of the week');
        all('.day-list input').forEach((box) => { box.checked = false; });
        q('[data-form="window"]').requestSubmit();
        if (await waitFor(() => q('#w-when-error'), 'the window form to say days are missing')) {
          expect(/Tick the days/.test(q('#w-when-error').textContent), 'a window without days is explained');
          q('.day-list input[value="sat"]').checked = true;
          q('[data-form="window"]').requestSubmit();
          if (await waitFor(() => all('dialog.drawer .settings dd').some((dd) => /^Sat 00:00-07:00/.test(dd.textContent)), 'the new window in the detail')) {
            all('dialog.drawer [data-action="window-remove"]').find((b) => /Sat/.test(b.getAttribute('aria-label'))).click();
            await waitFor(() => !all('dialog.drawer .settings dd').some((dd) => /^Sat 00:00-07:00/.test(dd.textContent)), 'the window to be removed');
          }
          // The same for every site in the group, from the site
          q('[data-action="add-window"]').click();
          if (await waitFor(() => q('[data-form="window"] [name="target"][value="group"]'), 'the choice of the whole group')) {
            q('[name="target"][value="group"]').checked = true;
            q('[data-form="window"]').requestSubmit();
            if (await waitFor(() => all('dialog.drawer .settings dd').some((dd) => /^every day 00:00-07:00, for the group/.test(dd.textContent)), 'the group window in the detail')) {
              all('dialog.drawer [data-action="window-remove"]').find((b) => /every day 00:00-07:00/.test(b.getAttribute('aria-label'))).click();
              await waitFor(() => !all('dialog.drawer .settings dd').some((dd) => /for the group/.test(dd.textContent)), 'the group window to be removed');
            }
          }
        }
      }
      if (await waitFor(() => q('dialog.drawer[open] [data-action="edit-server"]'), 'the server settings button')) {
        q('[data-action="edit-server"]').click();
        if (await waitFor(() => q('[data-form="server"]'), 'the server form')) {
          expect(q('#s-label').value === 'web-2' && /5 sites answer on 198\.51\.100\.7/.test(q('dialog.drawer').textContent), 'the server form shows its name and how many sites are on it');
          q('[data-action="form-cancel"]').click();
          await waitFor(() => q('dialog.drawer[open] [data-action="edit-server"]'), 'cancel to go back to the detail');
        }
      }
      q('dialog.drawer [data-action="close"]').click();
      await waitFor(() => !q('dialog.drawer[open]'), 'the detail to close');
    }

    // Cmd+K (Ctrl+K elsewhere) jumps to the filter; "/" no longer does, and Ctrl+Shift+K is the browser's
    const mac = /Mac|iPhone|iPad/.test(navigator.userAgent);
    const press = (init) => document.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true, cancelable: true, ...init }));
    document.activeElement.blur();
    press({ key: 'k', [mac ? 'metaKey' : 'ctrlKey']: true });
    expect(document.activeElement === q('[data-filter]'), `pressing ${mac ? 'Cmd' : 'Ctrl'}+K focuses the filter`);
    expect(q('.filter kbd')?.textContent === (mac ? '\u2318K' : 'Ctrl K') && q('[data-filter]').getAttribute('aria-keyshortcuts') === (mac ? 'Meta+K' : 'Control+K'), 'the field shows the keys for this computer, and says them to a screen reader');
    document.activeElement.blur();
    press({ key: 'K', shiftKey: true, [mac ? 'metaKey' : 'ctrlKey']: true });
    press({ key: '/' });
    press({});
    expect(document.activeElement !== q('[data-filter]'), 'Shift with it, "/" or a keydown without a key leave the focus where it was');

    // The font is ours, not a fallback
    await document.fonts.ready;
    expect(document.fonts.check('16px Figtree'), 'Figtree is loaded');

    // Snooze from the dashboard
    const first = all('.attention > .incidents > .incident')[0];
    first?.querySelector('summary').click();
    const snoozeButton = first?.querySelector('[data-action="snooze"][data-for="1h"]');
    if (snoozeButton) {
      snoozeButton.click();
      await waitFor(() => /Snoozed shop\.example until/.test(q('[data-region="toast"]').textContent), 'a toast after snoozing');
      await waitFor(() => /2 sites are down, snoozed/.test(q('.attention details[data-key="quiet"] summary')?.textContent || ''), 'the snoozed outage joins the quiet row');
      await waitFor(() => q('.attention details[data-key^="site:"]:not([data-key="site:1"])')?.open, 'the one outage left opens by itself');
    } else {
      fails.push('a snooze button in the first row');
    }

    // Theme: system, light, dark, and back
    const theme = q('[data-action="theme"]');
    theme.click();
    expect(document.documentElement.getAttribute('data-theme') === 'light', 'the theme button switches to light');
    theme.click();
    expect(document.documentElement.getAttribute('data-theme') === 'dark', 'then to dark');
    theme.click();
    expect(!document.documentElement.hasAttribute('data-theme'), 'then back to the system setting');

    expect(document.documentElement.scrollWidth <= window.innerWidth, 'no sideways scrolling on a wide screen');
    expect(!window.__csp.length, `no CSP violations: ${window.__csp.join(', ')}`);
    return fails;
  };

  // What people see first, before anything is clicked
  await evaluate(async () => {
    for (let t = 0; t < 5000 && !document.querySelector('#app[aria-busy="false"]'); t += 50) await new Promise((r) => setTimeout(r, 50));
  });
  await screenshot('first-view');

  const fails = await evaluate(steps);
  await screenshot('dashboard-light');
  await evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
  await screenshot('dashboard-dark');
  await evaluate(() => { document.documentElement.removeAttribute('data-theme'); document.querySelector('.site-row').click(); });
  await sleep(500);
  await screenshot('detail');

  // 6. A phone: everything fits without sideways scrolling
  await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
  wait = loaded();
  await send('Page.navigate', { url: `${base}/` });
  await wait;
  const phone = await evaluate(async () => {
    for (let t = 0; t < 5000 && !document.querySelector('#app[aria-busy="false"]'); t += 50) await new Promise((r) => setTimeout(r, 50));
    // On a phone the layout viewport grows with content that is too wide, so measure against the screen
    const shown = (s) => getComputedStyle(document.querySelector(s)).display !== 'none';
    return { overflow: document.documentElement.scrollWidth - 390, csp: window.__csp, corner: shown('.watch .owl-feet') && !shown('.watch .owl-talons') && !shown('.watch-moon') };
  });
  if (!phone.corner) fails.push('on a phone the owl stands in the corner on its feet, without the moon');
  if (phone.overflow > 0) fails.push(`no sideways scrolling on a phone (${phone.overflow}px too wide)`);
  if (phone.csp.length) fails.push(`no CSP violations on a phone: ${phone.csp.join(', ')}`);
  await screenshot('phone');
  await evaluate(() => document.querySelector('.site-row').click());
  await sleep(600);
  await screenshot('phone-detail');

  // 7. Times: a reader in another time zone is told whose clock the times are on, and TIME_FORMAT=12h shows AM and PM
  await send('Emulation.clearDeviceMetricsOverride');
  const footer = async (url) => {
    wait = loaded();
    await send('Page.navigate', { url });
    await wait;
    return evaluate(async () => {
      for (let t = 0; t < 5000 && !document.querySelector('#app[aria-busy="false"]'); t += 50) await new Promise((r) => setTimeout(r, 50));
      return document.querySelector('[data-foot]')?.textContent || '';
    });
  };
  await send('Emulation.setTimezoneOverride', { timezoneId: 'America/New_York' });
  const away = await footer(`${base}/`);
  if (!new RegExp(`the last at \\d\\d:\\d\\d, ${CITY} time$`).test(away)) fails.push(`a reader in another time zone is told the times are ${CITY} time (got "${away}")`);
  await send('Emulation.setTimezoneOverride', { timezoneId: ZONE });
  // The same database and session on a second server, set to 12h, with a typo in TIMEZONE (the server's zone stands in)
  children.push(spawn('php', ['-S', `127.0.0.1:${TWELVE_PORT}`, '-t', join(root, 'public')], { env: { ...env, TIME_FORMAT: '12h', TIMEZONE: 'Europe/Stokholm' }, stdio: 'ignore' }));
  await waitForHttp(`http://127.0.0.1:${TWELVE_PORT}/?login`);
  const twelve = await footer(`http://127.0.0.1:${TWELVE_PORT}/`);
  if (!/the last at \d{1,2}:\d\d (AM|PM)(, [\w ]+)?$/.test(twelve)) fails.push(`TIME_FORMAT=12h shows the time with AM or PM (got "${twelve}")`);
  const typo = await evaluate(() => document.querySelector('.banner')?.textContent || '');
  if (!/A setting in \.env needs fixing\. TIMEZONE "Europe\/Stokholm" is not a time zone.*Did you mean Europe\/Stockholm\?/.test(typo)) fails.push(`a typo in TIMEZONE is shown, with what was meant (got "${typo}")`);

  // 8. The very first visit: before the first user, then with no sites and cron not running yet
  await send('Emulation.clearDeviceMetricsOverride');
  const emptyEnv = { ...env, DB_PATH: join(tmp, 'empty.sqlite') };
  children.push(spawn('php', ['-S', `127.0.0.1:${EMPTY_PORT}`, '-t', join(root, 'public')], { env: emptyEnv, stdio: 'ignore' }));
  children.push(spawn('php', ['-S', `127.0.0.1:${NO_USERS_PORT}`, '-t', join(root, 'public')], { env: { ...emptyEnv, USERS_FILE: join(tmp, 'no-users.json') }, stdio: 'ignore' }));
  const emptyBase = `http://127.0.0.1:${EMPTY_PORT}`;
  const noUsersBase = `http://127.0.0.1:${NO_USERS_PORT}`;
  await waitForHttp(`${emptyBase}/?login`);
  await waitForHttp(`${noUsersBase}/?login`);

  wait = loaded();
  await send('Page.navigate', { url: `${noUsersBase}/?login` });
  await wait;
  const noUsers = await evaluate(() => ({
    title: document.querySelector('.login-steps h2')?.textContent || '',
    command: document.querySelector('.login-steps > code')?.textContent || '',
    form: Boolean(document.querySelector('#password')),
  }));
  if (noUsers.title !== 'No users yet' || !/^php vaka user:add/.test(noUsers.command) || noUsers.form) fails.push('before the first user, the login page says how to create one instead of showing the form');
  await screenshot('no-users');

  wait = loaded();
  await send('Page.navigate', { url: `${emptyBase}/?login` });
  await wait;
  wait = loaded();
  await evaluate(() => {
    document.querySelector('#username').value = 'smoke';
    document.querySelector('#password').value = 'smoke password';
    document.querySelector('form').submit();
  });
  await wait;
  const empty = await evaluate(async () => {
    for (let t = 0; t < 5000 && !document.querySelector('#app[aria-busy="false"]'); t += 50) await new Promise((r) => setTimeout(r, 50));
    const steps = [...document.querySelectorAll('.empty .steps li')];
    return {
      title: document.querySelector('.empty h2')?.textContent || '',
      steps: steps.length,
      done: steps.filter((li) => li.classList.contains('is-done')).length,
      cron: steps[1]?.querySelector('code')?.textContent || '',
      dir: document.querySelector('.empty > p code')?.textContent || '',
      pillStale: document.querySelector('[data-region="run-state"]').classList.contains('is-stale'),
      imports: /site:import/.test(steps[0]?.textContent || ''),
      sitesHidden: document.querySelector('[data-region="sites"]').hidden,
      pill: document.querySelector('[data-run-text]').textContent,
      csp: window.__csp,
    };
  });
  if (empty.title !== 'Nothing to watch yet' || empty.steps !== 3) fails.push(`the first visit shows the three first steps (got "${empty.title}", ${empty.steps} steps)`);
  if (empty.done !== 0) fails.push('nothing is ticked off before cron runs and alerts are set');
  if (!/^\* \* \* \* \* php[\d.]* \/.+\/vaka run$/.test(empty.cron) || /path\/to/.test(empty.cron)) fails.push(`the cron step shows this server's real line (got "${empty.cron}")`);
  if (!empty.dir.startsWith('/')) fails.push(`the first steps say where to run the commands (got "${empty.dir}")`);
  if (empty.pillStale) fails.push('no red top bar before the first site');
  if (!empty.imports) fails.push('the first step shows how to import a list');
  if (!empty.sitesHidden) fails.push('the empty site list and its filter are hidden');
  if (empty.pill !== 'No sites yet') fails.push(`the top bar says there are no sites yet (got "${empty.pill}")`);
  if (empty.csp.length) fails.push(`no CSP violations on the first visit: ${empty.csp.join(', ')}`);
  await screenshot('first-run');

  // Sites added, cron not started yet: the banner gives the real line, and nothing claims 100% up
  execFileSync('php', [join(root, 'vaka'), 'site:add', 'https://shop.example'], { env: emptyEnv, stdio: 'ignore' });
  wait = loaded();
  await send('Page.navigate', { url: `${emptyBase}/` });
  await wait;
  const waiting = await evaluate(async () => {
    for (let t = 0; t < 5000 && !document.querySelector('#app[aria-busy="false"]'); t += 50) await new Promise((r) => setTimeout(r, 50));
    return {
      banner: document.querySelector('.banner code')?.textContent || '',
      figures: [...document.querySelectorAll('.site-row .site-figure')].map((n) => n.firstChild?.textContent || ''),
      watch: document.querySelector('.watch-stats')?.textContent || '',
    };
  });
  if (!/^\* \* \* \* \* php[\d.]* \/.+\/vaka run$/.test(waiting.banner)) fails.push(`before the first run, the banner gives this server's cron line (got "${waiting.banner}")`);
  if (waiting.figures[1] !== '–') fails.push(`a site not checked yet shows no uptime (got "${waiting.figures[1]}")`);
  if (/Up, 30 days/.test(waiting.watch)) fails.push('the watch shows no 30-day uptime before the first check');

  ws.close();
  const problems = [...fails, ...errors];
  if (problems.length) {
    console.error('Smoke test failed:\n' + problems.map((p) => `  - ${p}`).join('\n'));
    return 1;
  }
  console.log('Smoke test passed' + (shotsDir ? `; screenshots in ${shotsDir}` : '') + '.');
  return 0;
}

let code = 1;
try {
  code = await main();
} catch (e) {
  console.error(e.stack || e.message);
} finally {
  await cleanup();
}
process.exit(code);
