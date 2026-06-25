import { fetchTimerStatus } from './timer-api.js';
import { createTimerSidebar } from './timer-sidebar.js';
import { createTimerModal } from './timer-modal.js';
import { syncProjectCards, initProjectGridExpand } from './project-cards.js';
import { initProjectTasksRefresh } from './project-tasks.js';

const listEl = document.getElementById('timer-list');
const emptyEl = document.getElementById('timer-empty');
const countEl = document.getElementById('timer-count');
const modal = document.getElementById('timer-modal');
const modalProject = document.getElementById('timer-modal-project');
const startForm = document.getElementById('timer-start-form');
const taskNameInput = document.getElementById('timer-task-name');

if (listEl && emptyEl && countEl) {
    const sidebar = createTimerSidebar(listEl, emptyEl, countEl, (status) => {
        syncProjectCards(status.timers || []);
    });

    const onTimerStarted = (status) => {
        sidebar.applyStatus(status);
    };

    if (modal && modalProject && startForm && taskNameInput) {
        createTimerModal(
            modal,
            modalProject,
            startForm,
            taskNameInput,
            initProjectTasksRefresh(onTimerStarted),
        );
    }

    const initial = window.__TIMER_INITIAL__;
    if (initial) {
        sidebar.applyStatus(initial);
    } else {
        fetchTimerStatus().then(sidebar.applyStatus).catch(() => {});
    }

    initProjectGridExpand();
}
