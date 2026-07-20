import { execFileSync } from 'node:child_process';

const endpoint = process.argv[2] || 'http://127.0.0.1:9333';
const php = process.argv[3];
const ini = process.argv[4];
const wpLoad = process.argv[5];
if (!php || !ini || !wpLoad) throw new Error('QA-Argumente fehlen.');

const targets = await fetch(endpoint + '/json/list').then((response) => response.json());
const target = targets.find((item) => item.type === 'page');
const socket = new WebSocket(target.webSocketDebuggerUrl);
const pending = new Map();
let sequence = 0;
await new Promise((resolve, reject) => {
  socket.addEventListener('open', resolve, { once: true });
  socket.addEventListener('error', reject, { once: true });
});
socket.addEventListener('message', (event) => {
  const payload = JSON.parse(String(event.data));
  if (!payload.id || !pending.has(payload.id)) return;
  const handler = pending.get(payload.id);
  pending.delete(payload.id);
  payload.error ? handler.reject(new Error(payload.error.message)) : handler.resolve(payload.result);
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
async function waitFor(expression, timeoutMs = 20000) {
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
}
function cookiesFor(login, role) {
  const code = `require ${JSON.stringify(wpLoad)}; $login=${JSON.stringify(login)}; $user=get_user_by('login',$login); if(!$user){$id=wp_insert_user(['user_login'=>$login,'user_pass'=>wp_generate_password(24,true,true),'user_email'=>$login.'@example.test','display_name'=>$login]);$user=get_user_by('id',$id);} $user->set_role(${JSON.stringify(role)}); if($login==='pov_qa_access_only'){$user->add_cap('pov_access_portal');} $expires=time()+900; echo wp_json_encode([['name'=>AUTH_COOKIE,'value'=>wp_generate_auth_cookie($user->ID,$expires,'auth'),'expires'=>$expires],['name'=>LOGGED_IN_COOKIE,'value'=>wp_generate_auth_cookie($user->ID,$expires,'logged_in'),'expires'=>$expires]]);`;
  const output = execFileSync(php, ['-c', ini, '-r', code], { encoding: 'utf8' });
  return JSON.parse(output.slice(output.indexOf('[')));
}
async function login(login, role) {
  await command('Network.clearBrowserCookies');
  for (const cookie of cookiesFor(login, role)) {
    await command('Network.setCookie', { ...cookie, url: 'http://proocean.local/', path: '/', httpOnly: true, sameSite: 'Lax' });
  }
}

await command('Page.enable');
await command('Runtime.enable');
await command('Network.enable');

await login('pov_qa_team', 'pov_van_team');
await navigate('http://proocean.local/van-operations/');
await waitFor('Boolean(document.querySelector(".pov-admin"))');
const team = await evaluate(`(() => ({
  nav: [...document.querySelectorAll('.pov-admin-header nav a')].map((node) => node.textContent.trim()),
  settings: Boolean(document.querySelector('.pov-admin-usernav a[href*="pov-settings"]')),
  detailHref: document.querySelector('.pov-open-button')?.href || '',
}))()`);
if (team.detailHref) {
  await navigate(team.detailHref);
  await waitFor('Boolean(document.querySelector(".pov-admin"))');
  team.responses = Boolean(await evaluate('document.querySelector("[data-pov-response-form]")'));
}
await navigate('http://proocean.local/wp-admin/admin.php?page=pov-settings');
team.adminRedirected = await evaluate('location.pathname.replace(/\\/+$/, "") === "/van-operations"');

await login('pov_qa_reader', 'pov_van_reader');
await navigate('http://proocean.local/van-operations/');
await waitFor('Boolean(document.querySelector(".pov-admin"))');
const reader = await evaluate(`(() => ({
  path: location.pathname,
  view: new URL(location.href).searchParams.get('view'),
  nav: [...document.querySelectorAll('.pov-admin-header nav a')].map((node) => node.textContent.trim()),
  requestLinks: document.querySelectorAll('a[href*="request_id"]').length,
  retryForms: document.querySelectorAll('input[name="action"][value="pov_retry_missing_addresses"]').length,
}))()`);
await navigate('http://proocean.local/van-operations/?view=calendar');
await waitFor('Boolean(document.querySelector(".pov-admin"))');
reader.calendar = await evaluate(`(() => ({
  export: Boolean(document.querySelector('[data-pov-calendar-export]')),
  editForm: Boolean(document.querySelector('[data-pov-calendar-form]')),
  editableDays: document.querySelectorAll('[data-pov-calendar-day]').length,
  leakedInternalNotes: [...document.querySelectorAll('[data-internal-note]')].some((node) => Boolean(node.dataset.internalNote)),
}))()`);

await login('pov_qa_subscriber', 'subscriber');
await navigate('http://proocean.local/van-operations/');
await waitFor('document.title.includes("Zugriff verweigert") || document.body.innerText.includes("keinen Zugriff")');
const subscriber = await evaluate(`(() => ({
  portal: Boolean(document.querySelector('.pov-admin')),
  denied: document.body.innerText.includes('keinen Zugriff'),
}))()`);

await login('pov_qa_access_only', 'subscriber');
await navigate('http://proocean.local/van-operations/');
await waitFor('document.title.includes("Zugriff verweigert") || document.body.innerText.includes("noch kein Portalbereich")');
const accessOnly = await evaluate(`(() => ({
  portal: Boolean(document.querySelector('.pov-admin')),
  denied: document.body.innerText.includes('noch kein Portalbereich'),
  path: location.pathname,
}))()`);

const cleanup = `require ${JSON.stringify(wpLoad)}; require_once ABSPATH.'wp-admin/includes/user.php'; foreach(['pov_qa_team','pov_qa_reader','pov_qa_subscriber','pov_qa_access_only'] as $login){$user=get_user_by('login',$login);if($user){wp_delete_user($user->ID);}}`;
execFileSync(php, ['-c', ini, '-r', cleanup], { encoding: 'utf8' });
await command('Network.clearBrowserCookies');
process.stdout.write(JSON.stringify({ team, reader, subscriber, accessOnly }, null, 2) + '\n');
socket.close();
