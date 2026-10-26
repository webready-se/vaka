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
import { tmpdir } from 'node:os';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const PHP_PORT = 8789;
const CDP_PORT = 9334;
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
  const env = { ...process.env, VAKA_ENV_FILE: '/dev/null', DB_PATH: dbPath, USERS_FILE: usersFile, TIMEZONE: 'Europe/Stockholm' };
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
      else if (entry.level === 'error' && !/favicon/.test(entry.url || '')) errors.push(`Log error: ${entry.text} ${entry.url || ''}`);
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
    expect(/favicon-alert\.svg$/.test(q('[data-favicon]').getAttribute('href')), 'the favicon turns to the alarmed owl');
    expect(/Watching 14 sites · checked/.test(q('[data-region="run-state"]').textContent), 'top bar says how many sites Vaka watches and when it checked');
    // Needs your attention: one compact row each, outages first, the quiet ones folded away last
    const rows = all('.attention > .incidents > .incident');
    const summary = (row) => row?.querySelector('summary')?.textContent || '';
    expect(rows.length === 6, `six rows need attention (got ${rows.length})`);
    expect(/shop\.example\s*is down/.test(summary(rows[0])), 'an outage comes first');
    expect(/The site answered 503/.test(summary(rows[0])), 'the row says why in one line');
    expect(/The site itself answers with an error/.test(rows[0]?.textContent || ''), 'the advice is inside the row');
    expect(/blog\.example\s*failed a check/.test(summary(rows[2])), 'a failing check comes after the outages');
    expect(/cafe\.example\s*2 private files are public/.test(summary(rows[3])) && /\.env, \.git\//.test(summary(rows[3])), 'public files come after them, and say which');
    expect(/the environment file/.test(rows[3]?.textContent || '') && /change every password and key/.test(rows[3]?.textContent || ''), 'the row says what each file holds and what to do');
    expect(/booking\.example\s*certificate expires in 6 days/.test(summary(rows[4])), 'an expiring certificate comes after what is broken now');
    expect(/1 site is down, snoozed/.test(summary(rows[5])) && /deals2\.example/.test(rows[5].textContent), 'snoozed outages are folded into one row at the end');
    expect(/2\s*Exposed files/.test(q('.watch-stats')?.textContent || ''), 'the watch counts the exposed files');
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
      expect(q('dialog.drawer .chart'), 'a response time chart');
      expect(all('dialog.drawer .days i').length === 30, 'thirty days in the detail');
      expect(all('dialog.drawer .timeline li').length >= 2, 'the history lists what happened');
      expect(/Grade C: 4 of 6 in place, missing HSTS and CSP/.test(q('dialog.drawer .headers-summary')?.textContent || ''), 'the detail says the headers grade in words');
      expect(all('dialog.drawer .header-list li').length === 6, 'the detail lists the six protections');
      expect(all('dialog.drawer .header-list code').some((c) => /^Content-Security-Policy:/.test(c.textContent)), 'a missing protection comes with the line that adds it');
      expect(location.hash === '#site-1', 'the detail has its own address');
      q('dialog.drawer [data-action="close"]').click();
      expect(!q('dialog.drawer[open]') && location.hash === '', 'closing the detail closes it and its address');
    }

    // "/" jumps to the filter
    document.activeElement.blur();
    document.dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true }));
    expect(document.activeElement === q('[data-filter]'), 'pressing / focuses the filter');
    document.activeElement.blur();

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
    return { overflow: document.documentElement.scrollWidth - 390, csp: window.__csp };
  });
  if (phone.overflow > 0) fails.push(`no sideways scrolling on a phone (${phone.overflow}px too wide)`);
  if (phone.csp.length) fails.push(`no CSP violations on a phone: ${phone.csp.join(', ')}`);
  await screenshot('phone');
  await evaluate(() => document.querySelector('.site-row').click());
  await sleep(600);
  await screenshot('phone-detail');

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
