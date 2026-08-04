import { escapeHtml, t } from './utils.js';
import { initProjectTaskDetails } from './project-task-details.js';

function inlineFormat(text) {
    return text
        .replace(/(?<!\*)\*([^*\n]+)\*(?!\*)/g, '<strong>$1</strong>')
        .replace(/(?<!_)_([^_\n]+)_(?!_)/g, '<em>$1</em>')
        .replace(/\b((?:https?):\/\/[^\s<]+)/gi, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
}

function formatRichText(text) {
    if (!text) {
        return '';
    }

    const escaped = escapeHtml(String(text).replace(/\r\n?/g, '\n').trim());
    const lines = escaped.split('\n');
    const html = [];
    let listOpen = false;

    for (const line of lines) {
        const trimmed = line.trim();
        const listMatch = trimmed.match(/^\*\s+(.+)$/);

        if (listMatch) {
            if (!listOpen) {
                html.push('<ul>');
                listOpen = true;
            }
            html.push(`<li>${inlineFormat(listMatch[1])}</li>`);
            continue;
        }

        if (listOpen) {
            html.push('</ul>');
            listOpen = false;
        }

        if (trimmed === '') {
            continue;
        }

        html.push(`<p>${inlineFormat(trimmed)}</p>`);
    }

    if (listOpen) {
        html.push('</ul>');
    }

    return html.join('');
}

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
    const toggle = task.description
        ? `<button type="button" class="project-show__task-toggle" data-task-id="${task.id}" aria-expanded="false" aria-controls="task-desc-${task.id}" title="${escapeHtml(t('toggle_description'))}"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M8.59 16.59 13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg></button>`
        : '';

    const assignee = task.planio_assignee
        ? `<span class="project-show__assignee">${escapeHtml(task.planio_assignee)}</span><span class="project-show__assignee-sep muted" aria-hidden="true">·</span>`
        : '';

    return `${toggle}${assignee}<span class="project-show__task-title">${escapeHtml(task.name)}</span>`;
}

function taskDetailRow(task) {
    if (!task.description) {
        return '';
    }

    return `
        <tr class="project-show__task-detail is-hidden" id="task-desc-${task.id}">
            <td colspan="5">
                <div class="project-show__task-description prose">${formatRichText(task.description)}</div>
            </td>
        </tr>
    `;
}

function renderTaskRow(task, project) {
    return `
        <tr class="project-show__task-row" data-task-id="${task.id}">
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
        ${taskDetailRow(task)}
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

    initProjectTaskDetails(content);
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
