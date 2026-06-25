import { escapeHtml, formatClock, ICONS } from './utils.js';
import { postTimerAction } from './timer-api.js';
import { updateDashboardAfterStop } from './dashboard.js';
import { syncProjectCards } from './project-cards.js';

export function createTimerSidebar(listEl, emptyEl, countEl, onStatusChange) {
    let timers = [];
    let tickInterval = null;

    function notify() {
        const status = { timers, running: timers.length > 0 };
        onStatusChange?.(status);
        syncProjectCards(timers);
    }

    function renderTimerItem(timer) {
        const paused = Boolean(timer.is_paused);
        const toggleBtn = paused
            ? `<button type="button" class="timer-icon-btn timer-icon-btn--play js-resume-timer" data-entry-id="${timer.id}" title="Resume" aria-label="Resume">${ICONS.play}</button>`
            : `<button type="button" class="timer-icon-btn timer-icon-btn--pause js-pause-timer" data-entry-id="${timer.id}" title="Pause" aria-label="Pause">${ICONS.pause}</button>`;

        return `
            <article class="timer-item${paused ? ' is-paused' : ''}" data-entry-id="${timer.id}">
                <span class="project-dot timer-item__dot" style="background:${escapeHtml(timer.project_color || '#3b82f6')}"></span>
                <div class="timer-item__content">
                    <div class="timer-item__row">
                        <span class="timer-item__project">${escapeHtml(timer.project_name || 'Project')}</span>
                        <time class="timer-item__clock" data-clock-for="${timer.id}">${formatClock(timer.elapsed_seconds || 0)}</time>
                    </div>
                    <div class="timer-item__row">
                        <span class="timer-item__task">${escapeHtml(timer.task_name || 'no-work')}</span>
                        <div class="timer-item__actions">
                            ${toggleBtn}
                            <button type="button" class="timer-icon-btn timer-icon-btn--stop js-stop-timer" data-entry-id="${timer.id}" title="Stop" aria-label="Stop">${ICONS.stop}</button>
                        </div>
                    </div>
                </div>
            </article>
        `;
    }

    function render() {
        listEl.innerHTML = '';

        if (!timers.length) {
            emptyEl.hidden = false;
            countEl.textContent = '0';
            if (tickInterval) {
                clearInterval(tickInterval);
                tickInterval = null;
            }
            notify();
            return;
        }

        emptyEl.hidden = true;
        countEl.textContent = String(timers.length);
        listEl.innerHTML = timers.map(renderTimerItem).join('');

        if (!tickInterval) {
            tickInterval = setInterval(() => {
                let changed = false;
                timers = timers.map((timer) => {
                    if (timer.is_paused) {
                        return timer;
                    }
                    changed = true;
                    return {
                        ...timer,
                        elapsed_seconds: (timer.elapsed_seconds || 0) + 1,
                    };
                });

                if (!changed) {
                    return;
                }

                timers.forEach((timer) => {
                    if (timer.is_paused) {
                        return;
                    }
                    const clock = listEl.querySelector(`[data-clock-for="${timer.id}"]`);
                    if (clock) {
                        clock.textContent = formatClock(timer.elapsed_seconds);
                    }
                });
            }, 1000);
        }

        notify();
    }

    function applyStatus(status) {
        timers = (status && status.timers) ? status.timers.map((t) => ({ ...t })) : [];
        render();
    }

    listEl.addEventListener('click', async (event) => {
        const pauseBtn = event.target.closest('.js-pause-timer');
        const resumeBtn = event.target.closest('.js-resume-timer');
        const stopBtn = event.target.closest('.js-stop-timer');

        if (pauseBtn) {
            const data = await postTimerAction('/api/timer/pause', pauseBtn.dataset.entryId);
            if (data) {
                applyStatus(data.status);
            }
        } else if (resumeBtn) {
            const data = await postTimerAction('/api/timer/resume', resumeBtn.dataset.entryId);
            if (data) {
                applyStatus(data.status);
            }
        } else if (stopBtn) {
            const data = await postTimerAction('/api/timer/stop', stopBtn.dataset.entryId);
            if (data) {
                applyStatus(data.status);
                updateDashboardAfterStop(data);
            }
        }
    });

    return { applyStatus };
}
