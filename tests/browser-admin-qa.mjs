import { execFileSync } from 'node:child_process';
import fs from 'node:fs/promises';
import path from 'node:path';

const endpoint = process.argv[2] || 'http://127.0.0.1:9333';
const outputDir = process.argv[3];
const php = process.argv[4];
const ini = process.argv[5];
const wpLoad = process.argv[6];
if (!outputDir || !php || !ini || !wpLoad) throw new Error('QA-Argumente fehlen.');

const phpCode = `require ${JSON.stringify(wpLoad)}; $users=get_users(['role'=>'administrator','number'=>1]); if(!$users){exit(2);} $expires=time()+900; echo wp_json_encode([['name'=>AUTH_COOKIE,'value'=>wp_generate_auth_cookie($users[0]->ID,$expires,'auth'),'expires'=>$expires],['name'=>LOGGED_IN_COOKIE,'value'=>wp_generate_auth_cookie($users[0]->ID,$expires,'logged_in'),'expires'=>$expires]]);`;
const cookieOutput = execFileSync(php, ['-c', ini, '-r', phpCode], { encoding: 'utf8' });
const jsonStart = cookieOutput.indexOf('[');
const cookies = JSON.parse(cookieOutput.slice(jsonStart));

const targets = await fetch(endpoint + '/json/list').then((response) => response.json());
const target = targets.find((item) => item.type === 'page');
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
    payload.error ? handler.reject(new Error(payload.error.message)) : handler.resolve(payload.result);
  } else if (payload.method === 'Runtime.exceptionThrown') {
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
async function waitFor(expression, timeoutMs = 30000) {
  const started = Date.now();
  while (Date.now() - started < timeoutMs) {
    if (await evaluate(expression)) return;
    await new Promise((resolve) => setTimeout(resolve, 200));
  }
  throw new Error('Zeitüberschreitung: ' + expression);
}
async function navigate(url) {
  await command('Page.navigate', { url });
  await waitFor('document.readyState === "complete"');
  await waitFor('Boolean(document.querySelector(".pov-admin"))');
}
async function screenshot(fileName) {
  const result = await command('Page.captureScreenshot', { format: 'png', fromSurface: true, captureBeyondViewport: true });
  await fs.mkdir(outputDir, { recursive: true });
  await fs.writeFile(path.join(outputDir, fileName), Buffer.from(result.data, 'base64'));
}

await command('Page.enable');
await command('Runtime.enable');
await command('Network.enable');
await command('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1100, deviceScaleFactor: 1, mobile: false });
for (const cookie of cookies) {
  await command('Network.setCookie', { ...cookie, url: 'http://proocean.local/', path: '/', httpOnly: true, sameSite: 'Lax' });
}

await navigate('http://proocean.local/wp-admin/admin.php?page=pov-routes');
const routeAudit = await evaluate(`(() => ({
  nav: [...document.querySelectorAll('.pov-admin-header nav a')].map((node) => node.textContent.trim()),
  clusters: document.querySelectorAll('.pov-route-cluster').length,
  routeLegs: document.querySelectorAll('.pov-tour-leg').length,
  datedStops: [...document.querySelectorAll('.pov-tour-stop time')].map((node) => node.textContent.trim()),
  overnights: [...document.querySelectorAll('.pov-tour-overnight strong')].map((node) => node.textContent.trim()),
  durations: [...document.querySelectorAll('.pov-tour-leg strong')].map((node) => node.textContent.trim()),
  maps: document.querySelectorAll('[class*=map], .leaflet-container').length,
  recommended: document.querySelectorAll('.pov-route-cluster.is-recommended').length,
  issueTitle: document.querySelector('.pov-route-issues h2')?.textContent.trim(),
  retryMissing: [...document.querySelectorAll('.pov-route-issues button')].some((node) => node.textContent.includes('Alle erneut prüfen')),
  issueHrefs: [...document.querySelectorAll('.pov-route-issues a[href*=edit_address]')].map((node) => node.href),
}))()`);
await screenshot('final-admin-routes.png');

let addressAudit = {};
if (routeAudit.issueHrefs.length) {
  await navigate(routeAudit.issueHrefs[0]);
  addressAudit = await evaluate(`(() => ({
    editorOpen: document.querySelector('#pov-address-editor')?.open,
    fields: ['street','house_number','postal_code','city','state_code'].every((name) => Boolean(document.querySelector('#pov-address-editor [name="' + name + '"]'))),
    action: document.querySelector('#pov-address-editor button')?.textContent.trim(),
    status: document.querySelector('.pov-address-status')?.textContent.trim(),
  }))()`);
  await screenshot('final-admin-address-edit.png');
}

await navigate('http://proocean.local/wp-admin/admin.php?page=pov-statistics&period=week');
const statisticsAudit = await evaluate(`(() => ({
  periods: [...document.querySelectorAll('.pov-period-switch a')].map((node) => node.textContent.trim()),
  rows: document.querySelectorAll('.pov-compact-table tbody tr').length,
  bars: document.querySelectorAll('.pov-stat-bar').length,
  totals: [...document.querySelectorAll('.pov-stat-overview strong')].map((node) => node.textContent.trim()),
}))()`);
await screenshot('final-admin-statistics.png');

await navigate('http://proocean.local/wp-admin/admin.php?page=pov-calendar&pov_month=2026-07');
const calendarAudit = await evaluate(`(() => {
  const panel = document.querySelector('[data-pov-calendar-export]');
  const select = document.querySelector('[data-pov-calendar-export-select]');
  const google = document.querySelector('[data-pov-calendar-export-google]');
  const ics = document.querySelector('[data-pov-calendar-export-ics]');
  const rect = panel?.getBoundingClientRect();
  return {
    visible: Boolean(panel && rect && rect.width > 0 && rect.height > 0),
    heading: panel?.querySelector('h2')?.textContent.trim(),
    options: select?.options.length || 0,
    selectedDate: select?.selectedOptions[0]?.dataset.date,
    googleHost: google ? new URL(google.href).host : '',
    icsAction: ics ? new URL(ics.href).searchParams.get('action') : '',
    manageHeading: [...document.querySelectorAll('.pov-calendar-sidebar h2')].map((node) => node.textContent.trim()),
  };
})()`);
await screenshot('final-admin-calendar-export.png');
calendarAudit.daySelection = await evaluate(`(() => {
  const day = [...document.querySelectorAll('.pov-admin-calendar-day.is-confirmed')].at(-1);
  const select = document.querySelector('[data-pov-calendar-export-select]');
  if (!day || !select) return false;
  day.click();
  return select.selectedOptions[0]?.dataset.date === day.dataset.date;
})()`);

await navigate('http://proocean.local/wp-admin/admin.php?page=pov-requests');
const inboxAudit = await evaluate(`(() => ({
  rows: document.querySelectorAll('.pov-operations-table tbody tr').length,
  nav: [...document.querySelectorAll('.pov-admin-header nav a')].map((node) => node.textContent.trim()),
}))()`);
await screenshot('final-admin-requests.png');

await navigate('http://proocean.local/wp-admin/admin.php?page=pov-requests&request_id=5');
const detailAudit = await evaluate(`(() => ({
  suggestions: document.querySelectorAll('.pov-admin-suggestion').length,
  responses: [...document.querySelectorAll('[name=response_type]')].map((node) => node.value),
  maps: document.querySelectorAll('[class*=map], .leaflet-container').length,
  location: document.querySelector('.pov-request-hero p')?.textContent.trim(),
  message: document.querySelector('[name=message]')?.value,
}))()`);
await evaluate(`(() => { const choice=document.querySelector('[name=response_type][value=question]'); if(choice){choice.click();} return true; })()`);
detailAudit.questionTemplate = await evaluate('document.querySelector("[name=message]")?.value');
detailAudit.dateHiddenOnQuestion = await evaluate('document.querySelector("[data-pov-response-date]")?.hidden && getComputedStyle(document.querySelector("[data-pov-response-date]")).display === "none"');
await screenshot('final-admin-request-detail.png');

await navigate('http://proocean.local/wp-admin/admin.php?page=pov-settings');
await evaluate(`(() => {
  const routing = [...document.querySelectorAll('.pov-settings-group')]
    .find((node) => node.querySelector('summary')?.textContent.trim() === 'Routing');
  if (routing) routing.open = true;
  return Boolean(routing);
})()`);
const settingsAudit = await evaluate(`(() => ({
  personnelFields: ['pov_personnel_hourly_rate','pov_personnel_count','pov_default_visit_hours'].every((name) => Boolean(document.querySelector('[name="' + name + '"]'))),
  responseTemplates: document.querySelectorAll('[name^=pov_response_][name$=_template]').length,
  geoTest: [...document.querySelectorAll('a.button')].some((node) => node.textContent.includes('Verbindung testen')),
  providerLabels: [...document.querySelectorAll('[data-pov-provider-select]')].map((node) => node.closest('label')?.childNodes[0]?.textContent.trim()),
  hiddenProviderFields: [...document.querySelectorAll('[data-pov-provider-field][hidden]')].map((node) => node.dataset.povProviderField),
  geoResults: [...document.querySelectorAll('.pov-geo-result article')].map((node) => node.textContent.trim()),
}))()`);
await screenshot('final-admin-settings-geo.png');

await command('Network.clearBrowserCookies');
await command('Browser.close').catch(() => {});
socket.close();
process.stdout.write(JSON.stringify({ routeAudit, addressAudit, statisticsAudit, calendarAudit, inboxAudit, detailAudit, settingsAudit, errors }, null, 2) + '\n');
