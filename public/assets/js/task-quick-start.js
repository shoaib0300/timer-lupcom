import { escapeHtml, ICONS, t } from './utils.js';
import { fetchFrequentTasks, searchTasks, startTimerByTaskId } from './timer-api.js';

let runningTaskIds = new Set();
let cachedFrequentTasks = [];
let cachedSearchItems = [];
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

function isTaskRunning(taskId) {
    return runningTaskIds.has(String(taskId));
}

function renderPlayButton(task, { running } = {}) {
    const startLabel = t('start_timer_for', { name: task.name });

    return `
        <button
            type="button"
            class="task-quick__play-btn js-start-task-timer${running ? ' is-disabled' : ''}"
            data-task-id="${task.id}"
            title="${escapeHtml(running ? t('timer_already_running') : startLabel)}"
            aria-label="${escapeHtml(running ? t('timer_already_running') : startLabel)}"
            ${running ? 'disabled' : ''}
        >${ICONS.start}</button>
    `;
}

function renderSuggestionItem(task) {
    const sessions = task.session_count
        ? `<span class="task-quick__meta-muted">${escapeHtml(t('session_count', { count: task.session_count }))}</span>`
        : '';
    const running = isTaskRunning(task.id);

    return `
        <div
            class="task-quick__item task-quick__item--search${running ? ' is-running' : ''}"
            role="option"
            data-task-id="${task.id}"
        >
            <span class="project-dot task-quick__dot" style="background:${escapeHtml(task.project_color || '#3b82f6')}"></span>
            <span class="task-quick__body">
                <span class="task-quick__label">${taskLabel(task)}</span>
                <span class="task-quick__meta">${taskMeta(task)}${sessions ? ` · ${sessions}` : ''}${running ? ` · ${escapeHtml(t('running'))}` : ''}</span>
            </span>
            ${renderPlayButton(task, { running })}
        </div>
    `;
}

function renderFrequentItem(task) {
    const sessions = task.session_count
        ? `<span class="task-quick__meta-muted">${escapeHtml(t('session_count', { count: task.session_count }))}</span>`
        : '';
    const running = isTaskRunning(task.id);

    return `
        <div class="task-quick__item task-quick__item--compact task-quick__item--frequent${running ? ' is-running' : ''}">
            <span class="project-dot task-quick__dot" style="background:${escapeHtml(task.project_color || '#3b82f6')}"></span>
            <span class="task-quick__body">
                <span class="task-quick__label">${taskLabel(task)}</span>
                <span class="task-quick__meta">${taskMeta(task)}${sessions ? ` · ${sessions}` : ''}</span>
            </span>
            ${renderPlayButton(task, { running })}
        </div>
    `;
}

async function startFromTask(task, onStarted) {
    if (isTaskRunning(task.id)) {
        return;
    }

    const data = await startTimerByTaskId(task.id);
    if (data?.status) {
        onStarted(data.status, data);
    }
}

function rerenderSearchResults() {
    const listEl = document.getElementById('task-quick-search-results');
    if (!listEl || listEl.hidden || cachedSearchItems.length === 0) {
        return;
    }

    listEl.innerHTML = cachedSearchItems.map((task) => renderSuggestionItem(task)).join('');
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

    rerenderSearchResults();
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
        handleTaskTimerClick(event, onStarted);
    });
}

function handleTaskTimerClick(event, onStarted) {
    const button = event.target.closest('.js-start-task-timer');
    if (!button || button.disabled) {
        return;
    }

    startFromTask({
        id: Number(button.dataset.taskId),
    }, onStarted);
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
    let activeIndex = -1;

    function closeSuggestions() {
        listEl.hidden = true;
        listEl.innerHTML = '';
        cachedSearchItems = [];
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
        cachedSearchItems = tasks;

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

        if (listEl.hidden || !cachedSearchItems.length) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActiveIndex(Math.min(activeIndex + 1, cachedSearchItems.length - 1));
            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActiveIndex(Math.max(activeIndex - 1, 0));
            return;
        }

        if (event.key === 'Enter') {
            event.preventDefault();
            const task = cachedSearchItems[activeIndex >= 0 ? activeIndex : 0];
            if (task && !isTaskRunning(task.id)) {
                input.value = '';
                closeSuggestions();
                startFromTask(task, onStarted);
            }
        }
    });

    listEl.addEventListener('click', (event) => {
        const playBtn = event.target.closest('.js-start-task-timer');
        if (!playBtn || playBtn.disabled) {
            return;
        }

        const task = cachedSearchItems.find((item) => String(item.id) === playBtn.dataset.taskId);
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
