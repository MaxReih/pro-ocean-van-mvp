import fs from 'node:fs/promises';
import path from 'node:path';

const endpoint = process.argv[2] || 'http://127.0.0.1:9351';
const outputDir = process.argv[3];
if (!outputDir) throw new Error('Ausgabeordner fehlt.');

const targets = await fetch(endpoint + '/json/list').then((response) => response.json());
const target = targets.find((item) => item.type === 'page');
if (!target) throw new Error('Kein Browser-Tab gefunden.');

const socket = new WebSocket(target.webSocketDebuggerUrl);
const pending = new Map();
const contexts = new Map();
const errors = [];
let sequence = 0;

await new Promise((resolve, reject) => {
  socket.addEventListener('open', resolve, { once: true });
  socket.addEventListener('error', reject, { once: true });
});

socket.addEventListener('message', (event) => {
  const payload = JSON.parse(String(event.data));
  if (payload.id && pending.has(payload.id)) {
    const handler = pending.get(payload.id);
    pending.delete(payload.id);
    payload.error ? handler.reject(new Error(payload.error.message)) : handler.resolve(payload.result);
    return;
  }
  if (payload.method === 'Runtime.executionContextCreated') {
    contexts.set(payload.params.context.id, payload.params.context);
  }
  if (payload.method === 'Runtime.executionContextDestroyed') {
    contexts.delete(payload.params.executionContextId);
  }
  if (payload.method === 'Runtime.executionContextsCleared') {
    contexts.clear();
  }
  if (payload.method === 'Runtime.exceptionThrown') {
    errors.push(payload.params?.exceptionDetails?.exception?.description || payload.params?.exceptionDetails?.text || 'JavaScript exception');
  }
});

function command(method, params = {}) {
  const id = ++sequence;
  socket.send(JSON.stringify({ id, method, params }));
  return new Promise((resolve, reject) => pending.set(id, { resolve, reject }));
}

async function evaluate(expression, contextId) {
  const params = { expression, awaitPromise: true, returnByValue: true };
  if (contextId) params.contextId = contextId;
  const result = await command('Runtime.evaluate', params);
  if (result.exceptionDetails) throw new Error(result.exceptionDetails.text || 'Auswertung fehlgeschlagen');
  return result.result?.value;
}

async function waitFor(expression, timeoutMs = 30000) {
  const started = Date.now();
  while (Date.now() - started < timeoutMs) {
    try {
      if (await evaluate(expression)) return;
    } catch {}
    await new Promise((resolve) => setTimeout(resolve, 250));
  }
  throw new Error('Zeitüberschreitung: ' + expression);
}

async function navigate(url) {
  await command('Page.navigate', { url });
  await waitFor('document.readyState === "complete"', 45000);
}

async function screenshot(fileName) {
  const result = await command('Page.captureScreenshot', { format: 'png', fromSurface: true, captureBeyondViewport: false });
  await fs.mkdir(outputDir, { recursive: true });
  await fs.writeFile(path.join(outputDir, fileName), Buffer.from(result.data, 'base64'));
}

async function waitForSurface(selector, timeoutMs = 120000) {
  const started = Date.now();
  while (Date.now() - started < timeoutMs) {
    for (const contextId of [...contexts.keys()]) {
      try {
        const result = await evaluate(`(() => ({
          found: Boolean(document.querySelector(${JSON.stringify(selector)})),
          url: location.href,
          title: document.title,
          heading: document.querySelector('h1, .pov-admin-title h1')?.textContent.trim() || '',
          requests: document.querySelectorAll('.pov-operations-table tbody tr').length,
          pageOverflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
          portalLinks: document.querySelectorAll('a[href*="/van-operations/"]').length,
          setupWarnings: [...document.querySelectorAll('.notice-warning')].map((node) => node.textContent.trim()),
        }))()`, contextId);
        if (result?.found) return result;
      } catch {}
    }
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  return null;
}

await command('Page.enable');
await command('Runtime.enable');
await command('Network.enable');
await command('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });

await navigate('https://maxreih.github.io/pro-ocean-van-mvp/');
await waitFor('document.querySelectorAll("[data-demo][href]").length === 2');
const launcherAudit = await evaluate(`(() => ({
  title: document.title,
  heading: document.querySelector('h1')?.textContent.trim(),
  frontendHref: document.querySelector('[data-demo="frontend"]')?.href,
  backendHref: document.querySelector('[data-demo="backend"]')?.href,
  status: document.querySelector('[role="status"]')?.textContent.trim(),
}))()`);
await screenshot('public-mvp-launcher.png');

await navigate(launcherAudit.frontendHref);
const frontendAudit = await waitForSurface('[data-pov-booking]');
await screenshot('public-mvp-frontend.png');

await navigate('about:blank');
await navigate(launcherAudit.backendHref);
const backendAudit = await waitForSurface('.pov-admin');
await screenshot('public-mvp-backend.png');

await command('Network.clearBrowserCache');
await command('Network.clearBrowserCookies');
socket.close();

if (!frontendAudit || !backendAudit || frontendAudit.portalLinks > 0 || backendAudit.pageOverflow || backendAudit.setupWarnings.length > 0) {
  throw new Error('Die öffentliche WordPress-Demo wurde nicht vollständig geladen.');
}

process.stdout.write(JSON.stringify({ launcherAudit, frontendAudit, backendAudit, errors }, null, 2) + '\n');
