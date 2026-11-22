import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.POV_PLAYWRIGHT || 'playwright');
const root = fileURLToPath(new URL('../', import.meta.url));
const source = file => readFileSync(new URL('../' + file, import.meta.url), 'utf8');
const booking = execFileSync(process.env.POV_PHP || 'php', ['-r', String.raw`
define('ABSPATH', '.');
define('POV_PLUGIN_URL', '/');
function get_option($name, $default = '') { return $default; }
function home_url($path) { return $path; }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_url($value) { return esc_attr($value); }
function esc_html($value) { return esc_attr($value); }
require 'src/Domain/SchoolClasses.php';
$states = [['state_code' => 'BW', 'state_name' => 'Baden-Württemberg', 'is_active' => 1]];
$tourWindows = [];
include 'templates/frontend-booking.php';
`], { cwd: root, encoding: 'utf8' });
const browser = await chromium.launch({ executablePath: process.env.POV_BROWSER || undefined });
try {
  const page = await browser.newPage({ viewport: { width: 1200, height: 900 }, timezoneId: 'Europe/Berlin' });
  const errors = [];
  page.on('pageerror', error => errors.push(error));
  await page.clock.setFixedTime(new Date('2026-11-02T12:00:00Z'));
  const dates = ['2026-11-18', '2026-11-20', '2026-11-23', '2026-11-24'];
  await page.route('**/*', async route => {
    const url = new URL(route.request().url());
    if (url.origin !== 'https://booking.test') return route.abort();
    if (url.pathname === '/') {
      const html = '<!doctype html><meta charset="utf-8"><style>' + source('assets/dist/frontend.css') + '</style>'
        + booking + '<script>window.POV_BOOKING = { restUrl: "/api/" };</script><script>'
        + source('assets/vendor/pov-calendar/pov-calendar.js') + '</script><script>'
        + source('assets/dist/frontend.js') + '</script>';
      return route.fulfill({ contentType: 'text/html; charset=utf-8', body: html });
    }
    if (url.pathname === '/api/recommendations') return route.fulfill({ json: {
      suggestions: dates.map(date => ({ date })), eligible_dates: dates, eligibility_token: 'test-token',
    } });
    if (url.pathname === '/api/availability') {
      const from = url.searchParams.get('from');
      const to = url.searchParams.get('to');
      const available = ['2026-11-05', ...dates].filter(date => date >= from && date <= to);
      return route.fulfill({ json: { available: available.length > 0, dates: available } });
    }
    if (url.pathname === '/api/calendar') return route.fulfill({ json: [
      { date: '2026-11-05', public_state: 'available', public_label: 'Verfügbar', is_selectable: true },
      { date: '2026-11-19', public_state: 'tour', public_label: 'Ocean Van in Gerlingen' },
    ] });
    return route.abort();
  });
  await page.goto('https://booking.test/');
  await page.locator('#pov-state-code').selectOption('BW');
  await page.locator('#pov-postal-code').fill('70180');
  const suggestions = page.locator('[data-role="suggestions"] article');
  await suggestions.nth(2).waitFor();
  assert.equal(await suggestions.count(), 3);
  const picker = page.locator('[data-role="date-picker"]');
  assert.equal(await picker.getAttribute('open'), null);
  await suggestions.nth(1).locator('button').click();
  const form = page.locator('[data-role="form"]');
  await form.waitFor();
  assert.match(await form.locator('[data-role="selected-date-summary"]').textContent(), /20\./);

  await form.locator('select[name="institution_type"]').selectOption('Schule');
  await form.locator('select[name="school_support"]').selectOption('yes');
  assert.equal(await form.locator('[name="school_support_amount"]').isVisible(), true);
  for (const width of [1200, 390]) {
    await page.setViewportSize({ width, height: 900 });
    const gaps = await form.evaluate(element => {
      const rect = selector => element.querySelector(selector).getBoundingClientRect();
      return {
        groups: rect('#pov-location-heading').top - rect('.pov-form-group').bottom,
        support: rect('.pov-school-support').top - rect('[name="school_needs"]').bottom,
      };
    });
    assert.ok(gaps.groups >= 16 && gaps.support >= 12, JSON.stringify(gaps));
  }

  await form.locator('[data-action="back-route"]').click();
  await picker.locator('summary').click();
  const cells = picker.locator('[data-role="picker-calendar"]');
  await cells.locator('[data-date="2026-11-05"][data-state="available"]').waitFor();
  assert.equal(await cells.locator('[data-date="2026-11-19"]').isDisabled(), true);
  await picker.locator('[data-role="date-mode"]').selectOption('range');
  await cells.locator('[data-date="2026-11-18"]').click();
  assert.equal(await picker.locator('[data-action="confirm-date"]').isDisabled(), true);
  await cells.locator('[data-date="2026-11-20"]').click();
  assert.equal(await cells.locator('[data-date="2026-11-19"]').getAttribute('data-selection'), null);
  await picker.locator('[data-action="confirm-date"]').click();
  await form.waitFor();
  assert.match(await form.locator('[data-role="selected-date-summary"]').textContent(), /18.*bis.*20/);

  await form.locator('[data-action="back-route"]').click();
  await picker.locator('[data-role="date-mode"]').selectOption('single');
  await cells.locator('[data-date="2026-11-05"]').click();
  await picker.locator('[data-action="confirm-date"]').click();
  await form.waitFor();
  assert.match(await form.locator('[data-role="selected-date-summary"]').textContent(), /5\. November/);
  assert.deepEqual(errors, []);
  console.log('Date picker UI checks passed at desktop and mobile widths.');
} finally {
  await browser.close();
}
