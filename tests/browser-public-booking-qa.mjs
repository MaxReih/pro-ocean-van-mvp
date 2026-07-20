import fs from 'node:fs/promises';
import path from 'node:path';

const endpoint = process.argv[2] || 'http://127.0.0.1:9351';
const outputDir = process.argv[3];
const timeoutMs = Number(process.argv[4] || 60000);
if (!outputDir) throw new Error('Ausgabeordner fehlt.');

const targets = await fetch(endpoint + '/json/list').then((response) => response.json());
const target = targets.find((item) => item.type === 'page');
if (!target) throw new Error('Kein Browser-Tab gefunden.');

const socket = new WebSocket(target.webSocketDebuggerUrl);
const pending = new Map();
const contexts = new Map();
const requests = new Map();
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
  } else if (payload.method === 'Runtime.executionContextDestroyed') {
    contexts.delete(payload.params.executionContextId);
  } else if (payload.method === 'Runtime.executionContextsCleared') {
    contexts.clear();
  } else if (payload.method === 'Runtime.exceptionThrown') {
    errors.push(payload.params?.exceptionDetails?.text || 'JavaScript exception');
  } else if (payload.method === 'Network.requestWillBeSent') {
    const request = payload.params.request;
    requests.set(payload.params.requestId, {
      url: request.url,
      method: request.method,
      type: payload.params.type,
      startedAt: Date.now(),
    });
  } else if (payload.method === 'Network.responseReceived') {
    const row = requests.get(payload.params.requestId);
    if (row) row.status = payload.params.response.status;
  } else if (payload.method === 'Network.loadingFinished') {
    const row = requests.get(payload.params.requestId);
    if (row) row.durationMs = Date.now() - row.startedAt;
  } else if (payload.method === 'Network.loadingFailed') {
    const row = requests.get(payload.params.requestId);
    if (row) {
      row.durationMs = Date.now() - row.startedAt;
      row.error = payload.params.errorText;
    }
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

async function waitFor(expression, limit = 30000, contextId) {
  const started = Date.now();
  while (Date.now() - started < limit) {
    try {
      const value = await evaluate(expression, contextId);
      if (value) return value;
    } catch {}
    await new Promise((resolve) => setTimeout(resolve, 200));
  }
  return null;
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

async function findContext(selector, limit = 120000) {
  const started = Date.now();
  while (Date.now() - started < limit) {
    for (const contextId of [...contexts.keys()]) {
      try {
        if (await evaluate(`Boolean(document.querySelector(${JSON.stringify(selector)}))`, contextId)) return contextId;
      } catch {}
    }
    await new Promise((resolve) => setTimeout(resolve, 250));
  }
  return null;
}

await command('Page.enable');
await command('Runtime.enable');
await command('Network.enable');
await command('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });

await navigate('https://maxreih.github.io/pro-ocean-van-mvp/');
const launcherReady = await waitFor('document.querySelectorAll("[data-demo][href]").length === 2');
if (!launcherReady) throw new Error('Demo-Launcher nicht geladen.');
const frontendHref = await evaluate('document.querySelector(\'[data-demo="frontend"]\').href');

const bootStarted = Date.now();
await navigate(frontendHref);
const bookingContext = await findContext('[data-pov-booking]');
const bootDurationMs = Date.now() - bootStarted;
if (!bookingContext) throw new Error('Buchungsoberfläche nicht geladen.');

const searchStarted = Date.now();
await evaluate(`(() => {
  const state = document.querySelector('[data-field="state_code"]');
  const postal = document.querySelector('[data-field="postal_code"]');
  state.value = 'BW';
  state.dispatchEvent(new Event('change', { bubbles: true }));
  postal.value = '73312';
  postal.dispatchEvent(new Event('input', { bubbles: true }));
  postal.dispatchEvent(new Event('change', { bubbles: true }));
  document.querySelector('[data-action="check-route"]').click();
  return true;
})()`, bookingContext);

const searchResult = await waitFor(`(() => {
  const status = document.querySelector('[data-role="route-status"]');
  const suggestions = document.querySelectorAll('.pov-suggestion').length;
  const loading = document.querySelector('[data-role="suggestions"]')?.getAttribute('aria-busy') === 'true';
  const statusText = status?.textContent.trim() || '';
  const suggestionText = document.querySelector('[data-role="suggestions"]')?.textContent.trim() || '';
  if (!statusText && /Gebt euren Ort ein/i.test(suggestionText)) return null;
  if (loading || /berechnet|geprüft wird/i.test(status?.textContent || '')) return null;
  return {
    status: statusText,
    suggestions,
    suggestionText,
  };
})()`, timeoutMs, bookingContext);
const searchDurationMs = Date.now() - searchStarted;

await screenshot(searchResult ? 'public-booking-postal-result.png' : 'public-booking-postal-timeout.png');
const relevantRequests = [...requests.values()]
  .filter((row) => /recommendations|openplz|nominatim|osrm|heigit|playground\.wordpress\.net/i.test(row.url))
  .sort((a, b) => b.durationMs - a.durationMs);

socket.close();
process.stdout.write(JSON.stringify({
  bootDurationMs,
  searchDurationMs,
  searchResult,
  relevantRequests,
  errors,
}, null, 2) + '\n');

if (!searchResult) process.exitCode = 2;
