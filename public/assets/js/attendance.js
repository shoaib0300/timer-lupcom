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
const FIELD_PERIOD = {
    morning_start: 'AM',
    morning_end: 'AM',
    afternoon_start: 'PM',
    afternoon_end: 'PM',
};
const FIELD_DEFAULTS = {
    morning_start: '08:00',
    morning_end: '12:00',
    afternoon_start: '12:30',
    afternoon_end: '17:30',
};
const START_TO_END = {
    morning_start: 'morning_end',
    afternoon_start: 'afternoon_end',
};
let activeTimeField = 'morning_start';
let sharedClockPicker = null;

function timeToMinutes(value) {
    const normalized = normalizeTime24(value);
    if (!normalized) {
        return null;
    }
    const [h, m] = normalized.split(':').map((part) => Number.parseInt(part, 10));
    return h * 60 + m;
}

function minutesToTime(totalMinutes) {
    const wrapped = ((totalMinutes % (24 * 60)) + 24 * 60) % (24 * 60);
    const hours = Math.floor(wrapped / 60);
    const minutes = wrapped % 60;
    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
}

function suggestEndTime(startField, endField) {
    const startValue = document.getElementById(fieldToInputId(startField))?.value;
    if (!startValue) {
        return FIELD_DEFAULTS[endField];
    }

    const startMinutes = timeToMinutes(startValue);
    if (startMinutes === null) {
        return FIELD_DEFAULTS[endField];
    }

    const offsetMinutes = 4 * 60;
    const cap = startField === 'morning_start' ? timeToMinutes('12:00') : timeToMinutes('20:00');
    return minutesToTime(Math.min(startMinutes + offsetMinutes, cap ?? startMinutes + offsetMinutes));
}

function getClockValueForField(field) {
    const current = document.getElementById(fieldToInputId(field))?.value?.trim();
    if (current) {
        return current;
    }

    if (field === 'morning_end') {
        return suggestEndTime('morning_start', 'morning_end');
    }

    if (field === 'afternoon_end') {
        return suggestEndTime('afternoon_start', 'afternoon_end');
    }

    return FIELD_DEFAULTS[field] || '08:00';
}

function syncClockForField(field) {
    if (!sharedClockPicker) {
        return;
    }

    sharedClockPicker.setDefaultPeriod(FIELD_PERIOD[field] || 'AM');
    sharedClockPicker.setValue(getClockValueForField(field));
}

function maybeAutoFillEndTime(startField, startValue) {
    const endField = START_TO_END[startField];
    if (!endField) {
        return;
    }

    const normalizedStart = normalizeTime24(startValue);
    if (!normalizedStart) {
        return;
    }

    const startMinutes = timeToMinutes(normalizedStart);
    const suggested = suggestEndTime(startField, endField);
    const endInput = document.getElementById(fieldToInputId(endField));
    const currentEnd = endInput?.value?.trim() || '';
    const currentEndMinutes = timeToMinutes(currentEnd);

    if (!currentEnd || currentEndMinutes === null || currentEndMinutes <= startMinutes) {
        setTimeFieldValue(endField, suggested);
    }
}

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

function activateTimeField(field) {
    activeTimeField = field;
    document.querySelectorAll('.attendance-time-trigger').forEach((btn) => {
        btn.classList.toggle('is-active', btn.dataset.field === field);
    });
    syncClockForField(field);
}

function initSharedClock() {
    const mount = document.getElementById('clock-shared');
    if (!mount || sharedClockPicker) {
        return;
    }

    const initial = getClockValueForField(activeTimeField);
    sharedClockPicker = mountClockPicker(mount, {
        value: initial,
        hints: {
            chooseHour: t('attendance.clock_choose_hour'),
            choosePeriod: t('attendance.clock_choose_period'),
            chooseMinutes: t('attendance.clock_choose_minutes'),
        },
        labels: {
            hour: t('attendance.clock_hour'),
            minute: t('attendance.clock_minute'),
            am: t('attendance.clock_am'),
            pm: t('attendance.clock_pm'),
        },
        onChange: (value) => {
            setTimeFieldValue(activeTimeField, value);
            maybeAutoFillEndTime(activeTimeField, value);
            clearTimeError();
        },
    });
    sharedClockPicker.setDefaultPeriod(FIELD_PERIOD[activeTimeField] || 'AM');
    sharedClockPicker.setValue(initial);

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

            activateTimeField(field);
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
    syncClockForField('morning_start');
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
