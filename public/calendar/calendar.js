(() => {
  'use strict';

  const $ = (selector) => document.querySelector(selector);
  const state = { cursor: startOfMonth(new Date()), events: [], view: 'month', loading: false, selectedDay: null };
  const thaiMonth = new Intl.DateTimeFormat('th-TH', { month: 'long', year: 'numeric' });
  const thaiDay = new Intl.DateTimeFormat('th-TH', { weekday: 'short' });
  const thaiFullDate = new Intl.DateTimeFormat('th-TH', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  const statusLabels = {
    planned: 'วางแผน',
    in_progress: 'กำลังทำ',
    done: 'เสร็จแล้ว',
    cancelled: 'ยกเลิก'
  };

  function startOfMonth(date) { return new Date(date.getFullYear(), date.getMonth(), 1); }
  function pad(value) { return String(value).padStart(2, '0'); }
  function localDateTime(date) {
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
  }
  function dateKey(date) { return localDateTime(date).slice(0, 10); }
  function parseServerDate(value) {
    if (value instanceof Date) return value;
    return new Date(String(value).replace(' ', 'T').replace(/Z$/, ''));
  }
  function escapeHtml(value) {
    const node = document.createElement('span');
    node.textContent = value || '';
    return node.innerHTML;
  }

  async function loadEvents() {
    state.loading = true;
    $('#loadingState').hidden = false;
    $('#errorState').hidden = true;
    $('#monthView').hidden = true;
    $('#agendaView').hidden = true;
    const gridStart = new Date(state.cursor.getFullYear(), state.cursor.getMonth(), 1 - state.cursor.getDay());
    const gridEnd = new Date(gridStart);
    gridEnd.setDate(gridEnd.getDate() + 42);
    try {
      const params = new URLSearchParams({ start: localDateTime(gridStart), end: localDateTime(gridEnd) });
      const response = await fetch(`/calendar/api/events?${params}`);
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || 'โหลดข้อมูลไม่สำเร็จ');
      state.events = data.events;
      render();
    } catch (error) {
      $('#errorState').textContent = error.message;
      $('#errorState').hidden = false;
    } finally {
      state.loading = false;
      $('#loadingState').hidden = true;
    }
  }

  function eventsOnDay(date) {
    const start = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    const end = new Date(start); end.setDate(end.getDate() + 1);
    return state.events.filter((event) => parseServerDate(event.start_at) < end && parseServerDate(event.end_at) >= start);
  }

  function render() {
    $('#periodTitle').textContent = thaiMonth.format(state.cursor);
    document.querySelectorAll('[data-view]').forEach((button) => button.classList.toggle('active', button.dataset.view === state.view));
    $('#monthView').hidden = state.view !== 'month';
    $('#agendaView').hidden = state.view !== 'agenda';
    if (state.view === 'month') renderMonth();
    else renderAgenda();
    renderSummary();
  }

  function renderMonth() {
    const grid = $('#monthGrid');
    grid.replaceChildren();
    const first = new Date(state.cursor.getFullYear(), state.cursor.getMonth(), 1 - state.cursor.getDay());
    const today = dateKey(new Date());
    for (let index = 0; index < 42; index += 1) {
      const date = new Date(first); date.setDate(first.getDate() + index);
      const events = eventsOnDay(date);
      const cell = document.createElement('div');
      cell.className = 'day-cell';
      if (date.getMonth() !== state.cursor.getMonth()) cell.classList.add('outside');
      if (dateKey(date) === today) cell.classList.add('today');
      cell.innerHTML = `<span class="day-number">${date.getDate()}</span>`;
      cell.addEventListener('click', () => openDayDetails(date));
      events.slice(0, 3).forEach((event) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `event-chip ${event.status === 'done' ? 'done' : ''}`;
        button.style.setProperty('--event-color', event.color);
        const time = parseServerDate(event.start_at);
        button.innerHTML = `<time>${event.all_day ? '' : `${pad(time.getHours())}:${pad(time.getMinutes())}`}</time><span>${escapeHtml(event.title)}</span>`;
        button.addEventListener('click', (clickEvent) => {
          clickEvent.stopPropagation();
          openDayDetails(date);
        });
        cell.appendChild(button);
      });
      if (events.length > 3) {
        const more = document.createElement('div');
        more.className = 'more-label';
        more.textContent = `อีก ${events.length - 3} งาน`;
        cell.appendChild(more);
      }
      grid.appendChild(cell);
    }
  }

  function renderAgenda() {
    const root = $('#agendaView');
    root.replaceChildren();
    const monthEvents = state.events.filter((event) => parseServerDate(event.start_at).getMonth() === state.cursor.getMonth());
    if (!monthEvents.length) {
      root.innerHTML = '<div class="empty-agenda">เดือนนี้ยังไม่มีงาน</div>';
      return;
    }
    const groups = new Map();
    monthEvents.forEach((event) => {
      const key = dateKey(parseServerDate(event.start_at));
      if (!groups.has(key)) groups.set(key, []);
      groups.get(key).push(event);
    });
    groups.forEach((events, key) => {
      const date = new Date(`${key}T00:00:00`);
      const row = document.createElement('div');
      row.className = 'agenda-day';
      row.innerHTML = `<div class="agenda-date"><strong>${date.getDate()}</strong><span>${thaiDay.format(date)}</span></div><div class="agenda-events"></div>`;
      events.forEach((event) => {
        const start = parseServerDate(event.start_at);
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'agenda-event';
        button.style.setProperty('--event-color', event.color);
        button.innerHTML = `<span class="agenda-bar"></span><span><strong>${escapeHtml(event.title)}</strong><small>${event.all_day ? 'ตลอดวัน' : `${pad(start.getHours())}:${pad(start.getMinutes())} น.`}${event.location ? ` · ${escapeHtml(event.location)}` : ''}</small></span>`;
        button.addEventListener('click', () => openEdit(event));
        row.querySelector('.agenda-events').appendChild(button);
      });
      root.appendChild(row);
    });
  }

  function renderSummary() {
    const visible = state.events.filter((event) => parseServerDate(event.start_at).getMonth() === state.cursor.getMonth());
    $('#plannedCount').textContent = visible.filter((event) => event.status === 'planned').length;
    $('#progressCount').textContent = visible.filter((event) => event.status === 'in_progress').length;
    $('#doneCount').textContent = visible.filter((event) => event.status === 'done').length;
  }

  function resetForm() {
    $('#eventForm').reset();
    $('#eventId').value = '';
    $('#formError').hidden = true;
    $('#verificationInput').value = '';
    document.querySelector('input[name="color"][value="#2563eb"]').checked = true;
  }

  function openDayDetails(date) {
    state.selectedDay = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    const events = eventsOnDay(state.selectedDay);
    const list = $('#dayEventList');
    $('#dayDialogTitle').textContent = `วันที่ ${state.selectedDay.getDate()}`;
    $('#dayDialogSubtitle').textContent = thaiFullDate.format(state.selectedDay);
    list.replaceChildren();

    if (!events.length) {
      list.innerHTML = `
        <div class="day-empty-state">
          <span aria-hidden="true">☼</span>
          <strong>วันนี้ยังไม่มีงาน</strong>
          <small>คุณสามารถเพิ่มงานใหม่สำหรับวันนี้ได้</small>
        </div>`;
    } else {
      events.forEach((event) => {
        const start = parseServerDate(event.start_at);
        const end = parseServerDate(event.end_at);
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'day-detail-event';
        item.style.setProperty('--event-color', event.color);
        const timeLabel = event.all_day
          ? 'ตลอดวัน'
          : `${pad(start.getHours())}:${pad(start.getMinutes())}–${pad(end.getHours())}:${pad(end.getMinutes())} น.`;
        item.innerHTML = `
          <span class="day-detail-bar"></span>
          <span class="day-detail-body">
            <span class="day-detail-heading">
              <strong>${escapeHtml(event.title)}</strong>
              <em class="status-badge status-${event.status}">${statusLabels[event.status] || 'วางแผน'}</em>
            </span>
            <small class="day-detail-meta">เวลา ${timeLabel}${event.location ? ` · ${escapeHtml(event.location)}` : ''}</small>
            ${event.description ? `<span class="day-detail-description">${escapeHtml(event.description)}</span>` : ''}
            <small class="day-detail-edit">แตะเพื่อดูหรือแก้ไขงาน →</small>
          </span>`;
        item.addEventListener('click', () => {
          $('#dayDialog').close();
          openEdit(event);
        });
        list.appendChild(item);
      });
    }

    $('#dayDialog').showModal();
  }

  function openCreate(date = new Date()) {
    resetForm();
    const now = new Date();
    const isToday = dateKey(date) === dateKey(now);
    const startHour = isToday ? Math.min(now.getHours() + 1, 23) : 9;
    const start = new Date(date.getFullYear(), date.getMonth(), date.getDate(), startHour, 0);
    const end = new Date(start);
    if (startHour === 23) end.setMinutes(59);
    else end.setHours(startHour + 1);
    $('#startInput').value = localDateTime(start);
    $('#endInput').value = localDateTime(end);
    $('#dialogEyebrow').textContent = 'NEW TASK';
    $('#dialogTitle').textContent = 'เพิ่มงานใหม่';
    $('#deleteButton').hidden = true;
    $('#eventDialog').showModal();
    $('#titleInput').focus();
  }

  function openEdit(event) {
    resetForm();
    $('#eventId').value = event.id;
    $('#titleInput').value = event.title;
    $('#descriptionInput').value = event.description || '';
    $('#locationInput').value = event.location || '';
    $('#startInput').value = localDateTime(parseServerDate(event.start_at));
    $('#endInput').value = localDateTime(parseServerDate(event.end_at));
    $('#allDayInput').checked = Boolean(event.all_day);
    $('#statusInput').value = event.status;
    const color = document.querySelector(`input[name="color"][value="${event.color}"]`);
    if (color) color.checked = true;
    $('#dialogEyebrow').textContent = 'EDIT TASK';
    $('#dialogTitle').textContent = 'แก้ไขงาน';
    $('#deleteButton').hidden = false;
    $('#eventDialog').showModal();
  }

  function formPayload() {
    return {
      title: $('#titleInput').value,
      description: $('#descriptionInput').value,
      location: $('#locationInput').value,
      startAt: $('#startInput').value,
      endAt: $('#endInput').value,
      allDay: $('#allDayInput').checked,
      status: $('#statusInput').value,
      color: document.querySelector('input[name="color"]:checked').value,
      verificationCode: $('#verificationInput').value
    };
  }

  function keepEndAfterStart() {
    const startValue = $('#startInput').value;
    if (!startValue) return;

    const start = new Date(startValue);
    const currentEnd = $('#endInput').value ? new Date($('#endInput').value) : null;
    if (Number.isNaN(start.getTime())) return;

    // When a future start is selected, move an empty or now-invalid end time
    // forward automatically instead of making the user correct both fields.
    if (!currentEnd || Number.isNaN(currentEnd.getTime()) || currentEnd < start) {
      const suggestedEnd = new Date(start);
      suggestedEnd.setHours(suggestedEnd.getHours() + 1);
      $('#endInput').value = localDateTime(suggestedEnd);
    }
  }

  async function mutate(url, method, payload) {
    const response = await fetch(url, {
      method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || 'ดำเนินการไม่สำเร็จ');
    return data;
  }

  $('#eventForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const id = $('#eventId').value;
    const button = $('#saveButton');
    button.disabled = true;
    $('#formError').hidden = true;
    try {
      await mutate(id ? `/calendar/api/events/${id}` : '/calendar/api/events', id ? 'PUT' : 'POST', formPayload());
      $('#eventDialog').close();
      showToast(id ? 'แก้ไขงานเรียบร้อยแล้ว' : 'เพิ่มงานเรียบร้อยแล้ว');
      await loadEvents();
    } catch (error) {
      $('#formError').textContent = error.message;
      $('#formError').hidden = false;
      $('#verificationInput').select();
    } finally {
      button.disabled = false;
    }
  });

  $('#deleteButton').addEventListener('click', async () => {
    const id = $('#eventId').value;
    if (!id || !window.confirm('ต้องการลบงานนี้ใช่หรือไม่? การลบไม่สามารถย้อนกลับได้')) return;
    $('#deleteButton').disabled = true;
    $('#formError').hidden = true;
    try {
      await mutate(`/calendar/api/events/${id}`, 'DELETE', { verificationCode: $('#verificationInput').value });
      $('#eventDialog').close();
      showToast('ลบงานเรียบร้อยแล้ว');
      await loadEvents();
    } catch (error) {
      $('#formError').textContent = error.message;
      $('#formError').hidden = false;
    } finally {
      $('#deleteButton').disabled = false;
    }
  });

  function showToast(message) {
    const toast = $('#toast');
    toast.textContent = message;
    toast.classList.add('show');
    window.setTimeout(() => toast.classList.remove('show'), 2600);
  }

  $('#addEventButton').addEventListener('click', () => openCreate());
  $('#startInput').addEventListener('change', keepEndAfterStart);
  $('#closeDayDialogButton').addEventListener('click', () => $('#dayDialog').close());
  $('#closeDayButton').addEventListener('click', () => $('#dayDialog').close());
  $('#addEventOnDayButton').addEventListener('click', () => {
    const selectedDay = state.selectedDay || new Date();
    $('#dayDialog').close();
    openCreate(selectedDay);
  });
  $('#closeDialogButton').addEventListener('click', () => $('#eventDialog').close());
  $('#cancelButton').addEventListener('click', () => $('#eventDialog').close());
  $('#previousButton').addEventListener('click', () => { state.cursor.setMonth(state.cursor.getMonth() - 1); state.cursor = startOfMonth(state.cursor); loadEvents(); });
  $('#nextButton').addEventListener('click', () => { state.cursor.setMonth(state.cursor.getMonth() + 1); state.cursor = startOfMonth(state.cursor); loadEvents(); });
  $('#todayButton').addEventListener('click', () => { state.cursor = startOfMonth(new Date()); loadEvents(); });
  document.querySelectorAll('[data-view]').forEach((button) => button.addEventListener('click', () => { state.view = button.dataset.view; render(); }));
  $('#eventDialog').addEventListener('click', (event) => {
    if (event.target === $('#eventDialog')) $('#eventDialog').close();
  });
  $('#dayDialog').addEventListener('click', (event) => {
    if (event.target === $('#dayDialog')) $('#dayDialog').close();
  });

  loadEvents();
})();
