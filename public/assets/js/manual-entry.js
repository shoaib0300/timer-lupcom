import { escapeHtml, t } from './utils.js';
import { createManualEntry, fetchProjectTasks } from './timer-api.js';
import {
    applyTimerStopData,
    setTrackedCompleted,
} from './dashboard-stats.js';

const manualForm = document.getElementById('manual-entry-form');
const projectSelect = document.getElementById('manual-project');
const taskSelect = document.getElementById('manual-task');
const reasonInput = document.getElementById('manual-reason');
const reasonLabel = document.getElementById('manual-reason-label');
const feedbackEl = document.getElementById('manual-entry-feedback');
const todayDate = manualForm?.dataset.today
    || document.getElementById('manual-work-date')?.max
    || document.getElementById('manual-work-date')?.value
    || '';

function isEntryToday(entry) {
    if (!entry?.ended_at) {
        return false;
    }

    return entry.ended_at.slice(0, 10) === todayDate;
}

function renderSessionRow(entry) {
    const isGeneral = entry.is_general;
    const projectCell = isGeneral
        ? '<span class="project-dot" style="background:#64748b;"></span> ' + escapeHtml(t('general'))
        : `<span class="project-dot" style="background:${escapeHtml(entry.project_color || '#3b82f6')}"></span> ${escapeHtml(entry.project_name || '')}`;
    const label = isGeneral
        ? escapeHtml(entry.reason || t('general_time'))
        : escapeHtml(entry.task_name || entry.reason || '—');

    return `
        <tr>
            <td>${projectCell}</td>
            <td>${label}</td>
            <td>${escapeHtml(entry.duration_human || '')}</td>
            <td class="muted">${escapeHtml(entry.ended_at || '')}</td>
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
        taskSelect.innerHTML = '<option value="">Select a project first</option>';
        return;
    }

    const tasks = await fetchProjectTasks(projectId);
    taskSelect.innerHTML = '<option value="">Select a task</option>';
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
        taskSelect.innerHTML = '<option value="">Select a project first</option>';
        taskSelect.disabled = true;
    }

    syncReasonField();
}

if (projectSelect) {
    projectSelect.addEventListener('change', async () => {
        hideFeedback();
        await populateTasks(projectSelect.value);
        syncReasonField();
    });
    syncReasonField();
}

if (manualForm) {
    manualForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        hideFeedback();

        const formData = new FormData(manualForm);

        if (!projectSelect?.value) {
            formData.delete('project_id');
            formData.delete('task_id');
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

        showFeedback(data.message || t('time_logged'));
        resetManualForm();
    });
}
