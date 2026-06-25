import { escapeHtml, t } from './utils.js';

function planioIdCell(planioIssueId) {
    if (!planioIssueId) {
        return '<span class="muted">—</span>';
    }

    return `<span class="project-show__planio-badge project-show__planio-badge--sm">#${escapeHtml(String(planioIssueId))}</span>`;
}

function statusCell(task) {
    if (task.planio_issue_id) {
        return `<span class="badge badge--planio" title="${escapeHtml(t('synced_from_planio'))}">${escapeHtml(task.status)}</span>`;
    }

    const label = task.status.replace(/_/g, ' ');
    return `<span class="badge badge--${escapeHtml(task.status)}">${escapeHtml(label)}</span>`;
}

function taskNameCell(task) {
    const assignee = task.planio_assignee
        ? `<span class="project-show__assignee">${escapeHtml(task.planio_assignee)}</span><span class="project-show__assignee-sep muted" aria-hidden="true">·</span>`
        : '';

    return `${assignee}<span class="project-show__task-title">${escapeHtml(task.name)}</span>`;
}

function renderTaskRow(task, project) {
    return `
        <tr data-task-id="${task.id}">
            <td class="project-show__task-name">${taskNameCell(task)}</td>
            <td class="project-show__col-planio">${planioIdCell(task.planio_issue_id)}</td>
            <td>${statusCell(task)}</td>
            <td>${escapeHtml(task.total_human)}</td>
            <td class="project-show__col-actions">
                <div class="project-show__row-actions">
                    <button
                        type="button"
                        class="btn btn--primary btn--sm js-start-timer"
                        data-project-id="${project.id}"
                        data-project-name="${escapeHtml(project.name)}"
                        data-task-name="${escapeHtml(task.name)}"
                    >${escapeHtml(t('start'))}</button>
                    <a href="/tasks/${task.id}/edit" class="btn btn--ghost btn--sm">${escapeHtml(t('edit'))}</a>
                    <form class="inline-form" method="post" action="/tasks/${task.id}/delete" onsubmit="return confirm('${escapeHtml(t('delete_task_confirm'))}');">
                        <button type="submit" class="btn btn--danger btn--sm">${escapeHtml(t('delete'))}</button>
                    </form>
                </div>
            </td>
        </tr>
    `;
}

export async function refreshProjectTasks(projectId, projectName) {
    const section = document.getElementById('project-tasks');
    if (!section || String(section.dataset.projectId) !== String(projectId)) {
        return;
    }

    const response = await fetch(`/api/projects/${projectId}/tasks`);
    if (!response.ok) {
        return;
    }

    const data = await response.json();
    const tasks = data.tasks || [];
    const project = { id: projectId, name: projectName || section.dataset.projectName || '' };
    const content = section.querySelector('#project-tasks-content');

    if (!content) {
        return;
    }

    if (!tasks.length) {
        content.innerHTML = '<p class="muted js-tasks-empty">' + escapeHtml(t('no_tasks_yet')) + '</p>';
        return;
    }

    content.innerHTML = `
        <div class="project-show__table-wrap">
            <table class="table project-show__table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th class="project-show__col-planio">Planio #</th>
                        <th>Status</th>
                        <th>Time</th>
                        <th class="project-show__col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody id="project-tasks-body">
                    ${tasks.map((task) => renderTaskRow(task, project)).join('')}
                </tbody>
            </table>
        </div>
    `;
}

export function initProjectTasksRefresh(onTimerStarted) {
    const original = onTimerStarted;
    return (status, startData) => {
        original(status);
        const projectId = startData?.entry?.project_id;
        const projectName = startData?.entry?.project_name;
        if (projectId) {
            refreshProjectTasks(projectId, projectName);
        }
    };
}
