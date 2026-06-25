import { escapeHtml } from './utils.js';

function renderTaskRow(task, project) {
    const statusLabel = task.status.replace(/_/g, ' ');
    return `
        <tr data-task-id="${task.id}">
            <td>${escapeHtml(task.name)}</td>
            <td><span class="badge badge--${escapeHtml(task.status)}">${escapeHtml(statusLabel)}</span></td>
            <td>${escapeHtml(task.total_human)}</td>
            <td class="actions">
                <button
                    type="button"
                    class="btn btn--primary btn--sm js-start-timer"
                    data-project-id="${project.id}"
                    data-project-name="${escapeHtml(project.name)}"
                    data-task-name="${escapeHtml(task.name)}"
                >Start</button>
                <a href="/tasks/${task.id}/edit" class="btn btn--ghost btn--sm">Edit</a>
                <form class="inline-form" method="post" action="/tasks/${task.id}/delete" onsubmit="return confirm('Delete this task?');">
                    <button type="submit" class="btn btn--danger btn--sm">Delete</button>
                </form>
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
        content.innerHTML = '<p class="muted js-tasks-empty">No tasks for this project yet.</p>';
        return;
    }

    content.innerHTML = `
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Time</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="project-tasks-body">
                ${tasks.map((task) => renderTaskRow(task, project)).join('')}
            </tbody>
        </table>
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
