import { escapeHtml, t } from './utils.js';

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
    return t('imported_summary', {
        created: stats.projects_created,
        updated: stats.projects_updated,
        tasks_created: stats.tasks_created,
        tasks_updated: stats.tasks_updated,
    });
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
    countEl.textContent = t('selected_count', { count: selected });
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
        listEl.innerHTML = '<p class="muted" style="padding:1rem;">' + escapeHtml(t('no_filter_match')) + '</p>';
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
            ${project.is_linked ? '<span class="settings-page__badge">' + escapeHtml(t('imported_badge')) + '</span>' : ''}
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
            throw new Error(data.error || t('could_not_load_projects'));
        }

        projects = data.projects || [];
        loadingEl?.classList.add('is-hidden');

        if (projects.length === 0) {
            emptyEl?.classList.remove('is-hidden');
            emptyEl.textContent = t('no_projects_found');
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
            throw new Error(data.error || t('connection_test_failed'));
        }

        showFeedback(t('connected_as', { name: data.user?.name || t('unknown') }));
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
        showFeedback(t('select_one_project'), true);
        return;
    }

    const importIssuesEnabled = importIssues?.checked ?? false;
    const totals = emptyStats();
    const failures = [];

    importing = true;
    setImportControlsDisabled(true);
    setImportProgress(0, selected.length, t('starting_import'), t('projects_progress', { done: 0, total: selected.length }));

    try {
        for (let index = 0; index < selected.length; index += 1) {
            const id = selected[index];
            const name = projectNameForId(id);
            const completedBefore = index;

            setImportProgress(
                completedBefore,
                selected.length,
                t('importing_name', { name }),
                t('projects_progress', { done: completedBefore, total: selected.length }),
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
                    throw new Error(data.error || t('import_failed'));
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
                    ? t('import_with_errors')
                    : completed === selected.length
                        ? t('import_complete')
                        : t('importing_name', { name }),
                t('projects_progress', { done: completed, total: selected.length }),
            );
        }

        if (failures.length > 0) {
            const summary = formatImportMessage(totals);
            showFeedback(`${summary} ${t('projects_failed', { count: failures.length, details: failures.slice(0, 3).join('; ') + (failures.length > 3 ? '…' : '') })}`, true);
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
