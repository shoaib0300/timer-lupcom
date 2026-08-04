import { formatLiveClock, t } from './utils.js';
import { fetchOfficeStatus, postOfficeAction, startOffice } from './office-api.js';
import { prependSessionRow } from './manual-entry.js?v=4';
import {
    applyOfficeStatus,
    setOfficeToday,
} from './dashboard-stats.js';

function localDateString() {
    const date = new Date();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${date.getFullYear()}-${month}-${day}`;
}

export function initOfficeClock() {
    const root = document.getElementById('office-clock');
    if (!root) {
        return;
    }

    const elapsedEl = document.getElementById('office-elapsed');
    const timerDisplay = document.getElementById('office-timer-display');
    const startBtn = document.getElementById('office-start-btn');
    const pauseBtn = document.getElementById('office-pause-btn');
    const resumeBtn = document.getElementById('office-resume-btn');
    const stopBtn = document.getElementById('office-stop-btn');
    const statusLabel = document.getElementById('office-status-label');
    const feedbackEl = document.getElementById('office-feedback');

    let session = null;
    let officeTodayBase = 0;
    let tickInterval = null;
    let workDate = localDateString();
    let elapsedAnchor = { base: 0, since: null };

    function showFeedback(message, isError = false) {
        if (!feedbackEl) {
            return;
        }
        feedbackEl.textContent = message;
        feedbackEl.classList.toggle('is-hidden', !message);
        feedbackEl.classList.toggle('is-error', isError);
    }

    function setButtons(active, paused) {
        if (startBtn) startBtn.hidden = active;
        if (pauseBtn) pauseBtn.hidden = !active || paused;
        if (resumeBtn) resumeBtn.hidden = !active || !paused;
        if (stopBtn) stopBtn.hidden = !active;
    }

    function currentElapsed() {
        if (!session) {
            return 0;
        }

        if (session.is_paused || elapsedAnchor.since === null) {
            return session.elapsed_seconds || 0;
        }

        return elapsedAnchor.base + Math.floor((Date.now() - elapsedAnchor.since) / 1000);
    }

    function syncElapsedAnchor() {
        if (!session || session.is_paused) {
            elapsedAnchor = {
                base: session?.elapsed_seconds || 0,
                since: null,
            };
            return;
        }

        elapsedAnchor = {
            base: session.elapsed_seconds || 0,
            since: Date.now(),
        };
    }

    function renderElapsed() {
        const elapsed = currentElapsed();

        if (elapsedEl) {
            elapsedEl.textContent = formatLiveClock(elapsed);
            elapsedEl.dataset.sessionId = session?.id ? String(session.id) : '';
        }

        if (session && !session.is_paused) {
            setOfficeToday(officeTodayBase + elapsed, { live: true });
        }
    }

    function stopTick() {
        if (tickInterval) {
            clearInterval(tickInterval);
            tickInterval = null;
        }
    }

    function checkDayRollover() {
        const today = localDateString();
        if (today === workDate) {
            return;
        }

        workDate = today;
        stopTick();
        session = null;
        officeTodayBase = 0;
        elapsedAnchor = { base: 0, since: null };

        if (elapsedEl) {
            elapsedEl.textContent = formatLiveClock(0);
            elapsedEl.dataset.sessionId = '';
        }

        fetchOfficeStatus().then(applyStatus).catch(() => {});
    }

    function startTick() {
        stopTick();
        if (!session || session.is_paused) {
            return;
        }

        const tick = () => {
            checkDayRollover();
            if (!session || session.is_paused) {
                return;
            }

            const elapsed = currentElapsed();
            session = { ...session, elapsed_seconds: elapsed };
            renderElapsed();
        };

        tick();
        tickInterval = setInterval(tick, 1000);
    }

    function applyStatus(status) {
        session = status?.session ? { ...status.session } : null;
        const active = Boolean(status?.active);

        root.dataset.active = active ? '1' : '0';

        if (timerDisplay) {
            timerDisplay.classList.toggle('is-paused', Boolean(session?.is_paused));
            timerDisplay.classList.toggle('is-idle', !active);
        }

        if (statusLabel) {
            if (!active) {
                statusLabel.textContent = t('office.clock_in_prompt');
            } else if (session?.is_paused) {
                statusLabel.textContent = t('office.paused_label');
            } else {
                statusLabel.textContent = t('office.in_office');
            }
        }

        setButtons(active, Boolean(session?.is_paused));

        if (status) {
            const officeTotal = status.office_today_seconds || 0;
            const sessionElapsed = session?.elapsed_seconds || 0;
            officeTodayBase = active ? Math.max(0, officeTotal - sessionElapsed) : officeTotal;

            applyOfficeStatus(status);
        }

        syncElapsedAnchor();
        renderElapsed();

        if (active && !session?.is_paused) {
            startTick();
        } else {
            stopTick();
        }
    }

    startBtn?.addEventListener('click', async () => {
        const data = await startOffice();
        if (data) {
            applyStatus(data.status);
            showFeedback(data.message);
        }
    });

    pauseBtn?.addEventListener('click', async () => {
        if (!session?.id) return;
        const data = await postOfficeAction('/api/office/pause', session.id);
        if (data) {
            applyStatus(data.status);
            showFeedback(data.message);
        }
    });

    resumeBtn?.addEventListener('click', async () => {
        if (!session?.id) return;
        const data = await postOfficeAction('/api/office/resume', session.id);
        if (data) {
            applyStatus(data.status);
            showFeedback(data.message);
        }
    });

    stopBtn?.addEventListener('click', async () => {
        if (!session?.id) return;
        const data = await postOfficeAction('/api/office/stop', session.id);
        if (!data) return;

        applyStatus(data.status);

        let message = data.message;
        if (data.session?.unassigned_seconds > 0) {
            message += ` ${t('office.gap_logged', {
                time: data.session.unassigned_human,
            })}`;
        }

        if (data.total_today_seconds !== undefined) {
            applyOfficeStatus({
                tracked_today_seconds: data.total_today_seconds,
                tracked_today_human: data.total_today_human,
            });
        }

        showFeedback(message);

        if (data.gap_entry) {
            prependSessionRow({
                project_name: null,
                project_color: '#64748b',
                task_name: data.gap_entry.notes,
                reason: data.gap_entry.notes,
                is_general: true,
                duration_human: data.gap_entry.duration_human,
                ended_at: data.session.ended_at,
            });
        }
    });

    const initial = window.__OFFICE_INITIAL__;
    if (initial) {
        applyStatus(initial);
    } else {
        fetchOfficeStatus().then(applyStatus).catch(() => {});
    }
}

initOfficeClock();
