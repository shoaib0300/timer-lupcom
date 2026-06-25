import { escapeHtml } from './utils.js';

export function updateDashboardAfterStop(data) {
    const entry = data.entry;
    if (!entry) {
        return;
    }

    document.querySelectorAll('.js-project-total').forEach((el) => {
        const card = el.closest('[data-project-id]');
        const projectId = card ? card.dataset.projectId : el.dataset.projectId;
        if (String(projectId) !== String(entry.project_id)) {
            return;
        }

        el.dataset.totalSeconds = String(data.project_total_seconds);
        el.textContent = el.dataset.projectId
            ? `${data.project_total_human} tracked`
            : data.project_total_human;
    });

    const todayEl = document.querySelector('.js-total-today');
    if (todayEl && data.total_today_human) {
        todayEl.textContent = data.total_today_human;
        todayEl.dataset.totalSeconds = String(data.total_today_seconds);
    }

    const sessionsBody = document.getElementById('recent-sessions-body');
    if (sessionsBody && entry.ended_at) {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>
                <span class="project-dot" style="background:${escapeHtml(entry.project_color || '#3b82f6')}"></span>
                ${escapeHtml(entry.project_name || '')}
            </td>
            <td>${escapeHtml(entry.task_name || '—')}</td>
            <td>${escapeHtml(entry.duration_human || '')}</td>
            <td class="muted">${escapeHtml(entry.ended_at)}</td>
        `;
        sessionsBody.prepend(row);
    }
}
