import { escapeHtml, formatCompactDateTime, t } from './utils.js';
import {
    createManualEntry,
    deleteTimeEntry,
    fetchPlanioActivities,
    fetchProjectTasks,
} from './timer-api.js';
import {
    applyTimerStopData,
    setTrackedCompleted,
} from './dashboard-stats.js';

const manualForm = document.getElementById('manual-entry-form');
const projectSelect = document.getElementById('manual-project');
const taskSelect = document.getElementById('manual-task');
const activitySelect = document.getElementById('manual-activity');
const reasonInput = document.getElementById('manual-reason');
const reasonLabel = document.getElementById('manual-reason-label');
const feedbackEl = document.getElementById('manual-entry-feedback');
const submitButton = manualForm?.querySelector('button[type="submit"]') || null;
const todayDate = manualForm?.dataset.today
    || document.getElementById('manual-work-date')?.max
    || document.getElementById('manual-work-date')?.value
    || '';
let manualSubmitInFlight = false;

function isEntryToday(entry) {
    if (!entry?.ended_at) {
        return false;
    }

    return entry.ended_at.slice(0, 10) === todayDate;
}

function renderSessionRow(entry) {
    const isGeneral = entry.is_general;
    const entryId = Number(entry.id || 0);
    const projectId = entry.project_id ? Number(entry.project_id) : '';
    const planioTimeEntryId = entry.planio_time_entry_id ? Number(entry.planio_time_entry_id) : '';
    const projectCell = isGeneral
        ? '<span class="project-dot" style="background:#64748b;"></span> ' + escapeHtml(t('general'))
        : `<span class="project-dot" style="background:${escapeHtml(entry.project_color || '#3b82f6')}"></span> ${escapeHtml(entry.project_name || '')}`;
    const label = isGeneral
        ? escapeHtml(entry.reason || t('general_time'))
        : escapeHtml(entry.task_name || entry.reason || '—');
    const subject = entry.subject
        ? escapeHtml(entry.subject)
        : '<span class="muted">—</span>';

    return `
        <tr data-entry-id="${entryId}" data-entry-project-id="${projectId}" data-planio-time-entry-id="${planioTimeEntryId}">
            <td>${projectCell}</td>
            <td>${label}</td>
            <td>${subject}</td>
            <td>${escapeHtml(entry.duration_human || '')}</td>
            <td class="muted">${escapeHtml(formatCompactDateTime(entry.ended_at || ''))}</td>
            <td>
                <button
                    type="button"
                    class="btn btn--ghost btn--sm js-entry-delete"
                    data-entry-id="${entryId}"
                    data-entry-project-id="${projectId}"
                    data-planio-time-entry-id="${planioTimeEntryId}"
                >${escapeHtml(t('delete'))}</button>
            </td>
        </tr>
    `;
}

export function prependSessionRow(entry) {
    if (!isEntryToday(entry)) {
        return;
    }

    const sessionsBody = document.getElementById('recent-sessions-body');
    const sessionsTable = document.getElementById('sessions-table');
    const emptyMessage = document.querySelector('.js-sessions-empty');

    if (!sessionsBody) {
        return;
    }

    if (emptyMessage) {
        emptyMessage.classList.add('is-hidden');
    }

    if (sessionsTable) {
        sessionsTable.classList.remove('is-hidden');
    }

    sessionsBody.insertAdjacentHTML('afterbegin', renderSessionRow(entry));
}

function updateProjectTotal(projectId, totalHuman) {
    if (!projectId) {
        return;
    }

    document.querySelectorAll('.js-project-total').forEach((el) => {
        const card = el.closest('[data-project-id]');
        if (!card || String(card.dataset.projectId) !== String(projectId)) {
            return;
        }

        el.textContent = totalHuman;
    });
}

function showFeedback(message) {
    if (!feedbackEl) {
        return;
    }

    feedbackEl.textContent = message;
    feedbackEl.classList.remove('is-hidden');
}

function hideFeedback() {
    feedbackEl?.classList.add('is-hidden');
}

async function populateTasks(projectId) {
    if (!taskSelect) {
        return;
    }

    taskSelect.innerHTML = '<option value="">Loading tasks…</option>';
    taskSelect.disabled = true;

    if (!projectId) {
        taskSelect.innerHTML = `<option value="">${t('select_project_first')}</option>`;
        return;
    }

    const tasks = await fetchProjectTasks(projectId);
    taskSelect.innerHTML = `<option value="">${t('manual_no_task')}</option>`;
    tasks.forEach((task) => {
        const option = document.createElement('option');
        option.value = String(task.id);
        option.textContent = task.name;
        taskSelect.appendChild(option);
    });
    taskSelect.disabled = false;
}

function syncReasonField() {
    const hasProject = Boolean(projectSelect?.value);

    if (reasonLabel) {
        reasonLabel.textContent = hasProject ? t('note_optional') : t('reason');
    }

    if (reasonInput) {
        reasonInput.placeholder = hasProject
            ? t('optional_note')
            : t('reason_placeholder');
        reasonInput.required = !hasProject;
    }
}

async function populateActivities() {
    if (!activitySelect) {
        return;
    }

    const activities = await fetchPlanioActivities();
    activitySelect.innerHTML = `<option value="">${t('manual_activity_none')}</option>`;
    activities.forEach((activity) => {
        if (!activity?.id || !activity?.name) {
            return;
        }
        const option = document.createElement('option');
        option.value = String(activity.id);
        option.textContent = activity.name;
        activitySelect.appendChild(option);
    });
}

export function updateDashboardAfterStop(data) {
    const entry = data.entry;
    if (!entry) {
        return;
    }

    if (entry.project_id) {
        updateProjectTotal(entry.project_id, data.project_total_human);
    }

    applyTimerStopData(data);

    if (entry.ended_at && isEntryToday(entry)) {
        prependSessionRow(entry);
    }
}

function resetManualForm() {
    manualForm?.reset();

    const dateInput = document.getElementById('manual-work-date');
    if (dateInput) {
        dateInput.value = todayDate;
    }

    const hoursInput = document.getElementById('manual-duration-hours');
    if (hoursInput) {
        hoursInput.value = '1';
    }

    const minutesInput = document.getElementById('manual-duration-minutes');
    if (minutesInput) {
        minutesInput.value = '0';
    }

    if (taskSelect) {
        taskSelect.innerHTML = `<option value="">${t('select_project_first')}</option>`;
        taskSelect.disabled = true;
    }

    if (activitySelect) {
        activitySelect.value = '';
    }

    syncReasonField();
}

if (projectSelect && projectSelect.dataset.manualEntryBound !== '1') {
    projectSelect.dataset.manualEntryBound = '1';
    projectSelect.addEventListener('change', async () => {
        hideFeedback();
        await populateTasks(projectSelect.value);
        syncReasonField();
    });
    syncReasonField();
}

populateActivities().catch(() => {});

async function handleEntryDeleteClick(button) {
    const entryId = Number(button?.dataset?.entryId || 0);
    if (!entryId) {
        return;
    }
    const projectId = Number(button.dataset.entryProjectId || 0);
    const hasPlanio = Boolean(button.dataset.planioTimeEntryId);
    const message = hasPlanio
        ? t('entry_delete_confirm_planio')
        : t('entry_delete_confirm');
    if (!window.confirm(message)) {
        return;
    }

    const result = await deleteTimeEntry(entryId);
    if (!result?.deleted) {
        return;
    }

    const row = button.closest('tr');
    row?.remove();

    const sessionsBody = document.getElementById('recent-sessions-body');
    const sessionsTable = document.getElementById('sessions-table');
    const emptyMessage = document.querySelector('.js-sessions-empty');
    if (sessionsBody && sessionsBody.children.length === 0) {
        sessionsTable?.classList.add('is-hidden');
        emptyMessage?.classList.remove('is-hidden');
    }

    if (typeof result.total_today_seconds === 'number') {
        setTrackedCompleted(result.total_today_seconds, result.total_today_human || '0m');
    }
    if (projectId && result.project_total_human) {
        updateProjectTotal(projectId, result.project_total_human);
    }

    if (result.planio_error) {
        showFeedback(t('entry_delete_success_planio_warn', { error: result.planio_error }));
    } else {
        showFeedback(t('entry_delete_success'));
    }
}

document.addEventListener('click', (event) => {
    const button = event.target instanceof Element
        ? event.target.closest('.js-entry-delete')
        : null;
    if (!button) {
        return;
    }
    handleEntryDeleteClick(button).catch(() => {});
});

if (manualForm && manualForm.dataset.manualEntryBound !== '1') {
    manualForm.dataset.manualEntryBound = '1';
    manualForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (manualSubmitInFlight) {
            return;
        }
        manualSubmitInFlight = true;
        if (submitButton) {
            submitButton.disabled = true;
        }
        hideFeedback();

        try {
            const formData = new FormData(manualForm);

            if (!projectSelect?.value) {
                formData.delete('project_id');
                formData.delete('task_id');
                formData.delete('activity_id');
                formData.delete('activity_name');
            } else if (activitySelect?.value) {
                const selected = activitySelect.options[activitySelect.selectedIndex];
                formData.set('activity_name', selected?.textContent?.trim() || '');
            }

            const data = await createManualEntry(formData);

            if (!data) {
                return;
            }

            if (data.is_today) {
                setTrackedCompleted(data.total_today_seconds, data.total_today_human);

                if (data.entry) {
                    prependSessionRow(data.entry);
                }

                if (data.entry?.project_id) {
                    updateProjectTotal(data.entry.project_id, data.project_total_human);
                }
            }

            let feedback = data.message || t('time_logged');
            if (data.planio_pushed) {
                feedback += ` ${t('manual_planio_push_success')}`;
            } else if (data.planio_error) {
                feedback += ` ${data.planio_error || t('manual_planio_push_failed')}`;
            }

            showFeedback(feedback);
            resetManualForm();
        } finally {
            manualSubmitInFlight = false;
            if (submitButton) {
                submitButton.disabled = false;
            }
        }
    });
}
