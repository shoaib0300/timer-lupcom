import { escapeHtml } from './utils.js';

const loadBtn = document.getElementById('planio-load-projects');
const testBtn = document.getElementById('planio-test-btn');
const syncBtn = document.getElementById('planio-sync-btn');
const filterInput = document.getElementById('planio-project-filter');
const selectVisible = document.getElementById('planio-select-visible');
const importIssues = document.getElementById('planio-import-issues');
const listEl = document.getElementById('planio-project-list');
const emptyEl = document.getElementById('planio-projects-empty');
const loadingEl = document.getElementById('planio-projects-loading');
const actionsEl = document.getElementById('planio-import-actions');
const countEl = document.getElementById('planio-selected-count');
const feedbackEl = document.getElementById('planio-sync-feedback');

let projects = [];

function showFeedback(message, isError = false) {
    if (!feedbackEl) {
        return;
    }

    feedbackEl.textContent = message;
    feedbackEl.classList.remove('is-hidden', 'settings-page__feedback--error');
    if (isError) {
        feedbackEl.classList.add('settings-page__feedback--error');
    }
}

function hideFeedback() {
    feedbackEl?.classList.add('is-hidden');
}

function updateSelectedCount() {
    if (!countEl || !listEl) {
        return;
    }

    const selected = listEl.querySelectorAll('input[type="checkbox"]:checked').length;
    countEl.textContent = `${selected} selected`;
}

function renderProjects() {
    if (!listEl) {
        return;
    }

    const query = (filterInput?.value || '').trim().toLowerCase();
    const filtered = projects.filter((project) => {
        if (!query) {
            return true;
        }

        return project.name.toLowerCase().includes(query)
            || project.identifier.toLowerCase().includes(query)
            || (project.parent_name || '').toLowerCase().includes(query);
    });

    if (filtered.length === 0) {
        listEl.innerHTML = '<p class="muted" style="padding:1rem;">No projects match your filter.</p>';
        listEl.classList.remove('is-hidden');
        updateSelectedCount();
        return;
    }

    listEl.innerHTML = filtered.map((project) => `
        <label class="settings-page__project-item${project.is_linked ? ' settings-page__project-item--linked' : ''}" data-planio-id="${project.id}">
            <input type="checkbox" name="project_ids[]" value="${project.id}">
            <div class="settings-page__project-meta">
                <strong>${escapeHtml(project.name)}</strong>
                <span>#${project.id} · ${escapeHtml(project.identifier)}${project.parent_name ? ` · under ${escapeHtml(project.parent_name)}` : ''}</span>
            </div>
            ${project.is_linked ? '<span class="settings-page__badge">Imported</span>' : ''}
        </label>
    `).join('');

    listEl.classList.remove('is-hidden');
    actionsEl?.classList.remove('is-hidden');
    updateSelectedCount();
}

async function loadProjects() {
    hideFeedback();
    emptyEl?.classList.add('is-hidden');
    loadingEl?.classList.remove('is-hidden');
    loadBtn?.setAttribute('disabled', 'disabled');

    try {
        const response = await fetch('/api/planio/projects');
        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.error || 'Could not load projects.');
        }

        projects = data.projects || [];
        loadingEl?.classList.add('is-hidden');

        if (projects.length === 0) {
            emptyEl?.classList.remove('is-hidden');
            emptyEl.textContent = 'No projects found for your account.';
            return;
        }

        renderProjects();
    } catch (error) {
        loadingEl?.classList.add('is-hidden');
        showFeedback(error.message, true);
    } finally {
        loadBtn?.removeAttribute('disabled');
    }
}

async function testConnection() {
    hideFeedback();
    testBtn?.setAttribute('disabled', 'disabled');

    try {
        const response = await fetch('/api/planio/test', { method: 'POST' });
        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.error || 'Connection test failed.');
        }

        showFeedback(`Connected as ${data.user?.name || 'user'}. Reloading…`);
        window.setTimeout(() => window.location.reload(), 800);
    } catch (error) {
        showFeedback(error.message, true);
    } finally {
        testBtn?.removeAttribute('disabled');
    }
}

async function syncSelected() {
    hideFeedback();

    const selected = [...listEl.querySelectorAll('input[type="checkbox"]:checked')].map((el) => el.value);
    if (selected.length === 0) {
        showFeedback('Select at least one project to import.', true);
        return;
    }

    const body = new URLSearchParams();
    selected.forEach((id) => body.append('project_ids[]', id));
    body.append('import_issues', importIssues?.checked ? '1' : '0');

    syncBtn?.setAttribute('disabled', 'disabled');

    try {
        const response = await fetch('/api/planio/sync', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
        });
        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.error || 'Import failed.');
        }

        showFeedback(data.message || 'Import complete.');
        await loadProjects();
    } catch (error) {
        showFeedback(error.message, true);
    } finally {
        syncBtn?.removeAttribute('disabled');
    }
}

loadBtn?.addEventListener('click', loadProjects);
testBtn?.addEventListener('click', testConnection);
syncBtn?.addEventListener('click', syncSelected);
filterInput?.addEventListener('input', renderProjects);
listEl?.addEventListener('change', updateSelectedCount);

selectVisible?.addEventListener('change', () => {
    const checked = selectVisible.checked;
    listEl?.querySelectorAll('input[type="checkbox"]').forEach((input) => {
        input.checked = checked;
    });
    updateSelectedCount();
});

if (loadBtn) {
    loadProjects();
}
