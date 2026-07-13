import { withCsrf } from './csrf.js';
import { t } from './utils.js';

function bindButton(button) {
    if (!button || button.dataset.bound === '1') {
        return;
    }

    button.dataset.bound = '1';
    const defaultLabel = button.textContent;

    button.addEventListener('click', async () => {
        const feedbackId = button.dataset.feedbackId || 'planio-sync-feedback';
        const feedbackEl = document.getElementById(feedbackId);
        const month = button.dataset.month || '';
        const reloadOnSuccess = button.dataset.reload === '1';

        button.setAttribute('disabled', 'disabled');
        button.textContent = t('planio_time_syncing');

        if (feedbackEl) {
            feedbackEl.classList.remove('is-hidden', 'settings-page__feedback--error', 'alert--error', 'alert--success');
            feedbackEl.classList.add('alert', 'alert--info');
            feedbackEl.textContent = t('planio_time_syncing');
        }

        try {
            const body = withCsrf();
            if (month) {
                body.append('month', month);
            }

            const response = await fetch('/api/planio/sync-time', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body,
            });
            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error === 'invalid_csrf'
                    ? t('session_expired_refresh')
                    : (data.error || t('planio_time_sync_failed')));
            }

            const message = data.message || t('planio_time_sync_done');

            if (feedbackEl) {
                feedbackEl.classList.remove('alert--info');
                feedbackEl.classList.add('alert--success');
                feedbackEl.textContent = message;
            }

            if (reloadOnSuccess) {
                window.setTimeout(() => window.location.reload(), 1200);
            }
        } catch (error) {
            if (feedbackEl) {
                feedbackEl.classList.remove('alert--info');
                feedbackEl.classList.add('alert--error', 'settings-page__feedback--error');
                feedbackEl.textContent = error.message;
            } else {
                window.alert(error.message);
            }
        } finally {
            button.removeAttribute('disabled');
            button.textContent = defaultLabel;
        }
    });
}

export function initPlanioTimeSync(root = document) {
    root.querySelectorAll('.js-planio-sync-time').forEach(bindButton);
}

function boot() {
    initPlanioTimeSync();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
