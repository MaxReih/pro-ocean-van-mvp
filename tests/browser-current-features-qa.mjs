import { execFileSync } from 'node:child_process';
import fs from 'node:fs/promises';
import path from 'node:path';

const endpoint = process.argv[2] || 'http://127.0.0.1:9351';
const baseUrl = (process.argv[3] || 'http://127.0.0.1:8090').replace(/\/+$/, '');
const outputDir = process.argv[4];
const php = process.argv[5];
const ini = process.argv[6];
const wpLoad = process.argv[7];
const mysqliPort = process.argv[8] || '';
if (!outputDir || !php || !ini || !wpLoad) throw new Error('QA-Argumente fehlen.');

const targets = await fetch(endpoint + '/json/list').then((response) => response.json());
const target = targets.find((item) => item.type === 'page');
if (!target) throw new Error('Kein Browser-Tab gefunden.');

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
    errors.push(payload.params?.exceptionDetails?.exception?.description || payload.params?.exceptionDetails?.text || 'JavaScript exception');
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
    try {
      const value = await evaluate(expression);
      if (value) return value;
    } catch {}
    await new Promise((resolve) => setTimeout(resolve, 200));
  }
  throw new Error('Zeitüberschreitung: ' + expression);
}

async function navigate(url, readySelector) {
  await command('Page.navigate', { url });
  await waitFor('document.readyState === "complete"');
  if (readySelector) {
    await waitFor(`Boolean(document.querySelector(${JSON.stringify(readySelector)}))`);
  }
}

async function screenshot(fileName) {
  const result = await command('Page.captureScreenshot', { format: 'png', fromSurface: true, captureBeyondViewport: true });
  await fs.mkdir(outputDir, { recursive: true });
  await fs.writeFile(path.join(outputDir, fileName), Buffer.from(result.data, 'base64'));
}

function expect(condition, message) {
  if (!condition) throw new Error(message);
}

await command('Page.enable');
await command('Runtime.enable');
await command('Network.enable');
await command('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1100, deviceScaleFactor: 1, mobile: false });

await navigate(baseUrl + '/planer/', '[data-pov-booking]');
await waitFor('document.querySelector("[data-role=calendar]")?.getAttribute("aria-busy") === "false"');
const initialAudit = await evaluate(`(() => {
  const panel = document.querySelector('[data-role="calendar-panel"]');
  const rect = panel?.getBoundingClientRect();
  const walkIn = document.querySelector('.pov-day[data-state="walk_in"]');
  return {
    calendarVisible: Boolean(panel && !panel.hidden && rect && rect.height > 0),
    calendarToggle: Boolean(document.querySelector('[data-action="toggle-calendar"]')),
    walkInVisible: Boolean(walkIn),
    walkInLabel: walkIn?.textContent.trim() || '',
    schoolLabel: document.querySelector('select[name="institution_type"] option[value="Schule"]')?.textContent.trim() || '',
    eventLabel: document.querySelector('select[name="institution_type"] option[value="Veranstaltung"]')?.textContent.trim() || '',
  };
})()`);
expect(initialAudit.calendarVisible, 'Frontend-Kalender ist nicht direkt sichtbar.');
expect(!initialAudit.calendarToggle, 'Frontend-Kalender kann noch geschlossen werden.');
expect(initialAudit.walkInVisible, 'Walk-in-Termin ist im Frontend-Kalender nicht sichtbar.');
expect(initialAudit.schoolLabel.includes('(kostenlos)'), 'Kostenhinweis für Schulen fehlt.');
expect(initialAudit.eventLabel.includes('(ggf. kostenpflichtig)'), 'Kostenhinweis für Veranstaltungen fehlt.');

await evaluate('document.querySelector(\'.pov-day[data-state="walk_in"]\').click()');
await waitFor('document.querySelector("[data-role=walk-in-dialog]")?.open === true');
const walkInAudit = await evaluate(`(() => ({
  title: document.querySelector('[data-role="walk-in-title"]')?.textContent.trim() || '',
  description: document.querySelector('[data-role="walk-in-description"]')?.textContent.trim() || '',
  location: document.querySelector('[data-role="walk-in-location"]')?.textContent.trim() || '',
}))()`);
expect(Boolean(walkInAudit.title && walkInAudit.description && walkInAudit.location), 'Walk-in-Detaildialog ist unvollständig.');
await evaluate('document.querySelector("[data-action=close-walk-in]").click()');

const routeStarted = Date.now();
await evaluate(`(() => {
  const state = document.querySelector('[data-field="state_code"]');
  const postal = document.querySelector('[data-field="postal_code"]');
  state.value = 'BW';
  state.dispatchEvent(new Event('change', { bubbles: true }));
  postal.value = '73312';
  postal.dispatchEvent(new Event('input', { bubbles: true }));
  document.querySelector('[data-action="check-route"]').click();
  return true;
})()`);
await waitFor(`(() => {
  const busy = document.querySelector('[data-role="suggestions"]')?.getAttribute('aria-busy') === 'true';
  return !busy && document.querySelectorAll('.pov-suggestion').length === 2;
})()`, 60000);
const routeAudit = await evaluate(`(() => ({
  durationMs: ${Date.now()} - ${routeStarted},
  suggestions: document.querySelectorAll('.pov-suggestion').length,
  greenDates: document.querySelectorAll('.pov-day[data-state="available"]').length,
  geoUnavailableDates: document.querySelectorAll('.pov-day[data-state="geo_unavailable"]').length,
  routeStatus: document.querySelector('[data-role="route-status"]')?.textContent.trim() || '',
}))()`);
expect(routeAudit.suggestions === 2, 'Es werden nicht genau zwei Routentermine angezeigt.');
expect(routeAudit.geoUnavailableDates > 0, 'Nicht passende Kalendertage werden nach der PLZ-Prüfung nicht ausgegraut.');
expect(routeAudit.greenDates === 0, 'Zu kurzfristige oder geographisch unpassende Tage bleiben im aktuellen Monat grün.');
await screenshot('current-features-frontend.png');

const phpCode = `define('WP_HOME', ${JSON.stringify(baseUrl)}); define('WP_SITEURL', ${JSON.stringify(baseUrl)}); require ${JSON.stringify(wpLoad)}; $users=get_users(['role'=>'administrator','number'=>1]); if(!$users){exit(2);} $expires=time()+900; global $wpdb; $request_id=(int)$wpdb->get_var("SELECT request_id FROM {$wpdb->prefix}pov_appointments WHERE status='confirmed' AND request_id IS NOT NULL ORDER BY id DESC LIMIT 1"); echo wp_json_encode(['cookies'=>[['name'=>AUTH_COOKIE,'value'=>wp_generate_auth_cookie($users[0]->ID,$expires,'auth'),'expires'=>$expires],['name'=>LOGGED_IN_COOKIE,'value'=>wp_generate_auth_cookie($users[0]->ID,$expires,'logged_in'),'expires'=>$expires]],'request_id'=>$request_id]);`;
const phpArgs = ['-c', ini];
if (/^\d{2,5}$/.test(mysqliPort)) phpArgs.push('-d', `mysqli.default_port=${mysqliPort}`);
phpArgs.push('-r', phpCode);
const cookieOutput = execFileSync(php, phpArgs, { encoding: 'utf8' });
const jsonStart = cookieOutput.indexOf('{');
const session = JSON.parse(cookieOutput.slice(jsonStart));
for (const cookie of session.cookies) {
  const result = await command('Network.setCookie', { ...cookie, url: baseUrl + '/', httpOnly: true });
  expect(result.success === true, 'Anmelde-Cookie konnte nicht gesetzt werden.');
}
const storedCookieNames = (await command('Network.getAllCookies')).cookies
  .filter((cookie) => new URL(baseUrl).hostname.endsWith(cookie.domain.replace(/^\./, '')))
  .map((cookie) => cookie.name);
expect(session.cookies.every((cookie) => storedCookieNames.includes(cookie.name)), 'Anmelde-Cookies fehlen im Testbrowser.');

await navigate(baseUrl + '/van-operations/?view=calendar&pov_month=2026-07', '.pov-admin');
const calendarAudit = await evaluate(`(() => ({
  walkInOption: [...document.querySelectorAll('select[name="availability_state"] option')].some((option) => option.value === 'walk_in'),
  walkInDay: Boolean(document.querySelector('.pov-admin-calendar-day.is-walk_in')),
  eventFields: ['public_title','public_description','public_location','public_url','walk_in_children','walk_in_adults'].every((name) => Boolean(document.querySelector('[name="' + name + '"]'))),
}))()`);
expect(calendarAudit.walkInOption && calendarAudit.walkInDay && calendarAudit.eventFields, 'Walk-in-Pflege im Backend-Kalender ist unvollständig.');
await screenshot('current-features-backend-calendar.png');

await navigate(baseUrl + '/van-operations/?view=routes', '.pov-admin');
const routeBackendAudit = await evaluate(`(() => ({
  overnightRecommendations: document.querySelectorAll('.pov-tour-overnight').length,
  recommendationExpenseForms: document.querySelectorAll('.pov-tour-overnight .pov-tour-expense-form').length,
  redundantExpenseForm: Boolean(document.querySelector('.pov-add-expense')),
}))()`);
expect(routeBackendAudit.recommendationExpenseForms === routeBackendAudit.overnightRecommendations, 'Kostenpflege an den Übernachtungsempfehlungen ist unvollständig.');
expect(!routeBackendAudit.redundantExpenseForm, 'Die redundante freie Erfassung von Übernachtungskosten ist noch sichtbar.');
await screenshot('current-features-backend-routes.png');

await navigate(baseUrl + '/van-operations/?view=statistics&period=month', '.pov-admin');
const statisticsAudit = await evaluate(`(() => ({
  participantData: document.body.innerText.includes('Kinder') && document.body.innerText.includes('Erwachsene'),
  eventTypes: document.body.innerText.includes('Veranstaltungsarten'),
  overnightCosts: document.body.innerText.toLowerCase().includes('bernachtung'),
  periodCards: document.querySelectorAll('.pov-stat-period').length,
  costGroups: document.querySelectorAll('.pov-stat-costs').length,
  horizontalOverflow: document.querySelector('.pov-stat-panel')?.scrollWidth > document.querySelector('.pov-stat-panel')?.clientWidth + 1,
}))()`);
expect(statisticsAudit.participantData && statisticsAudit.eventTypes && statisticsAudit.overnightCosts, 'Die neue Statistik ist unvollständig.');
expect(statisticsAudit.periodCards > 0 && statisticsAudit.costGroups === statisticsAudit.periodCards, 'Die Kostenstatistik verwendet nicht durchgehend übersichtliche Zeitraumkarten.');
expect(!statisticsAudit.horizontalOverflow, 'Die Kostenstatistik läuft horizontal aus dem sichtbaren Bereich.');
await screenshot('current-features-backend-statistics.png');

await navigate(baseUrl + '/van-operations/', '.pov-admin');
const detailHref = baseUrl + '/van-operations/?request_id=' + session.request_id;
expect(session.request_id > 0, 'Keine bestätigte Testanfrage für die Detailprüfung gefunden.');
await navigate(detailHref, '.pov-admin');
const requestAudit = await evaluate(`(() => ({
  communicationHub: document.body.innerText.includes('Kommunikation'),
  attachmentInput: Boolean(document.querySelector('input[type="file"]')),
  detailEditor: Boolean(document.querySelector('form input[name="institution_name"]')),
  dateEditor: Boolean(document.querySelector('form input[name="appointment_date"]')),
  participantOutcome: Boolean(document.querySelector('form input[name="participants_children"]')),
}))()`);
expect(Object.values(requestAudit).every(Boolean), 'Anfragedetails, Kommunikation, Anhänge oder Ergebnisdaten fehlen.');
await screenshot('current-features-backend-request.png');

socket.close();
process.stdout.write(JSON.stringify({
  initialAudit,
  walkInAudit,
  routeAudit,
  calendarAudit,
  routeBackendAudit,
  statisticsAudit,
  requestAudit,
  errors,
}, null, 2) + '\n');
if (errors.length) process.exitCode = 2;
