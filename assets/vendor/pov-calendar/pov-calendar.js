(function () {
  class POVCalendar {
    constructor(element, options) {
      this.element = element;
      this.options = options || {};
      this.month = this.options.initialMonth || new Date(new Date().getFullYear(), new Date().getMonth(), 1);
      this.rows = new Map();
      this.recommended = new Set();
      this.selectionStart = '';
      this.selectionEnd = '';
    }

    setMonth(month) {
      this.month = month;
      this.render();
    }

    setRows(rows) {
      this.rows = new Map((rows || []).map((row) => [row.date, row]));
      this.render();
    }

    setRecommended(dates) {
      this.recommended = new Set(dates || []);
      this.render();
    }

    setSelected(date) {
      this.setSelection(date, date);
    }

    setSelection(start, end) {
      this.selectionStart = start || '';
      this.selectionEnd = end || this.selectionStart;
      this.render();
    }

    iso(date) {
      const y = date.getFullYear();
      const m = String(date.getMonth() + 1).padStart(2, '0');
      const d = String(date.getDate()).padStart(2, '0');
      return `${y}-${m}-${d}`;
    }

    bounds() {
      const y = this.month.getFullYear();
      const m = this.month.getMonth();
      return {
        start: this.iso(new Date(y, m, 1)),
        end: this.iso(new Date(y, m + 1, 0))
      };
    }

    render() {
      if (!this.element) return;
      this.element.innerHTML = '';
      ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'].forEach((day) => {
        const el = document.createElement('div');
        el.className = 'pov-cal-head';
        el.textContent = day;
        el.setAttribute('aria-hidden', 'true');
        this.element.appendChild(el);
      });

      const y = this.month.getFullYear();
      const m = this.month.getMonth();
      const offset = (new Date(y, m, 1).getDay() + 6) % 7;
      for (let i = 0; i < offset; i++) {
        const spacer = document.createElement('span');
        spacer.className = 'pov-cal-spacer';
        this.element.appendChild(spacer);
      }

      const daysInMonth = new Date(y, m + 1, 0).getDate();
      for (let d = 1; d <= daysInMonth; d++) {
        const date = this.iso(new Date(y, m, d));
        const row = this.rows.get(date) || {
          public_state: 'unavailable',
          public_label: 'Nicht geladen',
          is_selectable: false,
          is_weekend: false
        };
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'pov-day';
        button.dataset.date = date;
        button.dataset.state = row.public_state;
        button.dataset.weekend = row.is_weekend ? 'true' : 'false';
        button.dataset.requestable = row.is_requestable ? 'true' : 'false';
        button.dataset.recommended = this.recommended.has(date) ? 'true' : 'false';
        button.disabled = !row.is_selectable && !row.is_interactive && !row.is_requestable;
        const readableDate = new Intl.DateTimeFormat('de-DE', {
          weekday: 'long',
          day: 'numeric',
          month: 'long',
          year: 'numeric'
        }).format(new Date(`${date}T12:00:00`));
        const recommended = this.recommended.has(date) ? ', Routenfavorit' : '';
        const inSelection = this.selectionStart && date >= this.selectionStart && date <= this.selectionEnd;
        let selection = '';
        if (inSelection && this.selectionStart === this.selectionEnd) selection = 'single';
        else if (inSelection && date === this.selectionStart) selection = 'start';
        else if (inSelection && date === this.selectionEnd) selection = 'end';
        else if (inSelection) selection = 'range';
        if (selection) button.dataset.selection = selection;
        const selectedLabel = selection ? ', ausgewählt' : '';
        button.setAttribute('aria-label', `${readableDate}: ${row.public_label}${recommended}${selectedLabel}`);
        button.setAttribute('aria-pressed', inSelection ? 'true' : 'false');
        const publicHint = ['tour', 'walk_in'].includes(row.public_state)
          ? `<small>${POVCalendar.escape(row.public_label)}</small>`
          : '';
        button.innerHTML = `<strong>${d}</strong>${publicHint}`;
        button.addEventListener('click', () => {
          if (row.is_interactive && this.options.onDetails) {
            this.options.onDetails(date, row);
            return;
          }
          if (row.is_requestable && this.options.onRequest) {
            this.options.onRequest(date, row);
            return;
          }
          if (row.is_selectable && this.options.onSelect) this.options.onSelect(date, row);
        });
        this.element.appendChild(button);
      }
    }

    static escape(value) {
      return String(value).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
    }
  }

  window.POVCalendar = POVCalendar;
})();
