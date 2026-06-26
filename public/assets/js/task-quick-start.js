import { escapeHtml, ICONS, t } from './utils.js';
import { fetchFrequentTasks, searchTasks, startTimerByTaskId } from './timer-api.js';

let runningTaskIds = new Set();
let cachedFrequentTasks = [];
let lastTimers = [];

function taskLabel(task) {
    const assignee = task.planio_assignee
        ? `${escapeHtml(task.planio_assignee)} · `
        : '';
    return `${assignee}${escapeHtml(task.name)}`;
}

function taskMeta(task) {
    const parts = [escapeHtml(task.project_name)];

    if (task.planio_issue_id) {
        parts.push(`#${escapeHtml(String(task.planio_issue_id))}`);
    }

    parts.push(`ID ${escapeHtml(String(task.id))}`);

    return parts.join(' · ');
}

function renderSuggestionItem(task, { compact = false } = {}) {
    const sessions = task.session_count
        ? `<span class="task-quick__meta-muted">${escapeHtml(t('session_count', { count: task.session_count }))}</span>`
        : '';
    const running = isTaskRunning(task.id);

    return `
        <button
            type="button"
            class="task-quick__item${compact ? ' task-quick__item--compact' : ''}${running ? ' is-running' : ''}"
            role="option"
            data-task-id="${task.id}"
            data-project-id="${task.project_id}"
            ${running ? 'disabled' : ''}
        >
            <span class="project-dot task-quick__dot" style="background:${escapeHtml(task.project_color || '#3b82f6')}"></span>
            <span class="task-quick__body">
                <span class="task-quick__label">${taskLabel(task)}</span>
                <span class="task-quick__meta">${taskMeta(task)}${sessions ? ` · ${sessions}` : ''}${running ? ` · ${escapeHtml(t('running'))}` : ''}</span>
            </span>
            <span class="task-quick__play" aria-hidden="true">${ICONS.start}</span>
        </button>
    `;
}

function isTaskRunning(taskId) {
    return runningTaskIds.has(String(taskId));
}

function renderFrequentItem(task) {
    const sessions = task.session_count
        ? `<span class="task-quick__meta-muted">${escapeHtml(t('session_count', { count: task.session_count }))}</span>`
        : '';
    const startLabel = t('start_timer_for', { name: task.name });
    const running = isTaskRunning(task.id);

    return `
        <div class="task-quick__item task-quick__item--compact task-quick__item--frequent${running ? ' is-running' : ''}">
            <span class="project-dot task-quick__dot" style="background:${escapeHtml(task.project_color || '#3b82f6')}"></span>
            <span class="task-quick__body">
                <span class="task-quick__label">${taskLabel(task)}</span>
                <span class="task-quick__meta">${taskMeta(task)}${sessions ? ` · ${sessions}` : ''}</span>
            </span>
            <button
                type="button"
                class="task-quick__play-btn js-start-frequent-task${running ? ' is-disabled' : ''}"
                data-task-id="${task.id}"
                title="${escapeHtml(running ? t('timer_already_running') : startLabel)}"
                aria-label="${escapeHtml(running ? t('timer_already_running') : startLabel)}"
                ${running ? 'disabled' : ''}
            >${ICONS.start}</button>
        </div>
    `;
}

async function startFromTask(task, onStarted) {
    const data = await startTimerByTaskId(task.id);
    if (data?.status) {
        onStarted(data.status, data);
    }
}

export function syncTaskQuickStart(timers) {
    if (timers !== undefined) {
        lastTimers = timers;
    }

    runningTaskIds = new Set(
        lastTimers.filter((timer) => timer.task_id).map((timer) => String(timer.task_id)),
    );

    const listEl = document.getElementById('timer-frequent-list');
    if (listEl && cachedFrequentTasks.length > 0) {
        listEl.innerHTML = cachedFrequentTasks.map((task) => renderFrequentItem(task)).join('');
    }
}

export function initFrequentTasks(onStarted) {
    const listEl = document.getElementById('timer-frequent-list');
    const emptyEl = document.getElementById('timer-frequent-empty');

    if (!listEl) {
        return;
    }

    fetchFrequentTasks().then((tasks) => {
        cachedFrequentTasks = tasks;

        if (!tasks.length) {
            if (emptyEl) {
                emptyEl.classList.remove('is-hidden');
            }
            return;
        }

        if (emptyEl) {
            emptyEl.classList.add('is-hidden');
        }

        listEl.innerHTML = tasks.map((task) => renderFrequentItem(task)).join('');
        syncTaskQuickStart();
    }).catch(() => {
        if (emptyEl) {
            emptyEl.classList.remove('is-hidden');
        }
    });

    listEl.addEventListener('click', (event) => {
        const button = event.target.closest('.js-start-frequent-task');
        if (!button || button.disabled) {
            return;
        }

        startFromTask({
            id: Number(button.dataset.taskId),
        }, onStarted);
    });
}

export function initTaskQuickSearch(onStarted) {
    const input = document.getElementById('task-quick-search-input');
    const listEl = document.getElementById('task-quick-search-results');
    const emptyEl = document.getElementById('task-quick-search-empty');
    const wrap = document.getElementById('task-quick-search-wrap');

    if (!input || !listEl || !wrap) {
        return;
    }

    let debounceId = null;
    let items = [];
    let activeIndex = -1;

    function closeSuggestions() {
        listEl.hidden = true;
        listEl.innerHTML = '';
        items = [];
        activeIndex = -1;
        if (emptyEl) {
            emptyEl.classList.add('is-hidden');
        }
    }

    function openSuggestions() {
        listEl.hidden = false;
    }

    function setActiveIndex(index) {
        activeIndex = index;
        listEl.querySelectorAll('[role="option"]').forEach((el, i) => {
            el.classList.toggle('is-active', i === activeIndex);
        });
    }

    function renderResults(tasks) {
        items = tasks;

        if (!tasks.length) {
            listEl.innerHTML = '';
            openSuggestions();
            if (emptyEl) {
                emptyEl.classList.remove('is-hidden');
            }
            return;
        }

        if (emptyEl) {
            emptyEl.classList.add('is-hidden');
        }

        listEl.innerHTML = tasks.map((task) => renderSuggestionItem(task)).join('');
        openSuggestions();
        setActiveIndex(0);
    }

    function scheduleSearch() {
        window.clearTimeout(debounceId);
        const query = input.value.trim();

        if (query.length < 2) {
            closeSuggestions();
            return;
        }

        debounceId = window.setTimeout(async () => {
            const tasks = await searchTasks(query);
            renderResults(tasks);
        }, 250);
    }

    input.addEventListener('input', scheduleSearch);

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeSuggestions();
            input.blur();
            return;
        }

        if (listEl.hidden || !items.length) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActiveIndex(Math.min(activeIndex + 1, items.length - 1));
            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActiveIndex(Math.max(activeIndex - 1, 0));
            return;
        }

        if (event.key === 'Enter') {
            event.preventDefault();
            const task = items[activeIndex >= 0 ? activeIndex : 0];
            if (task) {
                input.value = '';
                closeSuggestions();
                startFromTask(task, onStarted);
            }
        }
    });

    listEl.addEventListener('click', (event) => {
        const button = event.target.closest('[data-task-id]');
        if (!button || button.disabled) {
            return;
        }

        const task = items.find((item) => String(item.id) === button.dataset.taskId);
        if (!task || isTaskRunning(task.id)) {
            return;
        }

        input.value = '';
        closeSuggestions();
        startFromTask(task, onStarted);
    });

    document.addEventListener('click', (event) => {
        if (!wrap.contains(event.target)) {
            closeSuggestions();
        }
    });
}

export function initTaskQuickStart(onStarted) {
    initFrequentTasks(onStarted);
    initTaskQuickSearch(onStarted);
}
