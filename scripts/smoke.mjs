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
  // A second user limited to a group, so moving a site to it says who that shows it to
  execFileSync('php', ['-r', '$h = password_hash("smoke password", PASSWORD_BCRYPT, ["cost" => 4]); file_put_contents($argv[1], json_encode(["smoke" => ["password" => $h, "groups" => []], "reader" => ["password" => $h, "groups" => ["Own sites"]]]));', usersFile]);

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
  const logs = [];
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
    if (data.method === 'Runtime.consoleAPICalled' && data.params.type === 'log') logs.push(data.params.args.map((a) => a.value ?? '').join(' '));
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
    expect(!q('.placeholder') && !q('.is-loading'), 'the loading placeholders are gone once the overview is there');

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
    const watchBox = q('.watch').getBoundingClientRect();
    q('.watch h1').dispatchEvent(new PointerEvent('pointermove', { bubbles: true, pointerType: 'mouse', clientX: watchBox.left + 10, clientY: watchBox.top + 10 }));
    await new Promise((r) => requestAnimationFrame(r));
    expect(all('.watch-owl.is-awake .owl-pupil').length === 2 && all('.watch-owl .owl-pupil').every((pupil) => !pupil.style.transform), 'the wide-awake owl stares ahead instead of following the pointer');
    expect(/Made by\s*Webready\s*in Sweden/.test(q('.foot .credit')?.textContent || '') && q('.foot .credit a[href="https://webready.se"]'), 'the footer credits Webready, linked');
    expect(q('.foot .credit a[href="https://github.com/webready-se/puls"] svg[aria-label="Puls"]'), 'the footer shows Puls, the released sibling, linked');
    expect(q('.foot .credit a[href="https://github.com/webready-se/vaka"] svg[aria-label="Vaka"]'), 'the footer shows Vaka itself, linked to its repository');
    expect(all('.foot .credit-mark').every((mark) => mark.closest('a')), 'every mark in the footer is a link');
    expect(/^Vaka .+ · checks run every minute, the last at \d\d:\d\d$/.test(q('[data-foot]')?.textContent || ''), 'the footer says the version and how fresh the results are');
    expect(/Watching 14 sites · checked/.test(q('[data-region="run-state"]').textContent), 'top bar says how many sites Vaka watches and when it checked');
    // Needs your attention: one compact row each, outages first, the quiet ones folded away last
    const rows = all('.attention > .incidents > .incident');
    const summary = (row) => row?.querySelector('summary')?.textContent || '';
    expect(rows.length === 8, `eight rows need attention (got ${rows.length})`);
    expect(/shop\.example\s*is down/.test(summary(rows[0])), 'an outage comes first');
    expect(/The site answered 503/.test(summary(rows[0])), 'the row says why in one line');
    expect(/The site itself answers with an error/.test(rows[0]?.textContent || ''), 'the advice is inside the row');
    expect(/blog\.example\s*failed a check/.test(summary(rows[2])), 'a failing check comes after the outages');
    expect(/cafe\.example\s*2 private files are public/.test(summary(rows[3])) && /\.env, \.git\//.test(summary(rows[3])), 'public files come after them, and say which');
    expect(/the environment file/.test(rows[3]?.textContent || '') && /change every password and key/.test(rows[3]?.textContent || ''), 'the row says what each file holds and what to do');
    expect(/example\.net\s*is hidden behind a bot check/.test(summary(rows[4])) && /Imunify360's bot check answers instead of the site/.test(summary(rows[4])), 'a site behind a bot check leads the warnings, and says whose');
    expect(/an outage would go unnoticed/.test(rows[4]?.textContent || '') && rows[4]?.querySelector('code')?.textContent === '2001:db8::5', 'the row says why it matters and the address to let through');
    expect(/booking\.example\s*certificate expires in 6 days/.test(summary(rows[5])), 'an expiring certificate comes after what is broken now');
    expect(/docs\.example\s*domain expires in 12 days/.test(summary(rows[6])), 'a domain that runs out soon comes with the other warnings');
    expect(/Renew it with the registrar/.test(rows[6]?.textContent || ''), 'the domain row says what to do');
    expect(/1 site is down, snoozed/.test(summary(rows[7])) && /deals2\.example/.test(rows[7].textContent), 'snoozed outages are folded into one row at the end');


    expect(!q('.attention details[open]'), 'with two outages, every row starts closed');
    expect(rows.every((row) => row.getBoundingClientRect().height < 90), 'closed rows are compact');

    // A row opens on click and stays open across a refresh
    const listHeight = () => Math.round(q('.attention').getBoundingClientRect().height);
    const closedHeight = listHeight();
    rows[5].querySelector('summary').click();
    expect(q('.attention details[data-key^="cert:"]').open, 'a click opens the row');
    const opening = listHeight();
    await sleep(400);
    const opened = listHeight();
    expect(opening < opened && opened > closedHeight, `a row opened by a click grows to its height, so the rows under it slide down (${closedHeight}, ${opening}, ${opened})`);
    // Every frame through the refresh: a row drawn open again never grows again
    const heights = new Set();
    document.dispatchEvent(new Event('visibilitychange'));
    for (const until = performance.now() + 400; performance.now() < until; await sleep(16)) heights.add(listHeight());
    expect(heights.size === 1 && heights.has(opened), `and a refresh does not grow it again (heights ${[...heights].join(', ')})`);
    expect(q('.attention details[data-key^="cert:"]')?.open, 'an open row stays open when the page refreshes');

    // Every site, by group, with its numbers
    expect(all('.site-row').length === 15, `15 site rows (got ${all('.site-row').length})`);
    const hiddenRow = all('.site-row').find((row) => /example\.net/.test(row.textContent));
    expect(/hidden behind a bot check/.test(hiddenRow?.textContent || '') && !/200 ms/.test(hiddenRow?.textContent || ''), 'a site behind a bot check says so in the list, without a response time Vaka did not measure');
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
      expect(getComputedStyle(bars[20]).boxShadow === 'none' && note.classList.contains('is-picked') && getComputedStyle(bars[3], '::after').opacity === '0.35' && getComputedStyle(bars[20], '::after').opacity === '0', 'the picked day has no ring and its line is in the accent, and the other days step back');
      point(bars[20], 'pointerout', { relatedTarget: note });
      expect(note?.textContent === note?.getAttribute('data-day-note') && !note.classList.contains('is-picked'), 'moving away brings back the summary');
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
      const todo = [...(headerList?.querySelectorAll(':scope > li') || [])];
      expect(todo.map((li) => li.querySelector('b')?.textContent).join(', ') === 'X-Content-Type-Options, HSTS, CSP', `what to add comes first, the safest first and CSP last (got ${todo.map((li) => li.querySelector('b')?.textContent).join(', ')})`);
      expect(/^In place: Referrer-Policy, .*Permissions-Policy$/.test(q('dialog.drawer .headers-done summary')?.textContent || ''), 'what is in place is one line');
      expect([...(headerList?.querySelectorAll('code') || [])].some((c) => /^Content-Security-Policy-Report-Only:/.test(c.textContent)), 'a missing protection comes with the line that adds it (a CSP to try first)');
      // The lines in the web server's own form, so they paste in as they are; the header itself when the server is not known
      const formats = all('dialog.drawer .header-format button');
      expect(formats.map((b) => b.textContent).join(', ') === 'Header, nginx, Apache' && formats[0].getAttribute('aria-pressed') === 'true', `the lines come as a header, for nginx or for Apache, as a header when the server is not known (got ${formats.map((b) => b.textContent + ':' + b.getAttribute('aria-pressed')).join(', ')})`);
      expect(all('dialog.drawer .header-list .header-fix [data-action="copy"]').length === 3, 'every line has its Copy');
      formats[1].click();
      const nginxLines = all('dialog.drawer .header-list .header-fix code').map((c) => c.textContent);
      expect(nginxLines.some((l) => /^add_header Content-Security-Policy-Report-Only "default-src 'self'; script-src 'self' https:\/\/analytics\.example 'sha256-[^"]+" always;$/.test(l)) && nginxLines.every((l) => /^add_header \S+ ".*" always;$/.test(l)), `for nginx the lines are add_header lines, the CSP one drafted from the page (got "${nginxLines[2]}")`);
      expect(document.activeElement === q('dialog.drawer .header-format [data-format="nginx"]') && document.activeElement.getAttribute('aria-pressed') === 'true', 'the choice keeps the focus and shows as pressed');
      expect(q('dialog.drawer .header-list .header-fix [data-action="copy"]')?.getAttribute('data-copy') === nginxLines[0], 'Copy copies the line as shown');
      q('dialog.drawer .header-format [data-format="apache"]').click();
      expect(all('dialog.drawer .header-list .header-fix code').every((c) => /^Header always set \S+ ".*"$/.test(c.textContent)), 'for Apache the lines are Header lines');
      expect(q('dialog.drawer .headers-tip summary')?.textContent === 'Adding them in Apache' && /^Put the Header lines/.test(q('dialog.drawer .headers-tip p')?.textContent || ''), 'the tip under the list follows the choice');
      expect(localStorage.getItem('vaka-header-format') === 'apache', 'the choice is kept for the next site');
      q('dialog.drawer .header-format [data-format="header"]').click();
      expect(/^Content-Security-Policy-Report-Only: default-src 'self'; script-src /.test(all('dialog.drawer .header-list .header-fix code')[2]?.textContent || ''), 'back to the header as sent');
      expect(all('dialog.drawer .header-more').length === 3 && all('dialog.drawer .header-more').every((d) => !d.open), 'what each does and how to add it is one click away, closed at first');
      expect(todo[1]?.querySelector('.fix-label')?.textContent === 'Add this line:' && todo[0]?.querySelector('.fix-label')?.textContent === 'The line, for those files too:', 'every line says what to do with it');
      expect(todo[0]?.querySelector('.header-sent code')?.textContent === 'nosniff', 'a weak protection shows what the site sends');
      // What is in place opens to what the site sends
      const done = q('dialog.drawer .headers-done');
      expect(done?.tagName === 'DETAILS' && !done.open, 'what is in place is one closed line');
      expect(all('dialog.drawer .headers-done .header-values li').length === 3 && all('dialog.drawer .headers-done .header-values code').some((c) => c.textContent === 'SAMEORIGIN'), `it opens to what the site sends for each (got ${all('dialog.drawer .headers-done .header-values li').length} items)`);
      const csp = todo[2];
      expect(/do it last, it can break the site/.test(csp?.querySelector('p')?.textContent || ''), 'CSP says to do it last');
      const starts = [...(csp?.querySelectorAll('.header-scripts code') || [])].map((c) => c.textContent);
      expect(starts.length === 3 && /^window\.dataLayer = window\.dataLayer/.test(starts[0]), `the CSP item names the inline scripts it would block by their start (got ${starts.length})`);
      expect(starts[2] === 'document.write("<img src=x onerror=window.__xss=5>")' && window.__xss === undefined, 'a script from the site shows as plain text');
      const nosniff = todo[0];
      expect(/Weak/.test(nosniff?.textContent || '') && /a location with its own add_header/.test(nosniff?.textContent || ''), 'nosniff missing on the scripts and styles shows as weak, with where the line goes');
      expect(all('dialog.drawer .headers-tip').length === 1 && q('dialog.drawer .headers-tip summary')?.textContent === 'Adding them in nginx' && /^Put the add_header lines/.test(q('dialog.drawer .headers-tip p')?.textContent || ''), 'the detail says once, under the list and one click away, how to add the headers in nginx');
      expect(nosniff?.textContent.includes('/build/<img src=x onerror="window.__xss=4">.js') && window.__xss === undefined, 'a file name from the site shows as plain text');
      expect(all('dialog.drawer .headers-summary')[1]?.textContent === 'Mail for shop.example: SPF and DMARC in place', 'the detail says how the mail records are in words');
      expect(mailList?.querySelectorAll('li').length === 3 && /10 mx1\.mail\.example/.test(mailList.textContent), 'the detail lists MX, SPF and DMARC with what the domain has');
      expect(all('dialog.drawer .tile-when').length >= 4 && all('dialog.drawer .tile-when').every((p) => /^Checked \d/.test(p.textContent)), 'each check\'s tile says when it ran');
      q('dialog.drawer [data-action="recheck"]').click();
      await waitFor(() => /Checking/.test(q('dialog.drawer [data-action="recheck"]')?.textContent || '') && q('dialog.drawer [data-action="recheck"]').disabled, 'Check again to say it is checking');
      expect(/Checking shop\.example again/.test(q('.toast')?.textContent || ''), 'Check again says when the results come');
      // The detail is a modal dialog in the top layer; only a popover shown after it lies above it (and hit tests cannot tell, since all outside a modal is inert)
      expect(q('.toast').matches(':popover-open') && q('dialog.drawer').matches(':modal'), 'the message shows above the open site detail, not behind it');
      // Each check says it is checking until it has run again, the response time too
      expect(/^Checking…$/.test(q('dialog.drawer .fact-when')?.textContent || '') && all('dialog.drawer .tile-when').every((p) => p.textContent === 'Checking…' && p.querySelector('.checking-dot')), 'while Check again waits, each check says it is checking');
      expect(q('dialog.drawer [data-action="recheck"].is-busy .spinner') && all('dialog.drawer .tile[data-check]').every((t) => t.classList.contains('is-checking')) && q('dialog.drawer .fact[data-check="uptime"]')?.classList.contains('is-checking'), 'the button turns while it works, and every check it waits for is ringed');
      // As if the next run had come: first one check has run again, then all of them, and the page finds out by asking when each ran
      const realFetch = window.fetch;
      const later = new Date(Date.now() + 60000).toISOString();
      let ran = ['ssl'];
      const json = (data) => new Response(JSON.stringify(data), { status: 200, headers: { 'Content-Type': 'application/json' } });
      window.fetch = (url, options) => realFetch(url, options).then((response) => {
        if (/[?&]api=site-times&id=1$/.test(String(url))) {
          return response.json().then((data) => { ran.forEach((key) => { if (data.times[key]) data.times[key] = later; }); return json(data); });
        }
        if (!/[?&]api=site&id=1$/.test(String(url))) return response;
        return response.json().then((data) => {
          if (ran.includes('uptime')) data.site.checked_at = later;
          ['ssl', 'domain', 'exposed', 'headers', 'dns'].forEach((key) => { if (data.site[key]?.checked_at && ran.includes(key)) data.site[key].checked_at = later; });
          // The headers were fixed; the mail records came back the same, with something still missing
          if (ran.includes('headers')) Object.assign(data.site.headers, { grade: 'A', summary: 'Grade A: all 6 in place' });
          if (data.site.dns) data.site.dns.status = 'warn';
          return json(data);
        });
      });
      const closeButton = q('dialog.drawer [data-action="close"]');
      closeButton.focus();
      await waitFor(() => /^Checking… 1 of \d done$/.test(q('dialog.drawer [data-action="recheck"]')?.textContent || ''), 'Check again to find a check that ran again by itself, within seconds', 9000);
      const sslTile = q('dialog.drawer .tile[data-check="ssl"]');
      expect(parseFloat(q('dialog.drawer [data-action="recheck"]')?.style.getPropertyValue('--progress')) > 0 && !sslTile?.classList.contains('is-checking') && q('dialog.drawer .tile[data-check="headers"]')?.classList.contains('is-checking'), 'the button fills as checks come back, and a check that has run is no longer ringed');
      expect(sslTile?.querySelector('.tile-when')?.textContent === 'Checked just now' && sslTile.classList.contains('is-fresh'), 'a check that has run again says so and lights up');
      expect(q('dialog.drawer .tile[data-check="headers"] .tile-when')?.textContent === 'Checking…', 'the others still say they are checking');
      expect(document.activeElement === closeButton && q('dialog.drawer [data-action="close"]') === closeButton, 'only what changed is replaced: focus stays where it was');
      ran = ['uptime', 'ssl', 'domain', 'exposed', 'headers', 'dns'];
      await waitFor(() => /^Checked again \d/.test(q('dialog.drawer [data-action="recheck"]')?.textContent || ''), 'Check again to say when every check had run again', 9000);
      expect(!q('dialog.drawer [data-action="recheck"]').disabled && q('.toast')?.textContent === 'Every check of shop.example ran again: security headers changed', `once all have run, the button can be pressed again, and a message says what changed (got "${q('.toast')?.textContent}")`);
      expect(q('dialog.drawer .tile[data-check="headers"] .tile-was')?.textContent === 'Was: Grade C', `a check whose result changed says what it was (got "${q('dialog.drawer .tile[data-check="headers"] .tile-was')?.textContent}")`);
      expect(!q('dialog.drawer .tile[data-check="ssl"] .tile-was'), 'and one that did not, nothing');
      expect(/^Mail is the same as before: a record just added can take up to 30 minutes to show/.test(q('dialog.drawer .recheck-note')?.textContent || ''), 'mail that came back the same with something missing says why it may not show yet');
      window.fetch = realFetch;
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

    // Frame protection sent twice stays in place: it says who sends which and where to look, with no line to add
    all('.site-row').find((r) => /booking\.example/.test(r.textContent)).click();
    if (await waitFor(() => q('dialog.drawer[open] h2')?.textContent === 'booking.example', 'the booking site\'s detail to open')) {
      const done = q('dialog.drawer .headers-done');
      expect(/frame protection \(sent twice\)/.test(done?.querySelector('summary')?.textContent || ''), `what is in place says a header is sent twice (got "${done?.querySelector('summary')?.textContent}")`);
      const row = [...(done?.querySelectorAll('.header-values li') || [])].find((li) => /^Frame protection/.test(li.textContent));
      expect(row?.querySelector('code')?.textContent === 'DENY, SAMEORIGIN', 'it opens to both values');
      expect(/^Sent twice: "DENY" from the application and "SAMEORIGIN" from the web server\. Browsers then allow no frames at all/.test(row?.querySelector('.header-why')?.textContent || ''), `it says who sends which (got "${row?.querySelector('.header-why')?.textContent}")`);
      expect(/^Search the web server's configuration for X-Frame-Options and remove that line\./.test(row?.querySelector('.header-tip')?.textContent || '') && row?.querySelector('.header-tip strong'), 'and where to look, its takeaway in bold');
      q('dialog.drawer [data-action="close"]').click();
    }

    // Any other header sent twice is weak: where to look is in sight, in place of a line to add
    all('.site-row').find((r) => /deals1\.example/.test(r.textContent)).click();
    if (await waitFor(() => q('dialog.drawer[open] h2')?.textContent === 'deals1.example', 'the deals site\'s detail to open')) {
      const twice = all('dialog.drawer .header-list > li').find((li) => /^Referrer-Policy/.test(li.querySelector('b')?.textContent || ''));
      expect(twice?.querySelector('.header-why')?.textContent === 'Sent twice, "same-origin" and "no-referrer", so browsers may take either; keep one.', `a weak one sent twice says so (got "${twice?.querySelector('.header-why')?.textContent}")`);
      expect(twice?.querySelector('.header-sent code')?.textContent === 'same-origin, no-referrer', 'with what the site sends');
      expect(!twice?.querySelector('.header-fix') && !twice?.querySelector('.fix-label'), 'and no line to add');
      expect(/^Search for Referrer-Policy in the web server's configuration and in the application's code, and keep one\./.test(twice?.querySelector(':scope > div > .header-tip')?.textContent || ''), 'where to look is in sight');
      expect(twice?.querySelector('.header-more summary')?.textContent === 'What it does', 'what it does is one click away');
      q('dialog.drawer [data-action="close"]').click();
    }

    // A CSP in force that blocks a script on the page is something to do, said with the script
    all('.site-row').find((r) => /docs\.example/.test(r.textContent)).click();
    if (await waitFor(() => q('dialog.drawer[open] h2')?.textContent === 'docs.example', 'the docs site\'s detail to open')) {
      const cspItem = all('dialog.drawer .header-list > li').find((li) => /^CSP/.test(li.querySelector('b')?.textContent || ''));
      expect(/Weak, and it blocks parts of the page/.test(cspItem?.querySelector('.header-level')?.textContent || '') && /\. It blocks a script from cdn\.example, 1 inline style and an image from images\.example on the start page\.$/.test(cspItem?.querySelector('.header-why')?.textContent || ''), `a CSP that blocks a script on the page is listed as something to do, with what it blocks (got "${cspItem?.querySelector('.header-why')?.textContent}")`);
      expect([...(cspItem?.querySelectorAll('.header-scripts code') || [])].some((c) => c.textContent === 'https://cdn.example/widget.js') && /The scripts it blocks:/.test(cspItem?.textContent || ''), 'the blocked script is named by its address');
      expect([...(cspItem?.querySelectorAll('.header-scripts code') || [])].some((c) => c.textContent === 'image https://images.example/a.jpg') && /The styles, images and frames it blocks:/.test(cspItem?.textContent || ''), 'the blocked image is named by kind and address');
      expect(cspItem?.querySelector('[data-action="show-scripts"]')?.textContent === 'a script from cdn.example, 1 inline style and an image from images.example', `the whole phrase opens the lists (got "${cspItem?.querySelector('[data-action="show-scripts"]')?.textContent}")`);
      expect(/the CSP blocks a script from cdn\.example, 1 inline style and an image from images\.example on the page$/.test(q('dialog.drawer .headers-summary')?.textContent || ''), `the summary says what the CSP blocks (got "${q('dialog.drawer .headers-summary')?.textContent}")`);
      q('dialog.drawer [data-action="close"]').click();
    }

    // A CSP set in the page with a meta tag: in place, said so, with why to leave it there
    all('.site-row').find((r) => /www\.example\.com/.test(r.textContent)).click();
    if (await waitFor(() => q('dialog.drawer[open] h2')?.textContent === 'www.example.com', 'the www.example.com detail to open')) {
      expect(/CSP \(in the page\)/.test(q('dialog.drawer .headers-done summary')?.textContent || ''), `a CSP in a meta tag is in place, said to be in the page (got "${q('dialog.drawer .headers-done summary')?.textContent}")`);
      const cspValue = all('dialog.drawer .header-values > li').find((li) => /^CSP/.test(li.querySelector('b')?.textContent || ''));
      expect(cspValue?.querySelector('code')?.textContent === "default-src 'self'" && /^Set in the page with a meta tag/.test(cspValue?.querySelector('.header-why')?.textContent || ''), `the policy as browsers read it, without frame-ancestors, and where it is set (got "${cspValue?.querySelector('code')?.textContent}")`);
      expect([...(cspValue?.querySelectorAll('.header-tip strong') || [])].some((b) => b.textContent === 'leave it there rather than moving it to the server'), 'the tip says to leave it in the page');
      q('dialog.drawer [data-action="close"]').click();
    }

    // A CSP in force that may stop a statistics script sending back: something to look at, with the policy and connect-src
    all('.site-row').find((r) => /example\.org/.test(r.textContent)).click();
    if (await waitFor(() => q('dialog.drawer[open] h2')?.textContent === 'example.org', 'the example.org detail to open')) {
      const cspItem = all('dialog.drawer .header-list > li').find((li) => /^CSP/.test(li.querySelector('b')?.textContent || ''));
      expect(cspItem && /^ · In place$/.test(cspItem.querySelector('.header-level')?.textContent || '') && cspItem.classList.contains('is-weak'), `a CSP that may stop a script sending back is listed as in place, to look at (got "${cspItem?.querySelector('.header-level')?.textContent}")`);
      expect(/The script from stats\.example may not send anything back, such as visits for statistics: if they have stopped, the line below allows it\.$/.test(cspItem?.querySelector('.header-why')?.textContent || ''), `the note says which script and what to do (got "${cspItem?.querySelector('.header-why')?.textContent}")`);
      expect(cspItem?.querySelector('.fix-label')?.textContent === 'Your policy with stats.example added to connect-src:' && /connect-src 'self' https:\/\/stats\.example/.test(cspItem?.querySelector('.header-fix code')?.textContent || ''), 'the line is the policy with the host in connect-src');
      expect(!/CSP/.test(q('dialog.drawer .headers-done summary')?.textContent || ''), 'and it is not in the "In place" line');
      q('dialog.drawer [data-action="close"]').click();
    }

    // A CSP sent only as Report-Only: said so, with what it would block and the policy itself as the line to add
    all('.site-row').find((r) => /builder\.example/.test(r.textContent)).click();
    if (await waitFor(() => q('dialog.drawer[open] h2')?.textContent === 'builder.example', 'the builder site\'s detail to open')) {
      const tried = all('dialog.drawer .header-list > li').find((li) => /^CSP/.test(li.querySelector('b')?.textContent || ''));
      expect(/Sent as Report-Only, not in force/.test(tried?.querySelector('.header-level')?.textContent || ''), `a Report-Only CSP is not called missing (got "${tried?.querySelector('.header-level')?.textContent}")`);
      expect(/a tag manager makes this hard$/.test(tried?.querySelector('.header-level')?.textContent || ''), `a tag manager is said on the status line (got "${tried?.querySelector('.header-level')?.textContent}")`);
      expect(/^It blocks nothing yet\. Enforced, it would block 1 inline script, scripts from www\.googletagmanager\.com and widgets\.example, 1 inline style and a stylesheet from fonts\.googleapis\.com on builder\.example\/app\/\. It loads Google Tag Manager, so a strict policy takes more than a line\.$/.test(tried?.querySelector('.header-why')?.textContent || ''), `it says what the policy would block (got "${tried?.querySelector('.header-why')?.textContent}")`);
      expect(tried?.querySelector('.header-fix code')?.textContent === `Content-Security-Policy: default-src 'self'`, `the line to add is the site's own policy under the enforcing name (got "${tried?.querySelector('.header-fix code')?.textContent}")`);
      expect([...(tried?.querySelectorAll('.header-scripts code') || [])].some((c) => c.textContent === 'https://widgets.example/chat.js'), 'the script it would block is named by its address');
      expect(tried?.querySelector('.fix-label')?.textContent === 'Your policy under the name that enforces it, once the list above is empty:', `the line says what it is (got "${tried?.querySelector('.fix-label')?.textContent}")`);
      // The phrase opens the list of scripts; the tip marks its takeaway in bold
      const scriptsLink = tried?.querySelector('.header-why [data-action="show-scripts"]');
      expect(scriptsLink?.textContent === '1 inline script, scripts from www.googletagmanager.com and widgets.example, 1 inline style and a stylesheet from fonts.googleapis.com' && !tried.querySelector('.header-more').open, 'the scripts named in the note are a button, and the list is closed at first');
      scriptsLink?.click();
      expect(tried?.querySelector('.header-more')?.open === true && document.activeElement === tried?.querySelector('.header-scripts'), 'pressing it opens the list and moves there');
      expect([...(tried?.querySelectorAll('.header-tip strong') || [])].some((b) => b.textContent === "A strict CSP with it needs nonces from the site's own code"), 'the tip marks its takeaway in bold, built as a node, not markup');
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
          // Picking another group says who that shows the site to, as it is picked, and nothing once it is back
          const groupField = q('#f-group');
          const pickGroup = (name) => { groupField.value = name; groupField.dispatchEvent(new Event('change', { bubbles: true })); };
          pickGroup('Own sites');
          expect(q('#f-group-hint')?.textContent === 'Alerts go where the group says. Moving it shows it to user reader.' && q('#f-group-hint').getAttribute('aria-live') === 'polite' && groupField.isConnected, `moving a site says who it shows it to, without redrawing the form (got "${q('#f-group-hint')?.textContent}")`);
          pickGroup('');
          expect(q('#f-group-hint')?.textContent === 'Alerts go where the group says.', 'back in its own group, nothing more is said');
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
          // Its sites are in Own sites, which the reader is limited to, and one in none: a move says who gains or loses them
          const serverGroup = q('#s-group');
          const moveTo = (value) => { serverGroup.value = value; serverGroup.dispatchEvent(new Event('change', { bubbles: true })); return q('#s-group-move')?.textContent; };
          expect(moveTo('Customers') === 'Moving them hides them from user reader.' && moveTo('Own sites') === 'Moving them shows them to user reader.' && moveTo('__keep') === '', `moving a server's sites says who gains or loses them (got "${q('#s-group-move')?.textContent}")`);
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
  // Whoever opens the console meets the owl, once a page, wide awake while a site is down
  const greetings = logs.filter((line) => /keeps watch/.test(line));
  if (greetings.length !== 1 || !/\(O,O\)/.test(greetings[0])) fails.push(`the console greets once, the owl wide awake while a site is down (got ${JSON.stringify(greetings)})`);
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

  // While someone watches, the outages end: the red glow fades and the owl's eyes relax, once
  await send('Emulation.clearDeviceMetricsOverride');
  wait = loaded();
  await send('Page.navigate', { url: `${base}/` });
  await wait;
  await evaluate(async () => {
    for (let t = 0; t < 5000 && !document.querySelector('#app[aria-busy="false"]'); t += 50) await new Promise((r) => setTimeout(r, 50));
  });
  execFileSync('php', ['-r', '(new PDO("sqlite:" . $argv[1]))->exec("UPDATE check_state SET status = \'ok\' WHERE check_name = \'uptime\'");', dbPath]);
  const relief = await evaluate(async () => {
    const refresh = async () => {
      const old = document.querySelector('.watch');
      document.dispatchEvent(new Event('visibilitychange'));
      for (let t = 0; t < 5000 && document.querySelector('.watch') === old; t += 50) await new Promise((r) => setTimeout(r, 50));
      return document.querySelector('.watch').className;
    };
    const before = document.querySelector('.watch').className;
    const after = await refresh();
    const awake = Boolean(document.querySelector('.watch-owl.is-awake'));
    return { before, after, awake, again: await refresh() };
  });
  if (!/is-alert/.test(relief.before) || !/is-relieved/.test(relief.after) || relief.awake) fails.push(`when the outages end while someone watches, the panel and the owl show the relief (got "${relief.before}", then "${relief.after}")`);
  if (/is-relieved/.test(relief.again)) fails.push('the relief shows once, not on every refresh after it');

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

  // The calm owl's eyes follow a mouse over the panel and go back when it leaves, and a pointer that rests near it
  // makes it tilt its head; for Reduce Motion nothing moves
  const gaze = () => evaluate(async () => {
    const frame = () => new Promise((r) => requestAnimationFrame(r));
    const rest = () => new Promise((r) => setTimeout(r, 2100));
    const box = document.querySelector('.watch').getBoundingClientRect();
    const svg = document.querySelector('.watch-owl:not(.is-awake)');
    const owl = svg.getBoundingClientRect();
    const pupils = [...svg.querySelectorAll('.owl-pupil')];
    const point = (x, y) => svg.dispatchEvent(new PointerEvent('pointermove', { bubbles: true, pointerType: 'mouse', clientX: x, clientY: y }));
    const transforms = () => pupils.map((pupil) => pupil.style.transform);
    point(box.left + 10, box.top + 10);
    await frame();
    const looking = transforms();
    await rest();
    const curiousFar = svg.classList.contains('is-curious');
    // At the beak (20, 24.5 of the owl's 40 units) both pupils turn in
    point(owl.left + owl.width / 2, owl.top + owl.height * 24.5 / 40);
    await frame();
    const crossed = pupils.map((pupil) => parseFloat((/translate\((-?[\d.]+)px/.exec(pupil.style.transform) || [])[1]));
    // Down and to the side, near it but away from the beak, they keep together, and resting there it tilts its head
    point(owl.left - owl.width / 2, owl.top + owl.height * 0.9);
    await frame();
    const together = transforms();
    await rest();
    const curious = svg.classList.contains('is-curious');
    point(box.left + 10, box.top + 10);
    await frame();
    const straight = !svg.classList.contains('is-curious');
    svg.dispatchEvent(new PointerEvent('pointerout', { bubbles: true, pointerType: 'mouse', relatedTarget: document.body }));
    await frame();
    return { pupils: pupils.length, looking, crossed, together, curiousFar, curious, straight, after: transforms() };
  });
  const calm = await gaze();
  if (calm.pupils !== 2 || !calm.looking.every((t) => /^translate\(-[\d.]+px, -[\d.]+px\)$/.test(t))) fails.push(`the calm owl looks up and left at a pointer there (got ${JSON.stringify(calm.looking)})`);
  if (!(calm.crossed[0] > 0.8 && calm.crossed[1] < -0.8)) fails.push(`at the beak the owl's eyes cross (got ${JSON.stringify(calm.crossed)})`);
  if (!calm.together[0] || calm.together[0] !== calm.together[1]) fails.push(`away from the beak both eyes look the same way (got ${JSON.stringify(calm.together)})`);
  if (calm.curiousFar) fails.push('a pointer resting far from the owl leaves its head straight');
  if (!calm.curious) fails.push('a pointer resting near the owl makes it tilt its head');
  if (!calm.straight) fails.push('the owl straightens its head when the pointer moves on');
  if (!calm.after.every((t) => t === '')) fails.push(`the owl looks ahead again when the pointer leaves the panel (got ${JSON.stringify(calm.after)})`);
  await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
  const still = await gaze();
  await send('Emulation.setEmulatedMedia', { features: [] });
  if (still.pupils !== 2 || !still.looking.every((t) => t === '') || still.curious) fails.push(`for Reduce Motion the owl's eyes and head stand still (got ${JSON.stringify(still.looking)}, tilted: ${still.curious})`);

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
