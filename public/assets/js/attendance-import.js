function t(key, params = {}) {
    const dict = window.__I18N__ || {};
    let str = dict[key] || key;
    Object.entries(params).forEach(([k, v]) => {
        str = str.replace(`:${k}`, String(v));
    });
    return str;
}

export function initAttendanceImport({ onImported }) {
    const form = document.getElementById('attendance-import-form');
    const fileInput = document.getElementById('attendance-import-file');
    const modeSelect = document.getElementById('attendance-import-mode');
    const submitBtn = document.getElementById('attendance-import-submit');
    const successEl = document.getElementById('attendance-import-success');
    const errorEl = document.getElementById('attendance-import-error');
    const statusEl = document.getElementById('attendance-import-status');

    if (!form || !submitBtn) {
        return;
    }

    if (!submitBtn.dataset.defaultLabel) {
        submitBtn.dataset.defaultLabel = submitBtn.textContent.trim();
    }

    function reveal(el) {
        if (!el) {
            return;
        }
        el.classList.remove('is-hidden');
        el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function showSuccess(message) {
        if (successEl) {
            successEl.textContent = message;
            reveal(successEl);
        }
        errorEl?.classList.add('is-hidden');
        if (statusEl) {
            statusEl.textContent = message;
            reveal(statusEl);
        }
    }

    function showError(message) {
        if (errorEl) {
            errorEl.textContent = message;
            reveal(errorEl);
        }
        successEl?.classList.add('is-hidden');
        if (statusEl) {
            statusEl.textContent = message;
            reveal(statusEl);
        }
    }

    function clearMessages() {
        successEl?.classList.add('is-hidden');
        errorEl?.classList.add('is-hidden');
        if (statusEl) {
            statusEl.textContent = '';
            statusEl.classList.add('is-hidden');
        }
    }

    async function runImport() {
        clearMessages();

        const file = fileInput?.files?.[0];
        if (!file) {
            showError(t('attendance.import_missing_file'));
            fileInput?.focus();
            return;
        }

        const mode = modeSelect?.value || 'merge';
        if (mode === 'replace' && !window.confirm(t('attendance.import_replace_confirm'))) {
            return;
        }

        const formData = new FormData(form);
        formData.set('mode', mode);
        formData.set('timetable', file);

        submitBtn.setAttribute('disabled', 'disabled');
        submitBtn.textContent = t('attendance.importing');
        if (statusEl) {
            statusEl.textContent = t('attendance.importing');
            reveal(statusEl);
        }

        try {
            const response = await fetch('/api/attendance/import', {
                method: 'POST',
                body: formData,
            });

            let data = {};
            try {
                data = await response.json();
            } catch {
                data = {};
            }

            if (!response.ok) {
                showError(t('attendance.import_error'));
                return;
            }

            const message = t('attendance.import_success', {
                imported: data.imported ?? 0,
                cleared: data.cleared ?? 0,
                dates: data.dates ?? 0,
            });
            showSuccess(message);

            if (typeof onImported === 'function') {
                onImported(data);
            }
        } catch {
            showError(t('attendance.import_error'));
        } finally {
            submitBtn.removeAttribute('disabled');
            submitBtn.textContent = submitBtn.dataset.defaultLabel || submitBtn.textContent;
        }
    }

    submitBtn.addEventListener('click', runImport);

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        runImport();
    });
}
