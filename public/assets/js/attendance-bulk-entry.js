function t(key, params = {}) {
    const dict = window.__I18N__ || {};
    let str = dict[key] || key;
    Object.entries(params).forEach(([k, v]) => {
        str = str.replace(`:${k}`, String(v));
    });
    return str;
}

const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

function formatGermanDate(iso) {
    const [y, m, d] = iso.split('-');
    return `${d}.${m}.${y}`;
}

function parseMonth(isoMonth) {
    const [year, month] = isoMonth.split('-').map((part) => Number.parseInt(part, 10));
    return { year, month };
}

function isoDate(year, month, day) {
    return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

function isWeekend(iso) {
    const day = new Date(`${iso}T12:00:00`).getDay();
    return day === 0 || day === 6;
}

function countWeekdays(from, to) {
    let count = 0;
    const start = new Date(`${from}T12:00:00`);
    const end = new Date(`${to}T12:00:00`);
    for (let cursor = new Date(start); cursor <= end; cursor.setDate(cursor.getDate() + 1)) {
        const day = cursor.getDay();
        if (day !== 0 && day !== 6) {
            count += 1;
        }
    }
    return count;
}

function compareIso(a, b) {
    return a.localeCompare(b);
}

export function initAttendanceBulkEntry({ onSaved, openModal, closeModal }) {
    const trigger = document.getElementById('attendance-bulk-entry-btn');
    const modal = document.getElementById('attendance-bulk-modal');
    const grid = document.getElementById('attendance-bulk-calendar-grid');
    const monthLabel = document.getElementById('attendance-bulk-month-label');
    const previewEl = document.getElementById('attendance-bulk-preview');
    const typeSelect = document.getElementById('attendance-bulk-day-type');
    const applyBtn = document.getElementById('attendance-bulk-apply');
    const errorEl = document.getElementById('attendance-bulk-error');
    const page = document.getElementById('attendance-page');

    if (!trigger || !modal || !grid || !applyBtn) {
        return;
    }

    let viewYear;
    let viewMonth;
    let mode = 'single';
    let rangeAnchor = null;
    let selectedFrom = null;
    let selectedTo = null;

    function resetSelection() {
        rangeAnchor = null;
        selectedFrom = null;
        selectedTo = null;
        updatePreview();
        renderCalendar();
    }

    function setMode(nextMode) {
        mode = nextMode;
        resetSelection();
        document.querySelectorAll('[data-bulk-mode]').forEach((btn) => {
            btn.classList.toggle('is-active', btn.dataset.bulkMode === mode);
        });
    }

    function initViewMonth() {
        const pageMonth = page?.dataset.month;
        const parsed = pageMonth ? parseMonth(pageMonth) : null;
        const now = new Date();
        viewYear = parsed?.year ?? now.getFullYear();
        viewMonth = parsed?.month ?? (now.getMonth() + 1);
    }

    function shiftMonth(delta) {
        viewMonth += delta;
        if (viewMonth < 1) {
            viewMonth = 12;
            viewYear -= 1;
        } else if (viewMonth > 12) {
            viewMonth = 1;
            viewYear += 1;
        }
        renderCalendar();
    }

    function handleDayClick(iso) {
        if (mode === 'single') {
            selectedFrom = iso;
            selectedTo = iso;
            rangeAnchor = null;
        } else if (rangeAnchor === null) {
            rangeAnchor = iso;
            selectedFrom = iso;
            selectedTo = iso;
        } else {
            if (compareIso(iso, rangeAnchor) < 0) {
                selectedFrom = iso;
                selectedTo = rangeAnchor;
            } else {
                selectedFrom = rangeAnchor;
                selectedTo = iso;
            }
            rangeAnchor = null;
        }

        updatePreview();
        renderCalendar();
    }

    function isSelected(iso) {
        if (!selectedFrom || !selectedTo) {
            return false;
        }
        return compareIso(iso, selectedFrom) >= 0 && compareIso(iso, selectedTo) <= 0;
    }

    function updatePreview() {
        if (!previewEl) {
            return;
        }

        if (!selectedFrom || !selectedTo) {
            previewEl.textContent = t('attendance.bulk_preview_none');
            return;
        }

        if (selectedFrom === selectedTo) {
            previewEl.textContent = t('attendance.bulk_preview_single', {
                date: formatGermanDate(selectedFrom),
            });
            previewEl.dataset.fromMonth = selectedFrom.slice(0, 7);
            return;
        }

        const count = countWeekdays(selectedFrom, selectedTo);
        previewEl.textContent = t('attendance.bulk_preview_range', {
            count,
            start: formatGermanDate(selectedFrom),
            end: formatGermanDate(selectedTo),
        });
        previewEl.dataset.fromMonth = selectedFrom.slice(0, 7);
    }

    function renderCalendar() {
        if (!grid || !monthLabel) {
            return;
        }

        const first = new Date(viewYear, viewMonth - 1, 1);
        const daysInMonth = new Date(viewYear, viewMonth, 0).getDate();
        const startOffset = (first.getDay() + 6) % 7;
        const monthNames = new Intl.DateTimeFormat(document.documentElement.lang || 'de', {
            month: 'long',
            year: 'numeric',
        }).format(first);

        monthLabel.textContent = monthNames;

        let html = '<div class="attendance-bulk-calendar__weekdays">';
        WEEKDAYS.forEach((key) => {
            html += `<span>${t(`reports.weekday.${key}`)}</span>`;
        });
        html += '</div><div class="attendance-bulk-calendar__days">';

        for (let i = 0; i < startOffset; i += 1) {
            html += '<span class="attendance-bulk-calendar__day is-outside" aria-hidden="true"></span>';
        }

        for (let day = 1; day <= daysInMonth; day += 1) {
            const iso = isoDate(viewYear, viewMonth, day);
            const weekend = isWeekend(iso);
            const selected = isSelected(iso);
            const classes = [
                'attendance-bulk-calendar__day',
                weekend ? 'is-weekend' : '',
                selected ? 'is-selected' : '',
            ].filter(Boolean).join(' ');

            html += `<button type="button" class="${classes}" data-date="${iso}"${weekend ? ' data-weekend="1"' : ''}>${day}</button>`;
        }

        html += '</div>';
        grid.innerHTML = html;
    }

    function showError(message) {
        if (!errorEl) {
            return;
        }
        errorEl.textContent = message;
        errorEl.classList.remove('is-hidden');
    }

    function clearError() {
        errorEl?.classList.add('is-hidden');
    }

    trigger.addEventListener('click', () => {
        initViewMonth();
        setMode('single');
        clearError();
        if (typeSelect) {
            typeSelect.value = 'vacation';
        }
        renderCalendar();
        openModal(modal);
    });

    document.getElementById('attendance-bulk-prev-month')?.addEventListener('click', () => shiftMonth(-1));
    document.getElementById('attendance-bulk-next-month')?.addEventListener('click', () => shiftMonth(1));

    document.querySelectorAll('[data-bulk-mode]').forEach((btn) => {
        btn.addEventListener('click', () => setMode(btn.dataset.bulkMode || 'single'));
    });

    grid.addEventListener('click', (event) => {
        const button = event.target.closest('[data-date]');
        if (!button) {
            return;
        }
        handleDayClick(button.dataset.date);
    });

    applyBtn.addEventListener('click', async () => {
        clearError();

        if (!selectedFrom || !selectedTo) {
            showError(t('attendance.bulk_preview_none'));
            return;
        }

        const weekdayCount = countWeekdays(selectedFrom, selectedTo);
        if (weekdayCount === 0) {
            showError(t('attendance.bulk_no_weekdays'));
            return;
        }

        const formData = new FormData();
        formData.append('from', selectedFrom);
        formData.append('to', selectedTo);
        formData.append('day_type', typeSelect?.value || 'vacation');
        formData.append('month', page?.dataset.month || selectedFrom.slice(0, 7));

        applyBtn.setAttribute('disabled', 'disabled');
        const defaultLabel = applyBtn.dataset.defaultLabel || applyBtn.textContent;
        applyBtn.textContent = t('attendance.bulk_applying');

        try {
            const response = await fetch('/api/attendance/bulk-days', {
                method: 'POST',
                body: formData,
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                const key = data.error === 'no_weekdays'
                    ? 'attendance.bulk_no_weekdays'
                    : 'attendance.bulk_error';
                showError(t(key));
                return;
            }

            const typeLabel = typeSelect?.value === 'sick'
                ? t('attendance.kind.sick')
                : t('attendance.kind.vacation');

            const successEl = document.getElementById('attendance-import-success');
            if (successEl) {
                successEl.textContent = t('attendance.bulk_success', {
                    count: data.saved ?? weekdayCount,
                    type: typeLabel,
                });
                successEl.classList.remove('is-hidden');
                document.getElementById('attendance-import-error')?.classList.add('is-hidden');
                successEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

            closeModal();
            if (typeof onSaved === 'function') {
                onSaved(data, { from: selectedFrom, to: selectedTo });
            }
        } catch {
            showError(t('attendance.bulk_error'));
        } finally {
            applyBtn.removeAttribute('disabled');
            applyBtn.textContent = defaultLabel;
        }
    });

    if (!applyBtn.dataset.defaultLabel) {
        applyBtn.dataset.defaultLabel = applyBtn.textContent.trim();
    }
}
