#!/usr/bin/env node
// README media: seed the demo database (fake sites only), serve it and drive
// headless Chrome over the DevTools protocol to capture the screenshots and the
// social preview card in docs/screenshots/. Every image is cut to a dashboard
// element, so layout changes do not shift the crops.
//
// Usage: node scripts/readme-media.mjs          (CHROME=/path/to/chrome to override)
// Needs: Node 22+ (built-in WebSocket), PHP with pdo_sqlite, Chrome or Chromium.
// No npm dependencies: this is a dev tool, Vaka itself stays dependency-free.

import { spawn, execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const out = join(root, 'docs/screenshots');
// A port the system hands out, never a fixed one: another project's test server on the same machine may hold
// a fixed port, and then a check that something answers there passes against the wrong server
const freePort = () => new Promise((resolvePort, reject) => {
  const srv = createServer();
  srv.once('error', reject);
  srv.listen(0, '127.0.0.1', () => { const { port } = srv.address(); srv.close(() => resolvePort(port)); });
});
const PHP_PORT = await freePort();
const CDP_PORT = await freePort();
const tmp = mkdtempSync(join(tmpdir(), 'vaka-media-'));
const children = [];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

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

async function waitForHttp(url, tries = 100) {
  for (let i = 0; i < tries; i++) {
    try {
      const r = await fetch(url);
      if (r.status < 500) return r;
    } catch {}
    await sleep(100);
  }
  throw new Error(`Timed out waiting for ${url}`);
}

async function main() {
  mkdirSync(out, { recursive: true });
  const base = `http://127.0.0.1:${PHP_PORT}`;

  // 1. Demo data and a user
  const dbPath = join(tmp, 'demo.sqlite');
  const usersFile = join(tmp, 'users.json');
  execFileSync('php', [join(root, 'scripts/seed-demo.php'), dbPath], { stdio: 'ignore' });
  execFileSync('php', ['-r', 'file_put_contents($argv[1], json_encode(["demo" => ["password" => password_hash("demo password", PASSWORD_BCRYPT, ["cost" => 4]), "groups" => []]]));', usersFile]);
  const env = { ...process.env, VAKA_ENV_FILE: '/dev/null', DB_PATH: dbPath, USERS_FILE: usersFile, TIMEZONE: 'Europe/Stockholm' };
  children.push(spawn('php', ['-S', `127.0.0.1:${PHP_PORT}`, '-t', join(root, 'public')], { env, stdio: 'ignore' }));
  await waitForHttp(`${base}/?health`);

  // 2. Headless Chrome with remote debugging
  children.push(spawn(findChrome(), [
    '--headless=new', `--remote-debugging-port=${CDP_PORT}`, `--user-data-dir=${join(tmp, 'chrome')}`,
    '--no-first-run', '--no-default-browser-check', '--disable-gpu', '--hide-scrollbars', 'about:blank',
  ], { stdio: 'ignore' }));
  await waitForHttp(`http://127.0.0.1:${CDP_PORT}/json/version`, 300);
  const page = (await (await fetch(`http://127.0.0.1:${CDP_PORT}/json/list`)).json()).find((t) => t.type === 'page');
  const ws = new WebSocket(page.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
  let id = 0;
  const pending = new Map();
  const listeners = [];
  ws.onmessage = (msg) => {
    const data = JSON.parse(msg.data);
    if (data.id && pending.has(data.id)) {
      const { resolve, reject } = pending.get(data.id);
      pending.delete(data.id);
      data.error ? reject(new Error(data.error.message)) : resolve(data.result);
      return;
    }
    listeners.forEach((l) => l(data));
  };
  const send = (method, params = {}) => new Promise((resolve, reject) => {
    const msgId = ++id;
    pending.set(msgId, { resolve, reject });
    ws.send(JSON.stringify({ id: msgId, method, params }));
  });
  const loaded = () => new Promise((resolve) => {
    const l = (d) => { if (d.method === 'Page.loadEventFired') { listeners.splice(listeners.indexOf(l), 1); resolve(); } };
    listeners.push(l);
  });
  const run = async (expression) => {
    const r = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
    return r.result.value;
  };
  const navigate = async (url) => { const w = loaded(); await send('Page.navigate', { url }); await w; };
  const viewport = (width, height, mobile = false, deviceScaleFactor = 2) => send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor, mobile });
  const until = (cond) => run(`new Promise(async (res, rej) => { for (let t = 0; t < 100; t++) { if (${cond}) return res(true); await new Promise((r) => setTimeout(r, 100)); } rej(new Error('timed out: ' + ${JSON.stringify(cond)})); })`);
  // Page coordinates of elements from the top of the first to the bottom of the last
  const box = (first, last = first, pad = 0) => run(`(() => {
    const a = document.querySelector(${JSON.stringify(first)}).getBoundingClientRect();
    const b = document.querySelector(${JSON.stringify(last)}).getBoundingClientRect();
    return { x: Math.max(0, a.left + scrollX - ${pad}), y: Math.max(0, a.top + scrollY - ${pad}), width: Math.max(a.right, b.right) - a.left + 2 * ${pad}, height: b.bottom - a.top + 2 * ${pad} };
  })()`);
  const shoot = async (name, clip) => {
    const img = await send('Page.captureScreenshot', { format: 'png', clip: { ...clip, scale: 1 }, captureBeyondViewport: true });
    writeFileSync(join(out, name), Buffer.from(img.data, 'base64'));
    console.log(`  ${name}`);
  };
  const theme = (t) => run(`document.documentElement.setAttribute('data-theme', '${t}')`);
  const ready = () => until(`document.querySelector('#app[aria-busy="false"]')`);

  await send('Page.enable');
  await send('Runtime.enable');
  await viewport(1280, 1000);

  // 3. Log in through the form
  await navigate(`${base}/?login`);
  const w = loaded();
  await run(`document.getElementById('username').value = 'demo'; document.getElementById('password').value = 'demo password'; document.querySelector('form').submit()`);
  await w;
  await ready();
  console.log('Capturing to docs/screenshots/');

  // Hero: the top bar, the night panel, what needs you and the first group, in both themes
  const hero = await box('.topbar', '.group');
  hero.height += 24;
  await theme('dark');
  await sleep(400);
  await shoot('dashboard.png', { ...hero, x: 0, width: 1280 });
  await theme('light');
  await sleep(400);
  await shoot('dashboard-light.png', { ...hero, x: 0, width: 1280 });
  await theme('dark');

  // A site's detail: uptime, response times and what Vaka sees, ending under its tiles (a window tall enough to show them)
  await viewport(1280, 1300);
  await run(`document.querySelector('.site-row').click()`);
  await until(`document.querySelector('.drawer[open] .drawer-inner')`);
  await sleep(600);
  const detail = await box('.drawer', '.drawer .tiles');
  await shoot('detail.png', { ...detail, height: detail.height + 24 });

  // A phone: the same answer first
  await viewport(390, 844, true);
  await navigate(`${base}/`);
  await ready();
  await theme('dark');
  await sleep(400);
  const phone = await box('.topbar', '.attention');
  await shoot('phone.png', { ...phone, x: 0, width: 390, height: phone.height + 16 });

  // 4. Social preview (1280x640) for shared links: set it in the repository settings, GitHub has no API for it
  const card = join(tmp, 'social.html');
  writeFileSync(card, `<!doctype html><html><head><meta charset="utf-8"><style>
    * { margin: 0; box-sizing: border-box; }
    body { width: 1280px; height: 640px; overflow: hidden; background: #0f172a; color: #f1f5f9;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif; display: flex; }
    .text { width: 560px; padding: 84px 0 0 80px; }
    .brand { display: flex; align-items: center; gap: 18px; margin-bottom: 44px; }
    .icon { width: 64px; height: 64px; }
    .name { font-size: 46px; font-weight: 800; letter-spacing: -0.02em; }
    h1 { font-size: 50px; line-height: 1.12; letter-spacing: -0.03em; font-weight: 800; }
    h1 span { color: #22d3ee; }
    p { margin-top: 26px; font-size: 24px; line-height: 1.45; color: #94a3b8; }
    .shot { flex: 1; position: relative; }
    .shot img { position: absolute; left: 0; top: 70px; width: 980px; border-radius: 16px;
      border: 1px solid #334155; box-shadow: 0 30px 80px rgba(0,0,0,.55); }
  </style></head><body>
    <div class="text">
      <div class="brand"><img class="icon" src="${pathToFileURL(join(root, 'public/favicon.svg'))}" alt=""><div class="name">Vaka</div></div>
      <h1>Keeps watch<br><span>while you sleep.</span></h1>
      <p>Uptime, certificates, domains and exposed files for every site. One alert per server. PHP and SQLite, MIT licensed.</p>
    </div>
    <div class="shot"><img src="${pathToFileURL(join(out, 'dashboard.png'))}"></div>
  </body></html>`);
  await viewport(1280, 640, false, 1);
  await navigate(pathToFileURL(card).href);
  await sleep(300);
  await shoot('social-preview.png', { x: 0, y: 0, width: 1280, height: 640 });

  ws.close();
  console.log('Done.');
}

main()
  .catch((e) => { console.error('readme-media failed:', e.message); process.exitCode = 1; })
  .finally(() => {
    children.forEach((c) => { try { c.kill('SIGKILL'); } catch {} });
    try { rmSync(tmp, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 }); } catch {}
  });
