import { escapeHtml, formatLiveClock, ICONS, t } from './utils.js?v=56';
import { postTimerAction } from './timer-api.js?v=56';
import { updateDashboardAfterStop } from './dashboard.js';
import { refreshTrackedLive } from './dashboard-stats.js';
import { syncProjectCards } from './project-cards.js';

export function createTimerSidebar(listEl, emptyEl, countEl, onStatusChange, stopModal = null) {
    let timers = [];
    let tickInterval = null;

    function notify() {
        const status = { timers, running: timers.length > 0 };
        onStatusChange?.(status);
        syncProjectCards(timers);
        refreshTrackedLive(timers);
    }

    function renderTimerItem(timer) {
        const paused = Boolean(timer.is_paused);
        const toggleBtn = paused
            ? `<button type="button" class="timer-icon-btn timer-icon-btn--play js-resume-timer" data-entry-id="${timer.id}" title="${t('resume')}" aria-label="${t('resume')}">${ICONS.play}</button>`
            : `<button type="button" class="timer-icon-btn timer-icon-btn--pause js-pause-timer" data-entry-id="${timer.id}" title="${t('pause')}" aria-label="${t('pause')}">${ICONS.pause}</button>`;

        return `
            <article class="timer-item${paused ? ' is-paused' : ''}" data-entry-id="${timer.id}">
                <span class="project-dot timer-item__dot" style="background:${escapeHtml(timer.project_color || '#3b82f6')}"></span>
                <div class="timer-item__content">
                    <div class="timer-item__row">
                        <span class="timer-item__project">${escapeHtml(timer.project_name || t('project_fallback'))}</span>
                        <time class="timer-item__clock" data-clock-for="${timer.id}">${formatLiveClock(timer.elapsed_seconds || 0)}</time>
                    </div>
                    <div class="timer-item__row">
                        <span class="timer-item__task">${escapeHtml(timer.task_name || t('no_work'))}</span>
                        <div class="timer-item__actions">
                            ${toggleBtn}
                            <button type="button" class="timer-icon-btn timer-icon-btn--stop js-stop-timer" data-entry-id="${timer.id}" title="${t('stop')}" aria-label="${t('stop')}">${ICONS.stop}</button>
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
                        clock.textContent = formatLiveClock(timer.elapsed_seconds);
                    }
                });

                refreshTrackedLive(timers);
            }, 1000);
        }

        notify();
    }

    function applyStatus(status) {
        timers = (status && status.timers) ? status.timers.map((item) => ({ ...item })) : [];
        render();
    }

    async function collectStopData(timer) {
        if (stopModal?.open) {
            try {
                const result = await stopModal.open(timer);
                if (result?.comment?.trim()) {
                    return {
                        comment: result.comment.trim(),
                        activity_id: result.activity_id || '',
                        activity_name: result.activity_name || '',
                    };
                }
                // User cancelled modal — do not stop.
                if (result === null) {
                    return null;
                }
            } catch {
                // Fall through to prompt.
            }
        }

        const fallbackComment = (window.prompt(t('stop_note_prompt'), '') || '').trim();
        if (!fallbackComment) {
            return null;
        }

        return {
            comment: fallbackComment,
            activity_id: '',
            activity_name: '',
        };
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
            return;
        }

        if (resumeBtn) {
            const data = await postTimerAction('/api/timer/resume', resumeBtn.dataset.entryId);
            if (data) {
                applyStatus(data.status);
            }
            return;
        }

        if (!stopBtn) {
            return;
        }

        const timer = timers.find((item) => String(item.id) === String(stopBtn.dataset.entryId));
        const stopData = await collectStopData(timer);

        if (!stopData?.comment) {
            return;
        }

        const data = await postTimerAction('/api/timer/stop', stopBtn.dataset.entryId, {
            notes: stopData.comment,
            comment: stopData.comment,
            activity_id: stopData.activity_id || '',
            activity_name: stopData.activity_name || '',
        });

        if (data) {
            applyStatus(data.status);
            updateDashboardAfterStop(data);
            if (data.planio_pushed) {
                alert(t('planio_push_success'));
            } else if (data.planio_error) {
                alert(data.planio_error || t('planio_push_failed'));
            }
        }
    });

    return { applyStatus };
}
