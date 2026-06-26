import { mountClockPicker } from './clock-picker.js';

function t(key, params = {}) {
    const dict = window.__I18N__ || {};
    let str = dict[key] || key;
    Object.entries(params).forEach(([k, v]) => {
        str = str.replace(`:${k}`, String(v));
    });
    return str;
}

const page = document.getElementById('attendance-page');
const dayModal = document.getElementById('attendance-day-modal');
const holidayModal = document.getElementById('attendance-holiday-modal');
const dayForm = document.getElementById('attendance-day-form');
const holidayForm = document.getElementById('attendance-holiday-form');
const dayTypeSelect = document.getElementById('attendance-day-type');
const dayTimesWrap = document.getElementById('attendance-day-times');
const holidayNote = document.getElementById('attendance-day-holiday-note');
const timeError = document.getElementById('attendance-day-time-error');
const timeFieldNames = ['morning_start', 'morning_end', 'afternoon_start', 'afternoon_end'];
let activeTimeField = 'morning_start';
let sharedClockPicker = null;

function fieldToInputId(field) {
    return `attendance-${field.replace(/_/g, '-')}`;
}

function normalizeTime24(value) {
    if (value === null || value === undefined) {
        return '';
    }

    const trimmed = String(value).trim();
    if (trimmed === '') {
        return '';
    }

    const match = trimmed.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
    if (!match) {
        return null;
    }

    const hours = Number.parseInt(match[1], 10);
    const minutes = Number.parseInt(match[2], 10);

    if (hours > 23 || minutes > 59) {
        return null;
    }

    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
}

function showTimeError(message) {
    if (!timeError) {
        return;
    }

    timeError.textContent = message;
    timeError.classList.remove('is-hidden');
}

function clearTimeError() {
    if (!timeError) {
        return;
    }

    timeError.textContent = '';
    timeError.classList.add('is-hidden');
}

function setTimeFieldValue(field, value) {
    const normalized = normalizeTime24(value);
    const hidden = document.getElementById(fieldToInputId(field));
    const trigger = document.querySelector(`.attendance-time-trigger[data-field="${field}"]`);

    if (hidden) {
        hidden.value = normalized || '';
    }

    if (trigger) {
        const valueEl = trigger.querySelector('.attendance-time-trigger__value');
        if (valueEl) {
            valueEl.textContent = normalized || '—';
        }
    }

    if (field === activeTimeField && sharedClockPicker && normalized) {
        sharedClockPicker.setValue(normalized);
    }
}

function initSharedClock() {
    const mount = document.getElementById('clock-shared');
    if (!mount || sharedClockPicker) {
        return;
    }

    const initial = document.getElementById(fieldToInputId(activeTimeField))?.value || '08:00';
    sharedClockPicker = mountClockPicker(mount, {
        value: initial,
        onChange: (value) => {
            setTimeFieldValue(activeTimeField, value);
            clearTimeError();
        },
    });

    document.querySelectorAll('.attendance-time-trigger').forEach((trigger) => {
        if (trigger.dataset.bound === '1') {
            return;
        }
        trigger.dataset.bound = '1';
        trigger.addEventListener('click', () => {
            const field = trigger.dataset.field;
            if (!field) {
                return;
            }

            activeTimeField = field;
            document.querySelectorAll('.attendance-time-trigger').forEach((btn) => {
                btn.classList.toggle('is-active', btn === trigger);
            });

            const current = document.getElementById(fieldToInputId(field))?.value || '08:00';
            sharedClockPicker?.setValue(current);
        });
    });
}

function openModal(modal) {
    if (!modal) {
        return;
    }
    modal.classList.remove('is-hidden');
    modal.setAttribute('aria-hidden', 'false');
}

function closeModals() {
    [dayModal, holidayModal].forEach((modal) => {
        if (!modal) {
            return;
        }
        modal.classList.add('is-hidden');
        modal.setAttribute('aria-hidden', 'true');
    });
}

document.querySelectorAll('[data-attendance-close]').forEach((el) => {
    el.addEventListener('click', closeModals);
});

function syncDayTypeFields() {
    const isWork = dayTypeSelect?.value === 'work';
    if (!dayTimesWrap) {
        return;
    }
    dayTimesWrap.classList.toggle('is-hidden', !isWork);
    dayTimesWrap.querySelectorAll('input, button').forEach((el) => {
        el.disabled = !isWork;
    });
}

dayTypeSelect?.addEventListener('change', syncDayTypeFields);

document.getElementById('attendance-weeks')?.addEventListener('click', (event) => {
    const cell = event.target.closest('.attendance-cell[data-date]');
    if (!cell) {
        return;
    }

    const date = cell.dataset.date;
    document.getElementById('attendance-day-date').value = date;
    document.getElementById('attendance-day-modal-date').textContent = formatDateLabel(date);

    const kind = cell.dataset.kind;
    const dayType = cell.dataset.dayType || 'work';
    dayTypeSelect.value = dayType === 'vacation' || dayType === 'sick' ? dayType : 'work';

    setTimeFieldValue('morning_start', cell.dataset.morningStart);
    setTimeFieldValue('morning_end', cell.dataset.morningEnd);
    setTimeFieldValue('afternoon_start', cell.dataset.afternoonStart);
    setTimeFieldValue('afternoon_end', cell.dataset.afternoonEnd);
    clearTimeError();

    activeTimeField = 'morning_start';
    document.querySelectorAll('.attendance-time-trigger').forEach((btn) => {
        btn.classList.toggle('is-active', btn.dataset.field === 'morning_start');
    });

    if (kind === 'holiday' && cell.dataset.holidayName) {
        holidayNote.textContent = t('attendance.holiday_auto_note', { name: cell.dataset.holidayName });
        holidayNote.classList.remove('is-hidden');
    } else {
        holidayNote.classList.add('is-hidden');
    }

    syncDayTypeFields();
    initSharedClock();
    sharedClockPicker?.setValue(document.getElementById('attendance-morning-start')?.value || '08:00');
    openModal(dayModal);
});

dayForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearTimeError();

    if (dayTypeSelect.value === 'work') {
        for (const name of timeFieldNames) {
            const input = document.getElementById(fieldToInputId(name));
            if (!input || !input.value.trim()) {
                continue;
            }

            const normalized = normalizeTime24(input.value);
            if (!normalized) {
                showTimeError(t('attendance.time_invalid'));
                return;
            }

            input.value = normalized;
        }
    }

    const formData = new FormData(dayForm);
    const response = await fetch('/api/attendance/day', {
        method: 'POST',
        body: formData,
    });

    if (!response.ok) {
        if (response.status === 422) {
            showTimeError(t('attendance.time_invalid'));
        }
        return;
    }

    const data = await response.json();
    updateSummary(data.summary);
    rerenderWeeks(data.weeks);
    closeModals();
});

document.getElementById('attendance-add-holiday')?.addEventListener('click', (event) => {
    event.preventDefault();
    holidayForm.reset();
    const year = page?.dataset.year;
    if (year) {
        document.getElementById('attendance-holiday-date').value = `${year}-01-01`;
    }
    openModal(holidayModal);
});

holidayForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = new FormData(holidayForm);
    const response = await fetch('/api/attendance/holidays', {
        method: 'POST',
        body: formData,
    });

    if (!response.ok) {
        return;
    }

    closeModals();
    window.location.reload();
});

document.getElementById('attendance-holidays-list')?.addEventListener('click', async (event) => {
    const btn = event.target.closest('.attendance-holidays__remove');
    if (!btn) {
        return;
    }

    const date = btn.dataset.date;
    const source = btn.dataset.source;
    const action = source === 'manual' ? 'delete' : 'exclude';
    const formData = new FormData();
    formData.append('date', date);
    formData.append('action', action);

    const response = await fetch('/api/attendance/holidays/remove', {
        method: 'POST',
        body: formData,
    });

    if (!response.ok) {
        return;
    }

    window.location.reload();
});

function formatDateLabel(iso) {
    const [y, m, d] = iso.split('-');
    return `${d}.${m}.${y}`;
}

function updateSummary(summary) {
    const root = document.getElementById('attendance-summary');
    if (!root || !summary) {
        return;
    }

    const items = root.querySelectorAll('.attendance-summary__item strong');
    if (items.length >= 4) {
        items[0].textContent = `${summary.soll_label} h`;
        items[1].textContent = `${summary.ist_label} h`;
        items[2].textContent = `${summary.diff_label} h`;
        items[2].className = summary.diff_minutes < 0 ? 'is-negative' : summary.diff_minutes > 0 ? 'is-positive' : '';
        items[3].textContent = `${summary.yearly_diff_label} h`;
        items[3].className = summary.yearly_diff_minutes < 0 ? 'is-negative' : summary.yearly_diff_minutes > 0 ? 'is-positive' : '';
    }
}

function rerenderWeeks(weeks) {
    const container = document.getElementById('attendance-weeks');
    if (!container || !weeks) {
        window.location.reload();
        return;
    }

    container.querySelectorAll('.attendance-week').forEach((weekEl, weekIndex) => {
        const week = weeks[weekIndex];
        if (!week) {
            return;
        }

        const totalEl = weekEl.querySelector('.attendance-week__total');
        if (totalEl) {
            totalEl.textContent = t('attendance.week_total', { total: week.week_total_label });
        }

        week.days.forEach((day) => {
            if (!day.in_month || day.is_weekend) {
                return;
            }

            weekEl.querySelectorAll(`.attendance-cell[data-date="${day.date}"]`).forEach((cell) => {
                cell.dataset.kind = day.kind;
                cell.dataset.dayType = day.day_type;
                cell.dataset.morningStart = day.morning_start || '';
                cell.dataset.morningEnd = day.morning_end || '';
                cell.dataset.afternoonStart = day.afternoon_start || '';
                cell.dataset.afternoonEnd = day.afternoon_end || '';
                cell.className = `attendance-cell attendance-cell--${day.kind}`;

                const row = cell.closest('.attendance-week__row');
                if (!row) {
                    return;
                }

                const field = row.dataset.field;
                if (field === 'worked_label') {
                    cell.innerHTML = renderTotalCell(day);
                } else if (!field) {
                    cell.innerHTML = '<span class="muted">—</span>';
                } else if (['vacation', 'sick', 'holiday'].includes(day.kind)) {
                    cell.innerHTML = '<span class="muted">0:00</span>';
                } else {
                    cell.textContent = day[field] || '—';
                }
            });
        });
    });
}

function renderTotalCell(day) {
    let html = `<strong>${day.worked_label}</strong>`;
    if (day.kind === 'vacation') {
        html += `<small>${t('attendance.kind.vacation')}</small>`;
    } else if (day.kind === 'sick') {
        html += `<small>${t('attendance.kind.sick')}</small>`;
    } else if (day.kind === 'holiday' && day.holiday_name) {
        html += `<small>${day.holiday_name}</small>`;
    }
    return html;
}
