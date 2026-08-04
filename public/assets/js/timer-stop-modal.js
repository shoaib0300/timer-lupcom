import { formatLiveClock, t } from './utils.js?v=56';

async function fetchPlanioActivities() {
    try {
        const response = await fetch('/api/planio/activities');
        if (!response.ok) {
            return [];
        }
        const data = await response.json();
        return Array.isArray(data.activities) ? data.activities : [];
    } catch {
        return [];
    }
}

function ensureModalElement() {
    let modalEl = document.getElementById('timer-stop-modal');
    if (modalEl) {
        return modalEl;
    }

    modalEl = document.createElement('div');
    modalEl.id = 'timer-stop-modal';
    modalEl.className = 'modal is-hidden';
    modalEl.setAttribute('aria-hidden', 'true');
    modalEl.innerHTML = `
        <div class="modal__backdrop" data-stop-modal-close></div>
        <div class="modal__dialog" role="dialog" aria-labelledby="timer-stop-title">
            <h3 id="timer-stop-title" class="modal__title">${t('stop_log_time')}</h3>
            <form id="timer-stop-form">
                <div class="form-group">
                    <label for="timer-stop-spent-time">${t('spent_time') || 'Spent time'}</label>
                    <input type="text" id="timer-stop-spent-time" readonly>
                </div>
                <div class="form-group">
                    <label for="timer-stop-activity">${t('activity') || 'Activity'}</label>
                    <select id="timer-stop-activity">
                        <option value="">${t('stop_activity_none')}</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="timer-stop-comment">${t('comment') || 'Comment'}</label>
                    <textarea id="timer-stop-comment" rows="4" required placeholder="${t('stop_note_prompt')}"></textarea>
                </div>
                <div class="actions">
                    <button type="submit" class="btn btn--success">${t('save') || 'Save'}</button>
                    <button type="button" class="btn btn--ghost" data-stop-modal-close>${t('cancel') || 'Cancel'}</button>
                </div>
            </form>
        </div>
    `;
    document.body.appendChild(modalEl);
    return modalEl;
}

export function createTimerStopModal(modalEl = null) {
    const root = modalEl || ensureModalElement();
    const formEl = root.querySelector('#timer-stop-form');
    const spentTimeEl = root.querySelector('#timer-stop-spent-time');
    const activityEl = root.querySelector('#timer-stop-activity');
    const commentEl = root.querySelector('#timer-stop-comment');
    const titleEl = root.querySelector('#timer-stop-title');

    if (!formEl || !spentTimeEl || !activityEl || !commentEl) {
        return {
            open: async () => {
                const comment = (window.prompt(t('stop_note_prompt'), '') || '').trim();
                return comment
                    ? { comment, activity_id: '', activity_name: '' }
                    : null;
            },
        };
    }

    let resolveFn = null;
    let loadedActivities = false;
    let activitiesLoading = null;

    function close(result = null) {
        root.classList.add('is-hidden');
        root.setAttribute('aria-hidden', 'true');
        formEl.reset();

        if (resolveFn) {
            const resolve = resolveFn;
            resolveFn = null;
            resolve(result);
        }
    }

    function loadActivities() {
        if (loadedActivities) {
            return Promise.resolve();
        }
        if (activitiesLoading) {
            return activitiesLoading;
        }

        activitiesLoading = fetchPlanioActivities().then((activities) => {
            activityEl.innerHTML = `<option value="">${t('stop_activity_none')}</option>`;
            activities.forEach((activity) => {
                if (!activity?.id || !activity?.name) {
                    return;
                }
                const option = document.createElement('option');
                option.value = String(activity.id);
                option.textContent = activity.name;
                activityEl.appendChild(option);
            });
            loadedActivities = true;
            activitiesLoading = null;
        }).catch(() => {
            activitiesLoading = null;
        });

        return activitiesLoading;
    }

    root.querySelectorAll('[data-stop-modal-close]').forEach((el) => {
        el.addEventListener('click', () => close(null));
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !root.classList.contains('is-hidden')) {
            close(null);
        }
    });

    formEl.addEventListener('submit', (event) => {
        event.preventDefault();
        const comment = commentEl.value.trim();
        if (!comment) {
            commentEl.focus();
            return;
        }

        const selectedActivity = activityEl.options[activityEl.selectedIndex];
        const activityId = activityEl.value || '';
        close({
            comment,
            activity_id: activityId,
            activity_name: activityId ? (selectedActivity?.textContent?.trim() || '') : '',
        });
    });

    async function open(timer) {
        if (titleEl) {
            titleEl.textContent = t('stop_log_time');
        }
        spentTimeEl.value = formatLiveClock(timer?.elapsed_seconds || 0);
        commentEl.value = '';
        root.classList.remove('is-hidden');
        root.setAttribute('aria-hidden', 'false');
        commentEl.focus();

        // Load activities in background — never block the form from showing.
        loadActivities();

        return new Promise((resolve) => {
            resolveFn = resolve;
        });
    }

    return { open };
}
