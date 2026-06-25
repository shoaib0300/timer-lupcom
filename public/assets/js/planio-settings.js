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
const progressEl = document.getElementById('planio-import-progress');
const progressLabelEl = document.getElementById('planio-import-progress-label');
const progressPctEl = document.getElementById('planio-import-progress-pct');
const progressBarEl = document.getElementById('planio-import-progress-bar');
const progressDetailEl = document.getElementById('planio-import-progress-detail');

let projects = [];
let importing = false;

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

function hideProgress() {
    progressEl?.classList.add('is-hidden');
}

function setImportProgress(completed, total, label, detail = '') {
    if (!progressEl) {
        return;
    }

    const safeTotal = Math.max(total, 1);
    const pct = Math.min(100, Math.round((completed / safeTotal) * 100));

    progressEl.classList.remove('is-hidden');
    progressLabelEl.textContent = label;
    progressPctEl.textContent = `${pct}%`;
    progressBarEl.style.width = `${pct}%`;
    progressDetailEl.textContent = detail;
}

function setImportControlsDisabled(disabled) {
    syncBtn?.toggleAttribute('disabled', disabled);
    loadBtn?.toggleAttribute('disabled', disabled);
    filterInput?.toggleAttribute('disabled', disabled);
    selectVisible?.toggleAttribute('disabled', disabled);
    importIssues?.toggleAttribute('disabled', disabled);
    listEl?.querySelectorAll('input[type="checkbox"]').forEach((input) => {
        input.toggleAttribute('disabled', disabled);
    });
}

function emptyStats() {
    return {
        projects_created: 0,
        projects_updated: 0,
        tasks_created: 0,
        tasks_updated: 0,
    };
}

function mergeStats(into, from) {
    into.projects_created += from.projects_created || 0;
    into.projects_updated += from.projects_updated || 0;
    into.tasks_created += from.tasks_created || 0;
    into.tasks_updated += from.tasks_updated || 0;
}

function formatImportMessage(stats) {
    return `Imported from Planio: ${stats.projects_created} new and ${stats.projects_updated} updated projects locally. Tasks: ${stats.tasks_created} new, ${stats.tasks_updated} updated. Nothing was sent to Planio.`;
}

function projectNameForId(id) {
    const project = projects.find((item) => String(item.id) === String(id));
    return project?.name || `Project #${id}`;
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
            <input type="checkbox" name="project_ids[]" value="${project.id}"${importing ? ' disabled' : ''}>
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
        if (!importing) {
            loadBtn?.removeAttribute('disabled');
        }
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
    if (importing) {
        return;
    }

    hideFeedback();

    const selected = [...listEl.querySelectorAll('input[type="checkbox"]:checked')].map((el) => el.value);
    if (selected.length === 0) {
        showFeedback('Select at least one project to import.', true);
        return;
    }

    const importIssuesEnabled = importIssues?.checked ?? false;
    const totals = emptyStats();
    const failures = [];

    importing = true;
    setImportControlsDisabled(true);
    setImportProgress(0, selected.length, 'Starting import…', `0 of ${selected.length} projects`);

    try {
        for (let index = 0; index < selected.length; index += 1) {
            const id = selected[index];
            const name = projectNameForId(id);
            const completedBefore = index;

            setImportProgress(
                completedBefore,
                selected.length,
                `Importing ${name}…`,
                `${completedBefore} of ${selected.length} projects`,
            );

            const body = new URLSearchParams();
            body.append('project_id', id);
            body.append('import_issues', importIssuesEnabled ? '1' : '0');
            body.append('finalize', index === selected.length - 1 ? '1' : '0');

            try {
                const response = await fetch('/api/planio/sync-item', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body,
                });
                const data = await response.json();

                if (!response.ok) {
                    throw new Error(data.error || 'Import failed.');
                }

                mergeStats(totals, data.stats || {});
            } catch (error) {
                failures.push(`${name}: ${error.message}`);
            }

            const completed = index + 1;
            setImportProgress(
                completed,
                selected.length,
                failures.length > 0 && completed === selected.length
                    ? 'Import finished with errors'
                    : completed === selected.length
                        ? 'Import complete'
                        : `Importing ${name}…`,
                `${completed} of ${selected.length} projects`,
            );
        }

        if (failures.length > 0) {
            const summary = formatImportMessage(totals);
            showFeedback(`${summary} ${failures.length} project(s) failed: ${failures.slice(0, 3).join('; ')}${failures.length > 3 ? '…' : ''}`, true);
        } else {
            showFeedback(formatImportMessage(totals));
        }

        await loadProjects();
    } catch (error) {
        showFeedback(error.message, true);
        hideProgress();
    } finally {
        importing = false;
        setImportControlsDisabled(false);
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
