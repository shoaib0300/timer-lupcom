import { fetchTimerStatus } from './timer-api.js';
import { createTimerSidebar } from './timer-sidebar.js';
import { createTimerModal } from './timer-modal.js';
import { syncProjectCards, initProjectGridExpand } from './project-cards.js';
import { initProjectTasksRefresh } from './project-tasks.js';
import { initTaskQuickStart, syncTaskQuickStart } from './task-quick-start.js';
import { initPageBack } from './page-back.js';
import { initThemeToggle } from './theme.js';

initPageBack();
initThemeToggle();

const listEl = document.getElementById('timer-list');
const emptyEl = document.getElementById('timer-empty');
const countEl = document.getElementById('timer-count');
const modal = document.getElementById('timer-modal');
const modalProject = document.getElementById('timer-modal-project');
const startForm = document.getElementById('timer-start-form');

if (listEl && emptyEl && countEl) {
    const sidebar = createTimerSidebar(listEl, emptyEl, countEl, (status) => {
        syncProjectCards(status.timers || []);
        syncTaskQuickStart(status.timers || []);
    });

    const onTimerStarted = (status) => {
        sidebar.applyStatus(status);
        syncTaskQuickStart(status.timers || []);
    };

    const onTimerStartedWithRefresh = initProjectTasksRefresh(onTimerStarted);

    initTaskQuickStart(onTimerStartedWithRefresh);

    if (modal && modalProject && startForm) {
        createTimerModal(
            modal,
            modalProject,
            startForm,
            onTimerStartedWithRefresh,
        );
    }

    const initial = window.__TIMER_INITIAL__;
    if (initial) {
        sidebar.applyStatus(initial);
        syncTaskQuickStart(initial.timers || []);
    } else {
        fetchTimerStatus().then((status) => {
            sidebar.applyStatus(status);
            syncTaskQuickStart(status.timers || []);
        }).catch(() => {});
    }

    initProjectGridExpand();
}
