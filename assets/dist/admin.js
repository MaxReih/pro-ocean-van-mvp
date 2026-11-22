(function () {
  function syncDateRange(form) {
    const start = form.querySelector('[data-date-start]');
    const end = form.querySelector('[data-date-end]');
    if (!start || !end) return;

    const update = () => {
      if (start.value) {
        end.min = start.value;
        if (end.value && end.value < start.value) {
          end.value = start.value;
        }
      } else {
        end.removeAttribute('min');
      }
    };

    start.addEventListener('change', update);
    end.addEventListener('change', update);
    update();
  }

  function bindCalendarDays() {
    const form = document.querySelector('[data-pov-calendar-form]');
    if (!form) return;

    document.querySelectorAll('[data-pov-calendar-day]').forEach((day) => {
      day.addEventListener('click', () => {
        form.querySelector('[name="calendar_date_from"]').value = day.dataset.date || '';
        form.querySelector('[name="calendar_date_to"]').value = day.dataset.date || '';
        form.querySelector('[name="availability_state"]').value = day.dataset.state || 'available';
        form.querySelector('[name="availability_state"]').dispatchEvent(new Event('change', { bubbles: true }));
        form.querySelector('[name="public_note"]').value = day.dataset.publicNote || '';
        form.querySelector('[name="public_title"]').value = day.dataset.publicTitle || '';
        form.querySelector('[name="public_description"]').value = day.dataset.publicDescription || '';
        form.querySelector('[name="public_location"]').value = day.dataset.publicLocation || '';
        form.querySelector('[name="public_url"]').value = day.dataset.publicUrl || '';
        form.querySelector('[name="public_event_group"]').value = day.dataset.publicEventGroup || '';
        form.querySelector('[name="walk_in_children"]').value = day.dataset.walkInChildren || '';
        form.querySelector('[name="walk_in_adults"]').value = day.dataset.walkInAdults || '';
        form.querySelector('[name="internal_note"]').value = day.dataset.internalNote || '';
        form.querySelector('[name="custom_start_label"]').value = day.dataset.startLabel || '';
        form.querySelector('[name="custom_start_latitude"]').value = day.dataset.startLat || '';
        form.querySelector('[name="custom_start_longitude"]').value = day.dataset.startLon || '';
        form.querySelector('[name="calendar_date_to"]').min = day.dataset.date || '';
        form.dataset.loadedDate = day.dataset.date || '';
        const exportSelect = document.querySelector('[data-pov-calendar-export-select]');
        if (exportSelect) {
          const matchingOption = Array.from(exportSelect.options).find((option) => option.dataset.date === day.dataset.date);
          if (matchingOption) {
            exportSelect.value = matchingOption.value;
            exportSelect.dispatchEvent(new Event('change', { bubbles: true }));
          }
        }
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });

    const start = form.querySelector('[name="calendar_date_from"]');
    const eventGroup = form.querySelector('[name="public_event_group"]');
    if (start && eventGroup) {
      start.addEventListener('input', () => {
        if (form.dataset.loadedDate && start.value !== form.dataset.loadedDate) {
          eventGroup.value = '';
          delete form.dataset.loadedDate;
        }
      });
    }

    const state = form.querySelector('[data-pov-calendar-state]');
    const walkInFields = form.querySelector('[data-pov-walk-in-fields]');
    if (state && walkInFields) {
      const updateWalkInFields = () => {
        const visible = state.value === 'walk_in';
        walkInFields.hidden = !visible;
        walkInFields.querySelectorAll('input, textarea').forEach((field) => {
          field.required = visible && field.name === 'public_title';
        });
      };
      state.addEventListener('change', updateWalkInFields);
      form.addEventListener('click', () => window.setTimeout(updateWalkInFields, 0));
      updateWalkInFields();
    }
  }

  function bindCalendarExport() {
    const select = document.querySelector('[data-pov-calendar-export-select]');
    const google = document.querySelector('[data-pov-calendar-export-google]');
    const ics = document.querySelector('[data-pov-calendar-export-ics]');
    if (!select || !google || !ics) return;

    const update = () => {
      const option = select.options[select.selectedIndex];
      if (!option) return;
      google.href = option.dataset.google || '#';
      ics.href = option.dataset.ics || '#';
    };

    select.addEventListener('change', update);
    update();
  }

  function bindResponseForm(form) {
    const dateArea = form.querySelector('[data-pov-response-date]');
    const mode = form.querySelector('[data-pov-schedule-mode]');
    const days = form.querySelector('[data-pov-schedule-days]');
    const rows = form.querySelector('[data-pov-schedule-rows]');
    const range = form.querySelector('[data-pov-schedule-range]');
    const start = range?.querySelector('[name="date_from"]');
    const end = range?.querySelector('[name="date_to"]');
    const addDay = form.querySelector('[data-pov-add-day]');
    const dayTemplate = form.querySelector('[data-pov-day-template]');
    const override = form.querySelector('[name="override_request_dates"]');
    const choices = form.querySelectorAll('[name="response_type"]');
    const message = form.querySelector('[name="message"]');
    if (!dateArea || !mode || !days || !rows || !start || !end || !choices.length) return;
    let automaticMessage = message ? message.value : '';

    const update = () => {
      const selected = form.querySelector('[name="response_type"]:checked');
      const accepts = selected?.value === 'accept';
      dateArea.hidden = !accepts;
      mode.disabled = !accepts;
      days.hidden = !accepts || mode.value !== 'days';
      range.hidden = !accepts || mode.value !== 'range';
      if (override) {
        override.disabled = days.hidden && range.hidden;
        override.closest('label').hidden = override.disabled;
      }
      const setBounds = (input) => {
        input.min = override?.checked ? dateArea.dataset.today : dateArea.dataset.requestMin;
        input.max = override?.checked ? '' : dateArea.dataset.requestMax;
      };
      rows.querySelectorAll('input').forEach((input) => {
        setBounds(input);
        input.disabled = days.hidden;
        input.required = !days.hidden;
      });
      [start, end].forEach((input) => {
        setBounds(input);
        input.disabled = range.hidden;
        input.required = !range.hidden;
      });
      if (start.value > end.min) end.min = start.value;
      if (addDay) addDay.disabled = rows.children.length >= 180;
      if (selected && message) {
        const template = form.dataset[`template${selected.value.charAt(0).toUpperCase()}${selected.value.slice(1)}`] || '';
        if (!message.value.trim() || message.value === automaticMessage) {
          message.value = template;
          automaticMessage = template;
        }
      }
    };

    choices.forEach((choice) => choice.addEventListener('change', update));
    mode.addEventListener('change', update);
    override?.addEventListener('change', update);
    start.addEventListener('change', update);
    addDay?.addEventListener('click', () => {
      if (!dayTemplate || rows.children.length >= 180) return;
      rows.append(dayTemplate.content.cloneNode(true));
      update();
      rows.lastElementChild.querySelector('input').focus();
    });
    rows.addEventListener('click', (event) => {
      const remove = event.target.closest('[data-pov-remove-day]');
      if (!remove) return;
      remove.closest('.pov-schedule-row').remove();
      update();
      addDay?.focus();
    });
    form.querySelectorAll('[data-pov-response-date-value]').forEach((button) => {
      button.addEventListener('click', () => {
        const dateInput = rows.querySelector('input');
        dateInput.value = button.dataset.povResponseDateValue || '';
        dateInput.focus();
      });
    });
    update();
  }

  function bindManualAppointment(form) {
    const visibility = form.querySelector('[name="manual_visibility"]');
    const publicFields = form.querySelector('[data-pov-manual-public]');
    if (!visibility || !publicFields) return;
    const update = () => { publicFields.hidden = visibility.value !== 'public'; };
    visibility.addEventListener('change', update);
    update();
  }

  function bindProviderSettings(form) {
    const routing = form.querySelector('[data-pov-provider-select="routing"]');
    const geocoding = form.querySelector('[data-pov-provider-select="geocoding"]');
    if (!routing || !geocoding) return;

    const update = () => {
      form.querySelectorAll('[data-pov-provider-field]').forEach((field) => {
        const rule = field.dataset.povProviderField || '';
        const visible = rule === 'heigit'
          ? routing.value === 'heigit' || geocoding.value === 'heigit'
          : rule === `routing:${routing.value}` || rule === `geocoding:${geocoding.value}`;
        field.hidden = !visible;
      });
    };
    routing.addEventListener('change', update);
    geocoding.addEventListener('change', update);
    update();
  }

  document.querySelectorAll('[data-pov-calendar-form]').forEach(syncDateRange);
  document.querySelectorAll('[data-pov-response-form]').forEach(bindResponseForm);
  document.querySelectorAll('[data-pov-settings-form]').forEach(bindProviderSettings);
  document.querySelectorAll('.pov-manual-appointment form').forEach(bindManualAppointment);
  document.querySelectorAll('[data-pov-open-details]').forEach((button) => {
    button.addEventListener('click', () => {
      const details = document.querySelector(button.dataset.povOpenDetails || '');
      if (!details) return;
      details.open = true;
      details.scrollIntoView({ behavior: 'smooth', block: 'start' });
      const first = details.querySelector('input, select, textarea');
      if (first) window.setTimeout(() => first.focus(), 350);
    });
  });
  bindCalendarExport();
  bindCalendarDays();
})();
