import { startTimer } from './timer-api.js';

export function createTimerModal(modal, modalProject, startForm, taskNameInput, onStarted) {
    let pendingProject = null;

    function open(projectId, projectName, defaultTaskName) {
        pendingProject = { id: projectId, name: projectName };
        modalProject.textContent = projectName;
        taskNameInput.value = defaultTaskName || 'no-work';
        modal.classList.remove('is-hidden');
        modal.setAttribute('aria-hidden', 'false');
        taskNameInput.focus();
        taskNameInput.select();
    }

    function close() {
        modal.classList.add('is-hidden');
        modal.setAttribute('aria-hidden', 'true');
        pendingProject = null;
        startForm.reset();
    }

    document.addEventListener('click', (event) => {
        const button = event.target.closest('.js-start-timer');
        if (!button) {
            return;
        }

        open(
            button.dataset.projectId,
            button.dataset.projectName,
            button.dataset.taskName || 'no-work',
        );
    });

    startForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!pendingProject) {
            return;
        }

        const taskName = taskNameInput.value.trim() || 'no-work';
        const projectId = pendingProject.id;
        close();

        const data = await startTimer(projectId, taskName);
        if (data) {
            onStarted(data.status, data);
        }
    });

    modal.querySelectorAll('[data-modal-close]').forEach((el) => {
        el.addEventListener('click', close);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.classList.contains('is-hidden')) {
            close();
        }
    });

    return { open, close };
}
