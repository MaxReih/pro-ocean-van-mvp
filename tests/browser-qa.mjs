import fs from 'node:fs/promises';
import path from 'node:path';

const endpoint = process.argv[2] || 'http://127.0.0.1:9333';
const outputDir = process.argv[3] || path.resolve('../../../../../artifacts/qa');
const targetUrl = process.argv[4] || 'http://proocean.local/planer/';
let targets = [];
const endpointDeadline = Date.now() + 20000;
while (!targets.length && Date.now() < endpointDeadline) {
  try {
    targets = await fetch(endpoint + '/json/list').then((response) => response.json());
  } catch (error) {
    await new Promise((resolve) => setTimeout(resolve, 250));
  }
}
const target = targets.find((item) => item.type === 'page');
if (!target?.webSocketDebuggerUrl) throw new Error('Kein Edge-Ziel gefunden.');

const socket = new WebSocket(target.webSocketDebuggerUrl);
const pending = new Map();
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
    if (payload.error) handler.reject(new Error(payload.error.message));
    else handler.resolve(payload.result);
    return;
  }
  if (payload.method === 'Runtime.exceptionThrown') {
    errors.push(payload.params?.exceptionDetails?.text || 'JavaScript exception');
  }
});

function command(method, params = {}) {
  const id = ++sequence;
  socket.send(JSON.stringify({ id, method, params }));
  return new Promise((resolve, reject) => pending.set(id, { resolve, reject }));
}

async function evaluate(expression) {
  const result = await command('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
  if (result.exceptionDetails) throw new Error(result.exceptionDetails.text || 'Auswertung fehlgeschlagen');
  return result.result?.value;
}

async function waitFor(expression, timeoutMs = 45000) {
  const started = Date.now();
  while (Date.now() - started < timeoutMs) {
    if (await evaluate(expression)) return;
    await new Promise((resolve) => setTimeout(resolve, 250));
  }
  throw new Error('Zeitüberschreitung: ' + expression);
}

async function navigate(width, height, mobile = false) {
  await command('Emulation.setDeviceMetricsOverride', {
    width,
    height,
    deviceScaleFactor: 1,
    mobile,
    screenWidth: width,
    screenHeight: height,
  });
  await command('Page.navigate', { url: targetUrl });
  await waitFor('document.readyState === "complete"');
  await waitFor('Boolean(document.querySelector("[data-pov-booking]"))');
}

async function requestSuggestions() {
  await evaluate(`(() => {
    const state = document.querySelector('[data-field="state_code"]');
    const postal = document.querySelector('[data-field="postal_code"]');
    state.value = 'BW';
    postal.value = '73312';
    state.dispatchEvent(new Event('change', { bubbles: true }));
    postal.dispatchEvent(new Event('input', { bubbles: true }));
    document.querySelector('[data-action="check-route"]').click();
    return true;
  })()`);
  await waitFor('document.querySelectorAll(".pov-suggestion").length === 2');
}

async function screenshot(fileName) {
  const result = await command('Page.captureScreenshot', {
    format: 'png',
    fromSurface: true,
    captureBeyondViewport: true,
  });
  await fs.mkdir(outputDir, { recursive: true });
  await fs.writeFile(path.join(outputDir, fileName), Buffer.from(result.data, 'base64'));
}

await command('Page.enable');
await command('Runtime.enable');

await navigate(1440, 1100, false);
await requestSuggestions();
const desktopAudit = await evaluate(`(() => ({
  headline: document.querySelector('.pov-booking h1')?.textContent.trim(),
  suggestions: [...document.querySelectorAll('.pov-suggestion h3')].map((node) => node.textContent.trim()),
  suggestionDates: [...document.querySelectorAll('.pov-suggestion')].map((node) => node.dataset.date),
  comments: document.querySelectorAll('.pov-suggestion [data-reason], .pov-suggestion .pov-reason').length,
  metrics: document.querySelectorAll('.pov-suggestion-metrics, .pov-metric').length,
  alternatives: [...document.querySelectorAll('.pov-route-alternatives button')].map((node) => node.textContent.trim()),
  pastDays: document.querySelectorAll('.pov-day[data-state="past"]').length,
  pastDaysDisabled: [...document.querySelectorAll('.pov-day[data-state="past"]')].every((node) => node.disabled),
  pastDayBackground: getComputedStyle(document.querySelector('.pov-day[data-state="past"]')).backgroundColor,
}))()`);
await screenshot('final-booking-73312.png');

await evaluate('document.querySelector("[data-action=toggle-calendar]").click()');
await waitFor('document.querySelector("[data-role=calendar-panel]").hidden === false');
await screenshot('final-booking-calendar-past.png');

await evaluate('document.querySelector(".pov-suggestion button").click()');
await waitFor('document.querySelector("[data-role=form]") && !document.querySelector("[data-role=form]").hidden');
const formAudit = await evaluate(`(() => ({
  steps: document.querySelectorAll('[data-form-step]').length,
  postal: document.querySelector('[name="postal_code"]')?.value,
  state: document.querySelector('[name="state_code"]')?.value,
  postalEditable: !document.querySelector('[name="postal_code"]')?.readOnly,
  stateEditable: !document.querySelector('[name="state_code"]')?.disabled,
}))()`);
await screenshot('final-booking-form.png');

await navigate(390, 844, true);
await new Promise((resolve) => setTimeout(resolve, 2200));
await requestSuggestions();
const mobileAudit = await evaluate(`(() => ({
  width: document.documentElement.clientWidth,
  suggestions: document.querySelectorAll('.pov-suggestion').length,
  overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
}))()`);
await screenshot('final-booking-mobile.png');

await command('Browser.close').catch(() => {});
socket.close();
process.stdout.write(JSON.stringify({ desktopAudit, formAudit, mobileAudit, errors }, null, 2) + '\n');
