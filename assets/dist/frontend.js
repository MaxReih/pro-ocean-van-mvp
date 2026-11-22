(function () {
  const root = document.querySelector('[data-pov-booking]');
  if (!root || !window.POV_BOOKING || !window.POVCalendar) return;

  const api = window.POV_BOOKING.restUrl;
  const requestTimeoutMs = Math.max(5000, Number(window.POV_BOOKING.requestTimeoutMs || 12000));
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

  const dateRequest = $('[data-role="date-request"]');
  const pickerPanel = $('[data-role="date-picker"]');
  const pickerElement = $('[data-role="picker-calendar"]');
  const pickerStatus = $('[data-role="picker-status"]');
  const dateMode = $('[data-role="date-mode"]');
  const dateStatus = $('[data-role="date-status"]');
  const dateNext = $('[data-action="confirm-date"]');
  const weekdayCodes = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
  routeStatus.id = 'pov-route-status';

  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const firstSuggestedDate = new Date(today);
  firstSuggestedDate.setDate(firstSuggestedDate.getDate() + 14);
  const horizon = new Date(today);
  horizon.setDate(horizon.getDate() + 365);
  const latestDate = new Date(today);
  latestDate.setMonth(latestDate.getMonth() + 18);

  const state = {
    month: new Date(today.getFullYear(), today.getMonth(), 1),
    days: new Map(),
    recommendationByDate: new Map(),
    geoEligibleDates: new Set(),
    eligibilityToken: '',
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
  let pickerController = null;
  let pickerStart = '';
  let pickerEnd = '';
  let pickerChecking = false;

  const calendar = new window.POVCalendar(calendarElement, {
    initialMonth: state.month,
    overview: true,
    onDetails: function (date, row) {
      openWalkIn(date, row);
    }
  });
  const picker = new window.POVCalendar(pickerElement, { initialMonth: state.month, onSelect: selectPickerDay });

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

  function endpoint(path, query = {}) {
    const url = new URL(api, window.location.href);
    if (url.searchParams.has('rest_route')) {
      url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/?$/, '/') + path);
    } else {
      url.pathname = url.pathname.replace(/\/?$/, '/') + path;
    }
    new URLSearchParams(query).forEach((value, key) => url.searchParams.set(key, value));
    return url;
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
    button.disabled = loading || $('[data-field="state_code"]').selectedOptions[0]?.dataset.active === '0';
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
    state.recommendationByDate = new Map();
    state.geoEligibleDates = new Set();
    state.eligibilityToken = '';
    if (clearDate) {
      state.selectedDate = '';
      state.rangeFrom = '';
      state.rangeTo = '';
      pickerPanel.open = false;
      if (pickerController) pickerController.abort();
      pickerController = null;
      picker.setRows([]);
      pickerStart = pickerEnd = '';
      renderPickerSelection();
    }
    if (title || text) renderSuggestionPlaceholder(title, text, 'warning');
    else suggestionsElement.replaceChildren();
  }

  function invalidateRouteInput() {
    window.clearTimeout(routeTimer);
    state.routeRequestId += 1;
    if (routeController) routeController.abort();
    routeController = null;
    setRouteLoading(false);
    state.activeRegionKey = '';
    clearRecommendations('', '', true);
  }

  function handleRouteRegionChange() {
    clearRouteFieldErrors();
    const inactive = $('[data-field="state_code"]').selectedOptions[0]?.dataset.active === '0';
    const key = routeRegionKey();
    $('[data-role="region-contact"]').hidden = !inactive;
    dateRequest.hidden = !key || inactive;
    if (inactive || !key || state.activeRegionKey !== key) {
      invalidateRouteInput();
      setRouteStatus('');
    }
    if (inactive || !key) return;

    window.clearTimeout(routeTimer);
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
    if (!requireRouteRegion() || $('[data-field="state_code"]').selectedOptions[0]?.dataset.active === '0') return;
    dateRequest.hidden = false;

    const values = routeRegionValues();
    const key = routeRegionKey();
    const requestId = state.routeRequestId + 1;
    state.routeRequestId = requestId;
    if (routeController) routeController.abort();
    const requestController = new AbortController();
    let requestTimedOut = false;
    const requestTimeout = window.setTimeout(function () {
      requestTimedOut = true;
      requestController.abort();
    }, requestTimeoutMs);
    routeController = requestController;

    setRouteLoading(true);
    setRouteStatus('Passende Termine werden berechnet …', 'loading');
    renderSuggestionPlaceholder('Termine kommen gleich', '', 'loading');

    try {
      const response = await fetch(endpoint('recommendations'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ postal_code: values.postal, state_code: values.code }),
        signal: requestController.signal
      });
      const data = await responseData(response);
      if (!response.ok) {
        throw new Error('recommendations');
      }
      if (requestId !== state.routeRequestId || routeRegionKey() !== key) return;

      state.activeRegionKey = key;
      state.routeContext = data.route_context || null;
      state.geoEligibleDates = new Set(Array.isArray(data.eligible_dates) ? data.eligible_dates : []);
      state.eligibilityToken = typeof data.eligibility_token === 'string' ? data.eligibility_token : '';
      if (!routeScreen.hidden) {
        copyRouteRegionToForm();
        markRouteFresh();
      }
      renderSuggestions(Array.isArray(data.suggestions) ? data.suggestions : []);
      setRouteStatus('');
    } catch (error) {
      if ((error.name === 'AbortError' && !requestTimedOut) || requestId !== state.routeRequestId || routeRegionKey() !== key) return;
      state.activeRegionKey = key;
      state.geoEligibleDates = new Set();
      state.eligibilityToken = '';
      if (!routeScreen.hidden) {
        copyRouteRegionToForm();
        markRouteFresh();
      }
      renderSuggestions([]);
      setRouteStatus(requestTimedOut ? 'Die Routensuche dauert gerade zu lange.' : 'Die Routensuche ist gerade nicht verfügbar.', 'error');
    } finally {
      window.clearTimeout(requestTimeout);
      if (requestId === state.routeRequestId) {
        setRouteLoading(false);
        if (routeController === requestController) routeController = null;
      }
    }
  }

  function renderSuggestions(items) {
    const best = items.filter(function (item) {
      return item && typeof item.date === 'string' && item.date >= iso(firstSuggestedDate);
    }).slice(0, 3);
    state.recommendationByDate = new Map(best.map(function (item) { return [item.date, item]; }));
    suggestionsElement.innerHTML = '';

    if (!best.length) {
      renderSuggestionPlaceholder('Aktuell gibt es keine passenden Routenvorschläge.', '', 'warning');
      return;
    }

    best.forEach(function (item, index) {
      const article = document.createElement('article');
      article.className = 'pov-suggestion is-top-date' + (index === 0 ? ' is-best' : '');
      article.dataset.date = item.date;
      article.innerHTML =
        '<div class="pov-suggestion-copy">' +
        '<time datetime="' + escapeHtml(item.date) + '">' + escapeHtml(formatDate(item.date)) + '</time>' +
        '</div>';
      const button = document.createElement('button');
      button.type = 'button';
      button.className = index === 0 ? 'pov-button' : 'pov-secondary-button';
      button.textContent = 'Termin wählen';
      button.setAttribute('aria-label', formatDate(item.date) + ' wählen');
      button.addEventListener('click', function () {
        if (state.activeRegionKey !== routeRegionKey() || !state.eligibilityToken || !state.geoEligibleDates.has(item.date)) {
          setRouteStatus('Bitte sucht die passenden Termine erneut.', 'error');
          return;
        }
        openFormForDates(item.date, item.date, [], true);
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
    calendar.setRows(Array.from(state.days.values()));
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
      const response = await fetch(endpoint('calendar', bounds), {
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

  async function loadPickerMonth() {
    if (!pickerPanel.open || !routeRegionKey() || dateRequest.hidden) return;
    if (pickerController) pickerController.abort();
    const controller = new AbortController();
    pickerController = controller;
    const key = routeRegionKey();
    const bounds = picker.bounds();
    $('[data-role="picker-month"]').textContent = new Intl.DateTimeFormat('de-DE', { month: 'long', year: 'numeric' }).format(picker.month);
    $('[data-action="picker-prev"]').disabled = bounds.start <= iso(today);
    $('[data-action="picker-next"]').disabled = bounds.end >= iso(latestDate);
    picker.setRows([]);
    pickerElement.setAttribute('aria-busy', 'true');
    pickerStatus.textContent = 'Verfügbarkeit wird geladen …';
    const timeout = window.setTimeout(() => controller.abort(), requestTimeoutMs);
    try {
      const from = bounds.start > iso(today) ? bounds.start : iso(today);
      const to = bounds.end < iso(latestDate) ? bounds.end : iso(latestDate);
      const data = await fetchAvailability(from, to, weekdayCodes, controller.signal);
      if (!Array.isArray(data.dates)) throw new Error('availability');
      if (pickerController !== controller || key !== routeRegionKey()) return;
      const available = new Set(data.dates);
      const rows = [];
      const end = new Date(bounds.end + 'T12:00:00');
      for (let day = new Date(bounds.start + 'T12:00:00'); day <= end; day.setDate(day.getDate() + 1)) {
        const date = iso(day);
        const selectable = available.has(date);
        const weekend = day.getDay() === 0 || day.getDay() === 6;
        rows.push({
          date,
          public_state: selectable ? (weekend ? 'limited' : 'available') : 'unavailable',
          public_label: selectable ? (weekend ? 'Auf Anfrage' : 'Verfügbar') : 'Nicht verfügbar',
          is_selectable: selectable,
        });
      }
      picker.setRows(rows);
      pickerStatus.textContent = available.size ? '' : 'In diesem Monat sind keine Termine anfragbar.';
    } catch {
      if (pickerController === controller) pickerStatus.textContent = 'Verfügbarkeit konnte nicht geladen werden. Öffnet die Terminwahl erneut.';
    } finally {
      window.clearTimeout(timeout);
      if (pickerController === controller) {
        pickerController = null;
        pickerElement.setAttribute('aria-busy', 'false');
      }
    }
  }

  function renderPickerSelection() {
    const range = dateMode.value === 'range';
    const selection = $('[data-role="picker-selection"]');
    $('[data-role="preferred-weekdays"]').hidden = !range;
    picker.setSelection(pickerStart, pickerEnd);
    if (!pickerStart) selection.textContent = range ? 'Startdatum wählen' : 'Tag wählen';
    else if (range && !pickerEnd) selection.textContent = formatDate(pickerStart, 'medium') + ' · Enddatum wählen';
    else if (range && pickerStart !== pickerEnd) selection.textContent = formatDate(pickerStart, 'medium') + ' bis ' + formatDate(pickerEnd, 'medium');
    else selection.textContent = formatDate(pickerStart);
    dateNext.disabled = pickerChecking || !pickerStart || (range && !pickerEnd);
  }

  function selectPickerDay(date) {
    if (dateMode.value !== 'range' || !pickerStart || pickerEnd) {
      pickerStart = date;
      pickerEnd = '';
    } else if (date < pickerStart) {
      pickerEnd = pickerStart;
      pickerStart = date;
    } else {
      pickerEnd = date;
    }
    dateStatus.textContent = '';
    renderPickerSelection();
    pickerElement.querySelector('[data-date="' + date + '"]')?.focus({ preventScroll: true });
  }

  function datePreference() {
    const range = dateMode.value === 'range';
    return {
      range,
      from: pickerStart,
      to: range ? pickerEnd : pickerStart,
      weekdays: !range || $('[data-weekday="all"]').checked
        ? weekdayCodes : $$('[data-weekday]:checked', dateRequest).map(field => field.dataset.weekday)
    };
  }

  async function fetchAvailability(from, to, weekdays, signal) {
    const params = { state_code: routeRegionValues().code, from, to, weekdays: weekdays.join(',') };
    const response = await fetch(endpoint('availability', params), { signal });
    if (!response.ok) throw new Error('availability');
    return responseData(response);
  }

  function openFormForDates(from, to, weekdays, routeDate) {
    state.mode = routeDate ? 'specific_date' : 'date_range';
    state.selectedRecommendation = routeDate ? state.recommendationByDate.get(from) || null : null;
    state.selectedDate = routeDate ? from : '';
    state.rangeFrom = routeDate ? '' : from;
    state.rangeTo = routeDate ? '' : to;
    state.possibleWeekdays = routeDate ? [] : weekdays;
    openForm();
  }

  async function confirmDateRequest() {
    if (!requireRouteRegion() || dateRequest.hidden || dateNext.disabled) return;
    const preference = datePreference();
    const { range, from, to, weekdays } = preference;
    const span = (Date.parse(to) - Date.parse(from)) / 86400000;
    if (!from || !to || span < 0 || span >= 180 || !weekdays.length) {
      dateStatus.textContent = 'Bitte prüft Datum und Wochentage. Ein Zeitraum darf höchstens 180 Tage umfassen.';
      return;
    }

    const key = routeRegionKey();
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), requestTimeoutMs);
    pickerChecking = true;
    renderPickerSelection();
    dateStatus.textContent = 'Verfügbarkeit wird geprüft …';
    try {
      const data = await fetchAvailability(from, to, weekdays, controller.signal);
      if (routeScreen.hidden || !pickerPanel.open || key !== routeRegionKey() || JSON.stringify(preference) !== JSON.stringify(datePreference())) return;
      if (!data.available) {
        dateStatus.textContent = 'Für diese Auswahl ist kein Tag anfragbar. Bitte wählt ein anderes Datum oder andere Wochentage.';
        return;
      }
      const routeDate = !range && state.activeRegionKey === key && state.eligibilityToken && state.geoEligibleDates.has(from);
      dateStatus.textContent = '';
      openFormForDates(from, to, weekdays, routeDate);
    } catch {
      dateStatus.textContent = 'Die Verfügbarkeit ließ sich gerade nicht prüfen. Bitte erneut versuchen.';
    } finally {
      window.clearTimeout(timeout);
      pickerChecking = false;
      renderPickerSelection();
    }
  }

  function openWalkIn(date, row) {
    const dialog = $('[data-role="walk-in-dialog"]');
    const event = row && row.public_event ? row.public_event : {};
    $('[data-role="walk-in-title"]').textContent = event.title || row.public_label || 'Öffentliches Event';
    $('[data-role="walk-in-date"]').textContent = formatDate(date);
    $('[data-role="walk-in-description"]').textContent = event.description || 'Kommt gerne vorbei.';
    const location = $('[data-role="walk-in-location"]');
    location.textContent = event.location || '';
    location.hidden = !event.location;
    const link = $('[data-role="walk-in-link"]');
    link.href = event.url || '';
    link.hidden = !event.url;
    if (typeof dialog.showModal === 'function') {
      dialog.showModal();
    } else {
      dialog.setAttribute('open', 'open');
    }
  }

  function closeWalkIn() {
    const dialog = $('[data-role="walk-in-dialog"]');
    if (typeof dialog.close === 'function') dialog.close();
    else dialog.removeAttribute('open');
  }

  function selectedDateLabel() {
    if (state.mode === 'specific_date') return formatDate(state.selectedDate);
    if (state.rangeFrom && state.rangeTo) {
      if (state.rangeFrom === state.rangeTo) return formatDate(state.rangeFrom);
      return formatDate(state.rangeFrom, 'medium') + ' bis ' + formatDate(state.rangeTo, 'medium');
    }
    return 'Noch offen';
  }

  function openForm() {
    copyRouteRegionToForm();
    markRouteFresh();
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

  function toggleConditionalSection(section, visible, requiredAttribute) {
    section.hidden = !visible;
    $$('input, select, textarea', section).forEach(function (field) {
      field.disabled = !visible;
      field.required = visible && field.hasAttribute(requiredAttribute);
    });
  }

  function updateEventTypeSections() {
    const type = form.elements.institution_type.value;
    $$('[data-event-section]', form).forEach(function (section) {
      toggleConditionalSection(section, section.dataset.eventSection.split(' ').includes(type), 'data-type-required');
    });
    updateSchoolClasses();
    updateSchoolSupport();
    syncParticipantTotal();
  }

  function updateVenueSections() {
    const venue = form.elements.venue_type.value;
    $$('[data-venue-section]', form).forEach(function (section) {
      const sectionType = section.dataset.venueSection;
      const visible = venue === 'both' || venue === sectionType;
      toggleConditionalSection(section, visible, 'data-venue-required');
    });
    updateOtherFields();
  }

  function updateOtherField(name) {
    const toggle = $('[data-toggle-other="' + name + '"]', form);
    const wrapper = $('[data-other-field="' + name + '"]', form);
    if (!toggle || !wrapper) return;
    const visible = toggle.checked && !toggle.disabled;
    wrapper.hidden = !visible;
    const field = $('input, textarea', wrapper);
    if (field) field.disabled = !visible;
  }

  function updateOtherFields() {
    updateOtherField('presentation');
    updateOtherField('laptop');
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
    const participantCount = Number(form.elements.participant_total.value || 0);
    const invalidGroupSize = step === 1 && (participantCount < 1 || participantCount > 500);
    if (invalidGroupSize) {
      const participantField = $('[data-class-field="children"]:not(:disabled), [data-participant-field]:not(:disabled)', fieldset);
      if (participantField) uniqueInvalid.push(participantField);
    }
    if (!uniqueInvalid.length) return true;

    const error = document.createElement('div');
    error.className = 'pov-error';
    error.id = 'pov-step-error-' + step;
    error.setAttribute('role', 'alert');
    error.textContent = invalidGroupSize ? 'Bitte gebt insgesamt 1 bis 500 Teilnehmende an.' : 'Bitte markierte Angaben prüfen.';
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
    const availabilityLabels = { morning: 'Vormittag', afternoon: 'Nachmittag', full_day: 'Ganztägig' };
    const venueLabels = { indoor: 'Innenraum', outdoor: 'Außenbereich', both: 'Innen- und Außenbereich' };
    const venue = form.elements.venue_type.value;
    const outdoor = venue === 'outdoor' || venue === 'both';
    const onsite = [
      availabilityLabels[form.elements.availability_window.value] || 'Zeit noch offen',
      venueLabels[venue] || 'Einsatzbereich noch offen',
      form.elements.parking_type.options[form.elements.parking_type.selectedIndex]?.text || 'Stellplatz noch offen',
      form.elements.parking_location.value ? 'Stellplatz: Google-Maps-Link vorhanden' : 'Stellplatz: noch offen',
      outdoor && form.elements.electricity_outdoor_location.value
        ? 'Stromanschluss: ' + form.elements.electricity_outdoor_location.value
        : '',
      outdoor && form.elements.electricity_outdoor_distance_m.value
        ? 'Kabellänge: ' + form.elements.electricity_outdoor_distance_m.value + ' m'
        : '',
      'Ladestrom: ' + answerLabel(radioValue('electricity_charging_available')),
      'Wasser: ' + answerLabel(radioValue('water_available'))
    ].filter(Boolean).join(' · ');
    $('[data-role="summary-content"]').innerHTML =
      '<div class="pov-summary-row"><div><strong>' + escapeHtml(selectedDateLabel()) + '</strong><span>' +
      escapeHtml(form.elements.institution_name.value || 'Noch offen') + ' · ' +
      escapeHtml(form.elements.institution_type.value || 'Noch offen') + '<br>' +
      escapeHtml(form.elements.street.value + ' ' + form.elements.house_number.value) + ', ' +
      escapeHtml(form.elements.postal_code.value + ' ' + form.elements.city.value) + '<br>' +
      escapeHtml(form.elements.participant_total.value || '0') + ' Teilnehmende' +
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

  function updateSchoolClasses() {
    const container = $('[data-role="school-classes"]', form);
    const count = form.elements.institution_type.value === 'Schule'
      ? Math.max(0, Math.min(10, Number(form.elements.school_class_count.value) || 0)) : 0;
    const template = $('[data-role="school-class-template"]', form);
    while (container.children.length < count) {
      const row = template.content.firstElementChild.cloneNode(true);
      row.querySelector('legend').textContent = 'Klasse ' + (container.children.length + 1);
      container.appendChild(row);
    }
    Array.from(container.children).forEach((row, index) => toggleConditionalSection(row, index < count, 'data-type-required'));
  }

  function updateSchoolSupport() {
    toggleConditionalSection($('[data-role="school-support-details"]', form), form.elements.institution_type.value === 'Schule' &&
      form.elements.school_support.value === 'yes', 'data-type-required');
  }

  function schoolClasses() {
    return $$('.pov-school-class:not([hidden])', form).map((row, index) => {
      const value = name => Number($('[data-class-field="' + name + '"]', row).value);
      const children = value('children');
      const teachers = value('teachers');
      const adults = value('adults');
      return { class_name: 'Klasse ' + (index + 1), grade: value('grade'), children, teachers, adults, participant_count: children + teachers + adults };
    });
  }

  function participantCounts() {
    const type = form.elements.institution_type.value;
    if (type === 'Schule') {
      return schoolClasses().reduce((total, row) => ({
        children: total.children + row.children,
        adults: total.adults + row.teachers + row.adults
      }), { children: 0, adults: 0 });
    }
    if (type === 'Veranstaltung') {
      const children = Math.max(0, Number(form.elements.event_children_count.value || 0));
      form.elements.event_child_age_range.required = children > 0;
      return {
        children: children,
        adults: Math.max(0, Number(form.elements.event_adult_count.value || 0))
      };
    }
    form.elements.event_child_age_range.required = false;
    if (type === 'Sonstiges' || type === 'Unternehmen') {
      return {
        children: Math.max(0, Number(form.elements.other_children_count.value || 0)),
        adults: Math.max(0, Number(form.elements.other_adult_count.value || 0))
      };
    }
    return { children: 0, adults: 0 };
  }

  function collectPayload() {
    const data = new FormData(form);
    const counts = participantCounts();
    const type = String(data.get('institution_type') || '');
    const participantCount = Number(data.get('participant_total') || 0);
    const venue = String(data.get('venue_type') || '');
    const outdoor = venue === 'outdoor' || venue === 'both';
    const payload = {
      website: data.get('website') || '',
      form_started_at: state.startedAt,
      request_mode: state.mode,
      institution_name: data.get('institution_name') || '',
      institution_type: type,
      institution_website: data.get('institution_website') || '',
      contact_role: data.get('contact_role') || '',
      children_count: counts.children,
      adult_count: counts.adults,
      participant_total: participantCount,
      school_class_count: type === 'Schule' ? Number(data.get('school_class_count') || 0) : '',
      school_support: type === 'Schule' ? data.get('school_support') || '' : '',
      school_support_amount: data.get('school_support_amount') || '',
      school_support_note: data.get('school_support_note') || '',
      school_needs: type === 'Schule' ? data.get('school_needs') || '' : '',
      school_schedule_notes: type === 'Schule' ? data.get('school_schedule_notes') || '' : '',
      school_lesson_duration: type === 'Schule' ? data.get('school_lesson_duration') || '' : '',
      event_child_age_range: type === 'Veranstaltung' ? data.get('event_child_age_range') || '' : '',
      occasion_description: data.get('occasion_description') || '',
      availability_window: data.get('availability_window') || '',
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
      eligibility_token: state.mode === 'specific_date' ? state.eligibilityToken : '',
      specific_requested_date: state.mode === 'specific_date' ? state.selectedDate : '',
      desired_date_from: state.mode === 'date_range' ? state.rangeFrom : '',
      desired_date_to: state.mode === 'date_range' ? state.rangeTo : '',
      possible_weekdays: state.mode === 'date_range' ? state.possibleWeekdays : [],
      parking_available: '',
      parking_type: data.get('parking_type') || '',
      parking_location: data.get('parking_location') || '',
      electricity_available: data.get('electricity_charging_available') || '',
      electricity_outdoor_available: '',
      electricity_outdoor_location: outdoor ? data.get('electricity_outdoor_location') || '' : '',
      electricity_outdoor_distance_m: outdoor ? data.get('electricity_outdoor_distance_m') || '' : '',
      electricity_charging_available: data.get('electricity_charging_available') || '',
      water_available: data.get('water_available') || '',
      changing_room_available: data.get('changing_room_available') || '',
      shower_available: data.get('shower_available') || '',
      natural_water_nearby: data.get('natural_water_nearby') || '',
      natural_water_location: data.get('natural_water_location') || '',
      presentation_equipment: data.getAll('presentation_equipment[]'),
      presentation_equipment_other: data.getAll('presentation_equipment[]').includes('other') ? data.get('presentation_equipment_other') || '' : '',
      laptop_connections: data.getAll('laptop_connections[]'),
      laptop_connection_other: data.getAll('laptop_connections[]').includes('other') ? data.get('laptop_connection_other') || '' : '',
      wifi_available: data.get('wifi_available') || '',
      venue_type: venue,
      indoor_room_available: venue === 'indoor' || venue === 'both' ? 'yes' : 'no',
      indoor_room_description: venue === 'indoor' || venue === 'both' ? data.get('indoor_room_description') || '' : '',
      outdoor_area_description: outdoor ? data.get('outdoor_area_description') || '' : '',
      bad_weather_option_available: outdoor ? radioValue('bad_weather_option_available') : 'yes',
      accessibility_notes: type === 'Schule' ? data.get('school_needs') || '' : '',
      group_notes: type === 'Schule' ? data.get('school_needs') || '' : (type === 'Sonstiges' ? data.get('occasion_description') || '' : ''),
      general_notes: data.get('general_notes') || '',
      classes: type === 'Schule' ? schoolClasses() : [{ class_name: type || 'Gruppe', grade: 1, participant_count: participantCount }]
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
      const response = await fetch(endpoint('requests'), {
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
    if (action === 'picker-prev' || action === 'picker-next') {
      const offset = action === 'picker-prev' ? -1 : 1;
      picker.setMonth(new Date(picker.month.getFullYear(), picker.month.getMonth() + offset, 1));
      loadPickerMonth();
    }
    if (action === 'check-route') checkRoute();
    if (action === 'close-walk-in') closeWalkIn();
    if (action === 'confirm-date') confirmDateRequest();
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
      const firstQuestion = $('select[name="parking_type"]', form);
      if (firstQuestion) firstQuestion.focus();
    }
  });

  pickerPanel.addEventListener('toggle', function () {
    if (pickerPanel.open) loadPickerMonth();
    else {
      if (pickerController) pickerController.abort();
      pickerController = null;
      pickerElement.setAttribute('aria-busy', 'false');
    }
  });
  dateMode.addEventListener('change', function () {
    pickerEnd = '';
    dateStatus.textContent = '';
    renderPickerSelection();
  });
  $$('[data-weekday]', dateRequest).forEach(function (field) {
    field.addEventListener('change', function () {
      if (field.checked) {
        const others = field.dataset.weekday === 'all' ? $$('[data-weekday]:not([data-weekday="all"])', dateRequest) : [$('[data-weekday="all"]')];
        others.forEach(other => { other.checked = false; });
      }
      dateStatus.textContent = '';
    });
  });

  $('[data-field="postal_code"]').addEventListener('input', handleRouteRegionChange);
  $('[data-field="state_code"]').addEventListener('change', handleRouteRegionChange);
  form.elements.postal_code.addEventListener('input', trackFormRegionChange);
  form.elements.state_code.addEventListener('change', trackFormRegionChange);
  function syncParticipantTotal() {
    const counts = participantCounts();
    form.elements.participant_total.value = String(counts.children + counts.adults);
  }
  form.elements.institution_type.addEventListener('change', updateEventTypeSections);
  form.elements.school_support.addEventListener('change', updateSchoolSupport);
  form.addEventListener('input', function (event) {
    if (event.target === form.elements.school_class_count) updateSchoolClasses();
    if (event.target.matches('[data-class-field], [data-participant-field], [name="school_class_count"]')) syncParticipantTotal();
  });
  form.elements.venue_type.addEventListener('change', updateVenueSections);
  $$('[data-toggle-other]', form).forEach(function (field) {
    field.addEventListener('change', updateOtherFields);
  });
  $$('input[name="natural_water_nearby"]', form).forEach(function (field) {
    field.addEventListener('change', function () {
      const wrapper = $('[data-natural-water-location]', form);
      const visible = radioValue('natural_water_nearby') === 'yes';
      wrapper.hidden = !visible;
      wrapper.querySelector('input').disabled = !visible;
    });
  });
  ['street', 'house_number', 'city'].forEach(function (name) {
    form.elements[name].addEventListener('change', trackAddressChange);
  });
  form.addEventListener('input', function () {
    if (state.step === 2) renderSummary();
  });
  form.addEventListener('change', function () {
    if (state.step === 2) renderSummary();
  });
  form.addEventListener('submit', submitForm);

  updateEventTypeSections();
  updateVenueSections();
  updateOtherFields();
  renderPickerSelection();
  loadCalendar();
})();
