import { fetchTimerStatus } from './timer-api.js?v=57';
import { createTimerSidebar } from './timer-sidebar.js?v=57';
import { createTimerModal } from './timer-modal.js?v=57';
import { syncProjectCards, initProjectGridExpand } from './project-cards.js?v=57';
import { initProjectTasksRefresh } from './project-tasks.js?v=57';
import { initTaskQuickStart, syncTaskQuickStart } from './task-quick-start.js?v=57';
import { initPageBack } from './page-back.js?v=57';
import { initThemeToggle } from './theme.js?v=57';
import { initNavMenu } from './nav-menu.js?v=57';
import { initQuickDrawer } from './quick-drawer.js?v=57';
import { initTimerDrawer } from './timer-drawer.js?v=57';
import { createTimerStopModal } from './timer-stop-modal.js?v=57';
import './planio-time-sync.js?v=57';

initPageBack();
initThemeToggle();
initNavMenu();
initQuickDrawer();

const listEl = document.getElementById('timer-list');
const emptyEl = document.getElementById('timer-empty');
const countEl = document.getElementById('timer-count');
const modal = document.getElementById('timer-modal');
const stopModal = document.getElementById('timer-stop-modal');
const modalProject = document.getElementById('timer-modal-project');
const startForm = document.getElementById('timer-start-form');

if (listEl && emptyEl && countEl) {
    const drawer = initTimerDrawer();
    const timerStopModal = createTimerStopModal(stopModal);

    const sidebar = createTimerSidebar(listEl, emptyEl, countEl, (status) => {
        syncProjectCards(status.timers || []);
        syncTaskQuickStart(status.timers || []);
        drawer?.updateCount(status.timers?.length || 0);
    }, timerStopModal);

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
