export function formatHuman(seconds) {
    const safe = Math.max(0, Number(seconds) || 0);
    const h = Math.floor(safe / 3600);
    const m = Math.floor((safe % 3600) / 60);
    if (h > 0) {
        return `${h}h ${m}m`;
    }
    return `${m}m`;
}

function setStat(selector, seconds, { human } = {}) {
    const label = human ?? formatHuman(seconds);
    document.querySelectorAll(selector).forEach((el) => {
        el.textContent = label;
        el.dataset.totalSeconds = String(seconds);
    });
}

let trackedCompletedBase = 0;

export function initTrackedBase(seconds) {
    trackedCompletedBase = Math.max(0, Number(seconds) || 0);
    setStat('.js-total-today', trackedCompletedBase);
    setStat('.js-tracked-today', trackedCompletedBase);
}

export function setTrackedCompleted(seconds, human) {
    trackedCompletedBase = Math.max(0, Number(seconds) || 0);
    setStat('.js-total-today', trackedCompletedBase, { human });
    setStat('.js-tracked-today', trackedCompletedBase, { human });
}

export function refreshTrackedLive(runningTimers = []) {
    const runningSeconds = runningTimers.reduce(
        (sum, timer) => sum + (Number(timer.elapsed_seconds) || 0),
        0,
    );
    const total = trackedCompletedBase + runningSeconds;
    setStat('.js-total-today', total);
    setStat('.js-tracked-today', total);
}

export function setOfficeToday(seconds) {
    setStat('.js-office-today', seconds);
}

export function setUnassignedToday(seconds) {
    setStat('.js-unassigned-today', seconds);
}

/** @param {{ tracked_today_seconds?: number, office_today_seconds?: number, unassigned_today_seconds?: number, tracked_today_human?: string }} status */
export function applyOfficeStatus(status) {
    if (!status) {
        return;
    }

    if (status.tracked_today_seconds !== undefined) {
        setTrackedCompleted(
            status.tracked_today_seconds,
            status.tracked_today_human,
        );
    }

    if (status.office_today_seconds !== undefined) {
        setOfficeToday(status.office_today_seconds);
    }

    if (status.unassigned_today_seconds !== undefined) {
        setUnassignedToday(status.unassigned_today_seconds);
    }
}

/** @param {{ total_today_seconds?: number, total_today_human?: string, unassigned_today_seconds?: number }} data */
export function applyTimerStopData(data) {
    if (data.total_today_seconds !== undefined) {
        setTrackedCompleted(data.total_today_seconds, data.total_today_human);
    }

    if (data.unassigned_today_seconds !== undefined) {
        setUnassignedToday(data.unassigned_today_seconds);
    }
}

const totalTodayEl = document.querySelector('.js-total-today');
if (totalTodayEl?.dataset.totalSeconds !== undefined) {
    initTrackedBase(totalTodayEl.dataset.totalSeconds);
}
