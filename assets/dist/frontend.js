(function () {
  const root = document.querySelector('[data-pov-booking]');
  if (!root || !window.POV_BOOKING || !window.POVCalendar) return;

  const api = window.POV_BOOKING.restUrl;
  const $ = function (selector, context) {
    return (context || root).querySelector(selector);
  };
  const $$ = function (selector, context) {
    return Array.from((context || root).querySelectorAll(selector));
  };

  const form = $('[data-role="form"]');
  const routeScreen = $('[data-screen="route"]');
  const routeHeading = $('#pov-route-heading');
  const routeStatus = $('[data-role="route-status"]');
  const suggestionsElement = $('[data-role="suggestions"]');
  const calendarElement = $('[data-role="calendar"]');
  const rangeFrom = $('[data-range-field="from"]');
  const rangeTo = $('[data-range-field="to"]');

  routeStatus.id = 'pov-route-status';

  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const firstSuggestedDate = new Date(today);
  firstSuggestedDate.setDate(firstSuggestedDate.getDate() + 14);
  const horizon = new Date(today);
  horizon.setDate(horizon.getDate() + 365);

  const state = {
    month: new Date(today.getFullYear(), today.getMonth(), 1),
    days: new Map(),
    recommendations: new Set(),
    recommendationByDate: new Map(),
    selectedRecommendation: null,
    selectedDate: '',
    rangeFrom: '',
    rangeTo: '',
    possibleWeekdays: ['mon', 'tue', 'wed', 'thu', 'fri'],
    mode: 'date_range',
    step: 1,
    maxStep: 1,
    startedAt: Number(root.dataset.startedAt || Math.floor(Date.now() / 1000)),
    activeRegionKey: '',
    routeContext: null,
    routeRequestId: 0,
    calendarRequestId: 0,
    formOpened: false,
    formRegionKey: '',
    addressBaseline: '',
    addressDirty: false
  };

  let routeTimer = null;
  let routeController = null;
  let calendarController = null;

  const calendar = new window.POVCalendar(calendarElement, {
    initialMonth: state.month,
    onSelect: function (date) {
      selectDate(date);
    }
  });

  function iso(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return year + '-' + month + '-' + day;
  }

  function formatDate(value, style) {
    if (!value) return 'Noch offen';
    const date = new Date(value + 'T12:00:00');
    if (Number.isNaN(date.getTime())) return 'Noch offen';
    return new Intl.DateTimeFormat('de-DE', { dateStyle: style || 'full' }).format(date);
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
      return {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      }[character];
    });
  }

  async function responseData(response) {
    const text = await response.text();
    if (!text) return {};
    try {
      return JSON.parse(text);
    } catch (error) {
      return {};
    }
  }

  function preferredScrollBehavior() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
  }

  function routeRegionValues() {
    return {
      postal: $('[data-field="postal_code"]').value.trim(),
      code: $('[data-field="state_code"]').value
    };
  }

  function routeRegionKey() {
    const values = routeRegionValues();
    if (!/^\d{5}$/.test(values.postal) || !values.code) return '';
    return values.postal + ':' + values.code;
  }

  function formRegionKey() {
    const postal = form.elements.postal_code.value.trim();
    const code = form.elements.state_code.value;
    if (!/^\d{5}$/.test(postal) || !code) return '';
    return postal + ':' + code;
  }

  function setRouteStatus(message, type) {
    routeStatus.className = 'pov-live pov-route-status' + (type ? ' is-' + type : '');
    routeStatus.textContent = message || '';
    routeStatus.setAttribute('role', type === 'error' ? 'alert' : 'status');
  }

  function setRouteLoading(loading) {
    const button = $('[data-action="check-route"]');
    const label = button.querySelector('span:first-child');
    button.disabled = loading;
    button.classList.toggle('is-loading', loading);
    button.setAttribute('aria-busy', loading ? 'true' : 'false');
    suggestionsElement.setAttribute('aria-busy', loading ? 'true' : 'false');
    if (label) label.textContent = loading ? 'Route wird geprüft' : 'Termine finden';
  }

  function clearRouteFieldErrors() {
    $$('[data-field="postal_code"], [data-field="state_code"]').forEach(function (field) {
      field.removeAttribute('aria-invalid');
      field.removeAttribute('aria-describedby');
    });
  }

  function requireRouteRegion() {
    clearRouteFieldErrors();
    const values = routeRegionValues();
    const invalid = [];
    if (!values.code) invalid.push($('[data-field="state_code"]'));
    if (!/^\d{5}$/.test(values.postal)) invalid.push($('[data-field="postal_code"]'));
    if (!invalid.length) return true;

    invalid.forEach(function (field) {
      field.setAttribute('aria-invalid', 'true');
      field.setAttribute('aria-describedby', routeStatus.id);
    });
    setRouteStatus('Bitte Bundesland und fünfstellige PLZ eingeben.', 'error');
    invalid[0].focus();
    return false;
  }

  function renderSuggestionPlaceholder(title, text, tone) {
    suggestionsElement.innerHTML =
      '<div class="pov-empty-state' + (tone ? ' is-' + escapeHtml(tone) : '') + '">' +
      '<strong>' + escapeHtml(title) + '</strong>' +
      (text ? '<span>' + escapeHtml(text) + '</span>' : '') +
      '</div>';
  }

  function clearRecommendations(title, text, clearDate) {
    state.routeContext = null;
    state.selectedRecommendation = null;
    state.recommendations = new Set();
    state.recommendationByDate = new Map();
    if (clearDate) {
      state.selectedDate = '';
      state.rangeFrom = '';
      state.rangeTo = '';
    }
    calendar.setRecommended([]);
    if (typeof calendar.setSelected === 'function') calendar.setSelected('');
    renderSuggestionPlaceholder(title, text, 'warning');
  }

  function invalidateRouteInput() {
    window.clearTimeout(routeTimer);
    state.routeRequestId += 1;
    if (routeController) routeController.abort();
    routeController = null;
    setRouteLoading(false);
    state.activeRegionKey = '';
    clearRecommendations('Ort geändert', 'Bitte Route neu prüfen.', true);
  }

  function handleRouteRegionChange() {
    clearRouteFieldErrors();
    const key = routeRegionKey();
    if (state.activeRegionKey && key !== state.activeRegionKey) {
      invalidateRouteInput();
    } else if (!key) {
      invalidateRouteInput();
      setRouteStatus('');
      return;
    }
    if (!key) return;

    window.clearTimeout(routeTimer);
    setRouteStatus('Ort erkannt – wir suchen zwei passende Tage.', 'loading');
    routeTimer = window.setTimeout(checkRoute, 650);
  }

  function copyRouteRegionToForm() {
    const values = routeRegionValues();
    form.elements.postal_code.value = values.postal;
    form.elements.state_code.value = values.code;
    state.formRegionKey = routeRegionKey();
  }

  function markRouteFresh() {
    state.addressDirty = false;
    state.addressBaseline = addressFingerprint();
    const note = $('[data-role="form-route-note"]');
    note.hidden = true;
    $('[data-role="form-route-note-text"]').textContent = '';
  }

  async function checkRoute() {
    window.clearTimeout(routeTimer);
    if (!requireRouteRegion()) return;

    const values = routeRegionValues();
    const key = routeRegionKey();
    const requestId = state.routeRequestId + 1;
    state.routeRequestId = requestId;
    if (routeController) routeController.abort();
    routeController = new AbortController();

    setRouteLoading(true);
    setRouteStatus('Passende Termine werden berechnet …', 'loading');
    renderSuggestionPlaceholder('Termine kommen gleich', '', 'loading');

    try {
      const response = await fetch(api + 'recommendations', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ postal_code: values.postal, state_code: values.code }),
        signal: routeController.signal
      });
      const data = await responseData(response);
      if (!response.ok) {
        const apiError = new Error(data.message || 'Keine Routentermine gefunden.');
        apiError.isApiError = true;
        throw apiError;
      }
      if (requestId !== state.routeRequestId || routeRegionKey() !== key) return;

      state.activeRegionKey = key;
      state.routeContext = data.route_context || null;
      copyRouteRegionToForm();
      markRouteFresh();
      renderSuggestions(Array.isArray(data.suggestions) ? data.suggestions : []);
      setRouteStatus('Route geprüft.', 'success');
    } catch (error) {
      if (error.name === 'AbortError' || requestId !== state.routeRequestId || routeRegionKey() !== key) return;
      state.activeRegionKey = key;
      copyRouteRegionToForm();
      markRouteFresh();
      renderSuggestionPlaceholder('Keine Routentermine geladen', 'Kalender oder Zeitraum sind weiterhin möglich.', 'warning');
      setRouteStatus((error.isApiError ? error.message + ' ' : '') + 'Bitte Kalender oder Zeitraum nutzen.', 'error');
    } finally {
      if (requestId === state.routeRequestId) {
        setRouteLoading(false);
        routeController = null;
      }
    }
  }

  function renderSuggestions(items) {
    const best = items.filter(function (item) {
      return item && typeof item.date === 'string' && item.date >= iso(firstSuggestedDate);
    }).slice(0, 2);
    state.recommendations = new Set(best.map(function (item) { return item.date; }));
    state.recommendationByDate = new Map(best.map(function (item) { return [item.date, item]; }));
    calendar.setRecommended(Array.from(state.recommendations));
    suggestionsElement.innerHTML = '';

    if (!best.length) {
      renderSuggestionPlaceholder('Kein direkter Termin verfügbar', 'Kalender oder Zeitraum nutzen.', 'warning');
      return;
    }

    best.forEach(function (item, index) {
      const article = document.createElement('article');
      article.className = 'pov-suggestion is-top-date' + (index === 0 ? ' is-best' : '');
      article.dataset.date = item.date;
      article.innerHTML =
        '<div class="pov-top-date-rank" aria-hidden="true">' + (index + 1) + '</div>' +
        '<div class="pov-suggestion-copy">' +
        '<h3>' + escapeHtml(formatDate(item.date)) + '</h3>' +
        '</div>';
      const button = document.createElement('button');
      button.type = 'button';
      button.className = index === 0 ? 'pov-button' : 'pov-secondary-button';
      button.textContent = 'Termin wählen';
      button.setAttribute('aria-label', formatDate(item.date) + ' wählen');
      button.addEventListener('click', function () {
        selectDate(item.date);
      });
      article.appendChild(button);
      suggestionsElement.appendChild(article);
    });
  }

  function monthBounds() {
    const year = state.month.getFullYear();
    const month = state.month.getMonth();
    return {
      start: iso(new Date(year, month, 1)),
      end: iso(new Date(year, month + 1, 0))
    };
  }

  function setCalendarStatus(message, type) {
    const status = $('[data-role="calendar-status"]');
    status.textContent = message || '';
    status.className = 'pov-live pov-calendar-status' + (type ? ' is-' + type : '');
    status.setAttribute('role', type === 'error' ? 'alert' : 'status');
  }

  function updateMonthButtons() {
    const previous = $('[data-action="prev-month"]');
    const next = $('[data-action="next-month"]');
    const firstMonth = new Date(today.getFullYear(), today.getMonth(), 1);
    const lastMonth = new Date(horizon.getFullYear(), horizon.getMonth(), 1);
    previous.disabled = state.month <= firstMonth;
    next.disabled = state.month >= lastMonth;
  }

  function renderCalendar() {
    $('[data-role="month-label"]').textContent = new Intl.DateTimeFormat('de-DE', {
      month: 'long',
      year: 'numeric'
    }).format(state.month);
    calendar.setMonth(state.month);
    calendar.setRecommended(Array.from(state.recommendations));
    calendar.setRows(Array.from(state.days.values()));
    if (typeof calendar.setSelected === 'function') calendar.setSelected(state.selectedDate);
    updateMonthButtons();
  }

  async function loadCalendar() {
    const bounds = monthBounds();
    const requestId = state.calendarRequestId + 1;
    state.calendarRequestId = requestId;
    if (calendarController) calendarController.abort();
    calendarController = new AbortController();

    state.days = new Map();
    calendarElement.setAttribute('aria-busy', 'true');
    setCalendarStatus('Kalender wird geladen …', 'loading');
    renderCalendar();

    try {
      const response = await fetch(api + 'calendar?start=' + bounds.start + '&end=' + bounds.end, {
        signal: calendarController.signal
      });
      const rows = await responseData(response);
      if (!response.ok || !Array.isArray(rows)) throw new Error('calendar');
      if (requestId !== state.calendarRequestId) return;
      state.days = new Map(rows.map(function (row) { return [row.date, row]; }));
      setCalendarStatus('');
      renderCalendar();
    } catch (error) {
      if (error.name === 'AbortError' || requestId !== state.calendarRequestId) return;
      state.days = new Map();
      setCalendarStatus('Kalender nicht geladen. Alle Tage bleiben gesperrt.', 'error');
      renderCalendar();
    } finally {
      if (requestId === state.calendarRequestId) {
        calendarElement.setAttribute('aria-busy', 'false');
        calendarController = null;
      }
    }
  }

  function ensureActiveRoute() {
    if (state.activeRegionKey && state.activeRegionKey === routeRegionKey()) return true;
    setRouteStatus('Bitte zuerst die Route prüfen.', 'error');
    routeHeading.focus({ preventScroll: true });
    routeScreen.scrollIntoView({ behavior: preferredScrollBehavior(), block: 'start' });
    return false;
  }

  function selectDate(date) {
    if (!ensureActiveRoute()) return;
    state.mode = 'specific_date';
    state.selectedDate = date;
    state.rangeFrom = '';
    state.rangeTo = '';
    state.possibleWeekdays = [];
    state.selectedRecommendation = state.recommendationByDate.get(date) || null;
    if (typeof calendar.setSelected === 'function') calendar.setSelected(date);
    openForm();
  }

  function setRangeStatus(message, type) {
    const status = $('[data-role="range-status"]');
    status.textContent = message || '';
    status.className = 'pov-live' + (type ? ' is-' + type : '');
    status.setAttribute('role', type === 'error' ? 'alert' : 'status');
  }

  function clearRangeErrors() {
    [rangeFrom, rangeTo].forEach(function (field) {
      field.removeAttribute('aria-invalid');
      field.removeAttribute('aria-describedby');
    });
    $('[data-range-weekday-group]').removeAttribute('aria-invalid');
  }

  function selectRange() {
    clearRangeErrors();
    const weekdays = $$('[data-range-weekday]:checked').map(function (field) { return field.value; });
    const invalid = [];
    if (!rangeFrom.value || rangeFrom.value < iso(today)) invalid.push(rangeFrom);
    if (!rangeTo.value || rangeTo.value < rangeFrom.value || rangeTo.value > iso(horizon)) invalid.push(rangeTo);
    if (!weekdays.length) $('[data-range-weekday-group]').setAttribute('aria-invalid', 'true');

    if (invalid.length || !weekdays.length) {
      invalid.forEach(function (field) { field.setAttribute('aria-invalid', 'true'); });
      setRangeStatus('Bitte Zeitraum und mindestens einen Wochentag prüfen.', 'error');
      if (invalid[0]) invalid[0].focus();
      else $('[data-range-weekday]').focus();
      return;
    }
    if (!ensureActiveRoute()) return;

    state.mode = 'date_range';
    state.selectedDate = '';
    state.rangeFrom = rangeFrom.value;
    state.rangeTo = rangeTo.value;
    state.possibleWeekdays = weekdays;
    state.selectedRecommendation = null;
    if (typeof calendar.setSelected === 'function') calendar.setSelected('');
    setRangeStatus('');
    openForm();
  }

  function toggleOptionPanel(name) {
    const calendarPanel = $('[data-role="calendar-panel"]');
    const rangePanel = $('[data-role="range-panel"]');
    const calendarButton = $('[data-action="toggle-calendar"]');
    const rangeButton = $('[data-action="toggle-range"]');
    const target = name === 'calendar' ? calendarPanel : rangePanel;
    const other = name === 'calendar' ? rangePanel : calendarPanel;
    const targetButton = name === 'calendar' ? calendarButton : rangeButton;
    const otherButton = name === 'calendar' ? rangeButton : calendarButton;
    const willOpen = target.hidden;

    target.hidden = !willOpen;
    other.hidden = true;
    targetButton.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    otherButton.setAttribute('aria-expanded', 'false');
    calendarButton.textContent = calendarPanel.hidden ? 'Kalender öffnen' : 'Kalender schließen';
    rangeButton.textContent = rangePanel.hidden ? 'Zeitraum anfragen' : 'Zeitraum schließen';

    if (willOpen) {
      const heading = target.querySelector('h3');
      window.requestAnimationFrame(function () {
        if (heading) heading.focus({ preventScroll: true });
        target.scrollIntoView({ behavior: preferredScrollBehavior(), block: 'nearest' });
      });
    }
  }

  function selectedDateLabel() {
    if (state.mode === 'specific_date') return formatDate(state.selectedDate);
    if (state.rangeFrom && state.rangeTo) {
      return formatDate(state.rangeFrom, 'medium') + ' bis ' + formatDate(state.rangeTo, 'medium');
    }
    return 'Noch offen';
  }

  function openForm() {
    copyRouteRegionToForm();
    $('[data-role="selected-date-summary"]').textContent = selectedDateLabel();
    routeScreen.hidden = true;
    form.hidden = false;
    if (!state.formOpened) {
      state.formOpened = true;
      state.maxStep = 1;
    }
    setStep(1, true);
  }

  function syncFormRegionToRoute() {
    $('[data-field="postal_code"]').value = form.elements.postal_code.value.trim();
    $('[data-field="state_code"]').value = form.elements.state_code.value;
  }

  function backToRoute() {
    if (state.addressDirty) syncFormRegionToRoute();
    form.hidden = true;
    routeScreen.hidden = false;
    window.requestAnimationFrame(function () {
      routeHeading.focus({ preventScroll: true });
      routeScreen.scrollIntoView({ behavior: preferredScrollBehavior(), block: 'start' });
    });
  }

  function recheckFormRoute() {
    syncFormRegionToRoute();
    invalidateRouteInput();
    form.hidden = true;
    routeScreen.hidden = false;
    setRouteStatus('Adresse übernommen – Route neu prüfen.', 'loading');
    window.requestAnimationFrame(function () {
      routeHeading.focus({ preventScroll: true });
      routeScreen.scrollIntoView({ behavior: preferredScrollBehavior(), block: 'start' });
      if (routeRegionKey()) checkRoute();
      else requireRouteRegion();
    });
  }

  function setStep(step, focus) {
    state.step = Math.max(1, Math.min(2, step));
    $$('[data-form-step]', form).forEach(function (fieldset) {
      fieldset.hidden = Number(fieldset.dataset.formStep) !== state.step;
    });
    $$('[data-goto-step]', form).forEach(function (button) {
      const buttonStep = Number(button.dataset.gotoStep);
      button.disabled = buttonStep > state.maxStep;
      button.classList.toggle('is-current', buttonStep === state.step);
      button.classList.toggle('is-complete', buttonStep < state.step || buttonStep < state.maxStep);
      if (buttonStep === state.step) button.setAttribute('aria-current', 'step');
      else button.removeAttribute('aria-current');
    });
    $('[data-role="progress-fill"]').style.width = state.step === 2 ? '100%' : '0';
    if (state.step === 2) renderSummary();
    if (focus !== false) {
      const legend = $('[data-form-step="' + state.step + '"] > legend', form);
      window.requestAnimationFrame(function () {
        if (legend) legend.focus({ preventScroll: true });
        form.scrollIntoView({ behavior: preferredScrollBehavior(), block: 'start' });
      });
    }
  }

  function clearStepErrors(fieldset) {
    $$('.pov-error', fieldset).forEach(function (element) { element.remove(); });
    $$('[aria-invalid="true"]', fieldset).forEach(function (element) {
      element.removeAttribute('aria-invalid');
    });
    $$('[aria-describedby]', fieldset).forEach(function (element) {
      const remaining = (element.getAttribute('aria-describedby') || '')
        .split(/\s+/)
        .filter(function (id) { return id && id.indexOf('pov-step-error-') !== 0; });
      if (remaining.length) element.setAttribute('aria-describedby', remaining.join(' '));
      else element.removeAttribute('aria-describedby');
    });
  }

  function validateStep(step, focusInvalid) {
    const fieldset = $('[data-form-step="' + step + '"]', form);
    clearStepErrors(fieldset);
    const invalid = $$('input, select, textarea', fieldset).filter(function (field) {
      return !field.disabled && !field.checkValidity();
    });
    const uniqueInvalid = Array.from(new Set(invalid.filter(Boolean)));
    if (!uniqueInvalid.length) return true;

    const error = document.createElement('div');
    error.className = 'pov-error';
    error.id = 'pov-step-error-' + step;
    error.setAttribute('role', 'alert');
    error.textContent = 'Bitte markierte Angaben prüfen.';
    const actions = $('.pov-actions', fieldset);
    fieldset.insertBefore(error, actions || null);

    uniqueInvalid.forEach(function (field) {
      field.setAttribute('aria-invalid', 'true');
      field.setAttribute('aria-describedby', error.id);
      const group = field.closest('.pov-choice-fieldset');
      if (group) {
        group.setAttribute('aria-describedby', error.id);
        group.setAttribute('aria-invalid', 'true');
      }
    });
    if (focusInvalid !== false && uniqueInvalid[0].focus) uniqueInvalid[0].focus();
    return false;
  }

  function moveNext() {
    if (!validateStep(state.step, true)) return;
    state.maxStep = 2;
    setStep(2, true);
  }

  function answerLabel(value) {
    return { yes: 'Ja', no: 'Nein', unknown: 'Unklar' }[value] || 'Noch offen';
  }

  function radioValue(name) {
    const selected = form.querySelector('input[name="' + name + '"]:checked');
    return selected ? selected.value : '';
  }

  function renderSummary() {
    const weather = radioValue('weather_option');
    const onsite = [
      'Parkplatz: ' + answerLabel(radioValue('parking_available')),
      'Strom: ' + answerLabel(radioValue('electricity_available')),
      'Wasser: ' + answerLabel(radioValue('water_available')),
      'Innenraum: ' + answerLabel(weather)
    ].join(' · ');
    $('[data-role="summary-content"]').innerHTML =
      '<div class="pov-summary-row"><div><strong>' + escapeHtml(selectedDateLabel()) + '</strong><span>' +
      escapeHtml(form.elements.institution_name.value || 'Noch offen') + ' · ' +
      escapeHtml(form.elements.institution_type.value || 'Noch offen') + '<br>' +
      escapeHtml(form.elements.street.value + ' ' + form.elements.house_number.value) + ', ' +
      escapeHtml(form.elements.postal_code.value + ' ' + form.elements.city.value) + '<br>' +
      escapeHtml(form.elements.participant_total.value || '0') + ' Teilnehmende · ' +
      escapeHtml(form.elements.target_group.value || 'Noch offen') +
      '</span></div><button type="button" data-edit-step="1" aria-label="Kontakt und Gruppe ändern">Ändern</button></div>' +
      '<div class="pov-summary-row"><div><strong>Vor Ort</strong><span>' +
      escapeHtml(onsite) +
      '</span></div><button type="button" data-focus-onsite aria-label="Vor-Ort-Angaben ändern">Ändern</button></div>';
  }

  function addressFingerprint() {
    const street = form.elements.street.value.trim();
    const house = form.elements.house_number.value.trim();
    const city = form.elements.city.value.trim();
    const region = formRegionKey();
    if (!street || !house || !city || !region) return '';
    return [street.toLowerCase(), house.toLowerCase(), city.toLowerCase(), region].join('|');
  }

  function showFormRouteNote(message) {
    const note = $('[data-role="form-route-note"]');
    note.hidden = false;
    $('[data-role="form-route-note-text"]').textContent = message;
  }

  function invalidateFormRecommendation(message) {
    if (state.addressDirty) return;
    state.addressDirty = true;
    state.activeRegionKey = '';
    clearRecommendations('Adresse geändert', 'Route bitte erneut prüfen.', false);
    showFormRouteNote(message);
  }

  function trackFormRegionChange() {
    if (formRegionKey() !== state.formRegionKey) {
      invalidateFormRecommendation('PLZ oder Bundesland geändert. Die bisherigen Empfehlungen sind nicht mehr gültig.');
    }
  }

  function trackAddressChange() {
    const fingerprint = addressFingerprint();
    if (!fingerprint) return;
    if (!state.addressBaseline) {
      state.addressBaseline = fingerprint;
      return;
    }
    if (fingerprint !== state.addressBaseline) {
      invalidateFormRecommendation('Adresse geändert. Bitte die Route erneut prüfen.');
    }
  }

  function gradeForTarget(target) {
    return target === 'Weiterführende Schule' ? 5 : 1;
  }

  function collectPayload() {
    const data = new FormData(form);
    const weather = data.get('weather_option') || '';
    const target = String(data.get('target_group') || 'Gruppe');
    const participantCount = String(data.get('participant_total') || '0');
    const payload = {
      website: data.get('website') || '',
      form_started_at: state.startedAt,
      request_mode: state.mode,
      institution_name: data.get('institution_name') || '',
      institution_type: data.get('institution_type') || '',
      contact_first_name: data.get('contact_first_name') || '',
      contact_last_name: data.get('contact_last_name') || '',
      contact_email: data.get('contact_email') || '',
      contact_phone: data.get('contact_phone') || '',
      street: data.get('street') || '',
      house_number: data.get('house_number') || '',
      postal_code: data.get('postal_code') || '',
      city: data.get('city') || '',
      state_code: data.get('state_code') || '',
      privacy_consent: data.get('privacy_consent') ? 1 : 0,
      specific_requested_date: state.mode === 'specific_date' ? state.selectedDate : '',
      desired_date_from: state.mode === 'date_range' ? state.rangeFrom : '',
      desired_date_to: state.mode === 'date_range' ? state.rangeTo : '',
      possible_weekdays: state.mode === 'date_range' ? state.possibleWeekdays : [],
      parking_available: data.get('parking_available') || '',
      electricity_available: data.get('electricity_available') || '',
      water_available: data.get('water_available') || '',
      indoor_room_available: weather,
      bad_weather_option_available: weather,
      accessibility_notes: '',
      group_notes: '',
      general_notes: data.get('general_notes') || '',
      classes: [{
        class_name: target,
        grade: gradeForTarget(target),
        participant_count: participantCount
      }]
    };
    if (!state.addressDirty && state.routeContext) {
      payload.latitude = state.routeContext.latitude;
      payload.longitude = state.routeContext.longitude;
    }
    if (!state.addressDirty && state.selectedRecommendation) {
      payload.route_distance_km = state.selectedRecommendation.estimated_distance_km || null;
      payload.route_cost = state.selectedRecommendation.estimated_cost || null;
    }
    return payload;
  }

  async function submitForm(event) {
    event.preventDefault();
    if (!state.selectedDate && (!state.rangeFrom || !state.rangeTo)) {
      backToRoute();
      setRouteStatus('Bitte zuerst einen Termin oder Zeitraum wählen.', 'error');
      return;
    }
    for (let step = 1; step <= 2; step += 1) {
      if (!validateStep(step, false)) {
        state.maxStep = Math.max(state.maxStep, step);
        setStep(step, true);
        validateStep(step, true);
        return;
      }
    }

    const status = $('[data-role="submit-status"]');
    const button = $('.pov-submit-button', form);
    status.className = 'pov-live';
    status.setAttribute('role', 'status');
    status.textContent = 'Anfrage wird gesendet …';
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');

    try {
      const response = await fetch(api + 'requests', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(collectPayload())
      });
      const data = await responseData(response);
      if (!response.ok) throw new Error(data.message || 'Die Anfrage konnte nicht gesendet werden.');

      form.hidden = true;
      const success = $('[data-role="success"]');
      $('[data-role="public-uuid"]').textContent = data.public_uuid || '';
      success.hidden = false;
      window.requestAnimationFrame(function () {
        success.focus({ preventScroll: true });
        success.scrollIntoView({ behavior: preferredScrollBehavior(), block: 'center' });
      });
    } catch (error) {
      status.className = 'pov-live pov-error';
      status.setAttribute('role', 'alert');
      status.textContent = error && error.message
        ? error.message
        : 'Die Anfrage konnte nicht gesendet werden.';
      button.disabled = false;
      button.removeAttribute('aria-busy');
    }
  }

  root.addEventListener('click', function (event) {
    if (!(event.target instanceof Element)) return;
    const target = event.target.closest('button, [data-action]');
    if (!target || target.disabled) return;
    const action = target.dataset.action;

    if (action === 'prev-month') {
      state.month = new Date(state.month.getFullYear(), state.month.getMonth() - 1, 1);
      loadCalendar();
    }
    if (action === 'next-month') {
      state.month = new Date(state.month.getFullYear(), state.month.getMonth() + 1, 1);
      loadCalendar();
    }
    if (action === 'check-route') checkRoute();
    if (action === 'toggle-calendar') toggleOptionPanel('calendar');
    if (action === 'toggle-range') toggleOptionPanel('range');
    if (action === 'select-range') selectRange();
    if (action === 'back-route') backToRoute();
    if (action === 'recheck-form-route') recheckFormRoute();
    if (target.matches('[data-next]')) moveNext();
    if (target.matches('[data-prev]')) setStep(1, true);
    if (target.dataset.gotoStep) {
      const targetStep = Number(target.dataset.gotoStep);
      if (targetStep <= state.maxStep && (targetStep < state.step || validateStep(state.step, true))) {
        setStep(targetStep, true);
      }
    }
    if (target.dataset.editStep) setStep(Number(target.dataset.editStep), true);
    if (target.hasAttribute('data-focus-onsite')) {
      const firstQuestion = $('input[name="parking_available"]', form);
      if (firstQuestion) firstQuestion.focus();
    }
  });

  $('[data-field="postal_code"]').addEventListener('input', handleRouteRegionChange);
  $('[data-field="state_code"]').addEventListener('change', handleRouteRegionChange);
  form.elements.postal_code.addEventListener('input', trackFormRegionChange);
  form.elements.state_code.addEventListener('change', trackFormRegionChange);
  ['street', 'house_number', 'city'].forEach(function (name) {
    form.elements[name].addEventListener('change', trackAddressChange);
  });
  rangeFrom.addEventListener('change', function () {
    rangeTo.min = rangeFrom.value || iso(today);
    if (rangeTo.value && rangeTo.value < rangeTo.min) rangeTo.value = rangeTo.min;
  });
  form.addEventListener('input', function () {
    if (state.step === 2) renderSummary();
  });
  form.addEventListener('change', function () {
    if (state.step === 2) renderSummary();
  });
  form.addEventListener('submit', submitForm);

  const defaultFrom = new Date(today);
  defaultFrom.setDate(defaultFrom.getDate() + 14);
  const defaultTo = new Date(defaultFrom);
  defaultTo.setDate(defaultTo.getDate() + 28);
  rangeFrom.min = iso(today);
  rangeFrom.max = iso(horizon);
  rangeFrom.value = iso(defaultFrom);
  rangeTo.min = rangeFrom.value;
  rangeTo.max = iso(horizon);
  rangeTo.value = iso(defaultTo);
  $$('[data-range-weekday]').forEach(function (field) { field.checked = true; });
  loadCalendar();
})();
