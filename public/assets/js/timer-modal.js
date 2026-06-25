import { fetchProjectTasks, startTimer } from './timer-api.js';

const NEW_TASK_VALUE = '__new__';

export function createTimerModal(modal, modalProject, startForm, onStarted) {
    const taskSelect = document.getElementById('timer-task-select');
    const newTaskGroup = document.getElementById('timer-new-task-group');
    const taskNameInput = document.getElementById('timer-task-name');

    if (!taskSelect || !newTaskGroup || !taskNameInput) {
        return { open: () => {}, close: () => {} };
    }

    let pendingProject = null;

    function setNewTaskMode(enabled, defaultName = '') {
        newTaskGroup.classList.toggle('is-hidden', !enabled);
        taskNameInput.required = enabled;

        if (enabled) {
            taskNameInput.value = defaultName;
            taskNameInput.focus();
            taskNameInput.select();
        } else {
            taskNameInput.value = '';
        }
    }

    function populateTaskSelect(tasks, preferredTaskName) {
        taskSelect.innerHTML = '';

        tasks.forEach((task) => {
            const option = document.createElement('option');
            option.value = String(task.id);
            option.textContent = task.name;
            option.dataset.taskName = task.name;
            taskSelect.appendChild(option);
        });

        const createOption = document.createElement('option');
        createOption.value = NEW_TASK_VALUE;
        createOption.textContent = '+ Create new task';
        taskSelect.appendChild(createOption);

        const preferred = preferredTaskName && preferredTaskName !== 'no-work'
            ? tasks.find((task) => task.name === preferredTaskName)
            : null;

        if (preferred) {
            taskSelect.value = String(preferred.id);
            setNewTaskMode(false);
            return;
        }

        if (tasks.length > 0) {
            taskSelect.selectedIndex = 0;
            setNewTaskMode(false);
            return;
        }

        taskSelect.value = NEW_TASK_VALUE;
        setNewTaskMode(true, preferredTaskName || 'no-work');
    }

    async function open(projectId, projectName, preferredTaskName = '') {
        pendingProject = { id: projectId, name: projectName };
        modalProject.textContent = projectName;
        modal.classList.remove('is-hidden');
        modal.setAttribute('aria-hidden', 'false');

        taskSelect.innerHTML = '<option value="">Loading tasks…</option>';
        taskSelect.disabled = true;
        setNewTaskMode(false);

        const tasks = await fetchProjectTasks(projectId);
        taskSelect.disabled = false;
        populateTaskSelect(tasks, preferredTaskName);

        if (taskSelect.value !== NEW_TASK_VALUE) {
            taskSelect.focus();
        }
    }

    function close() {
        modal.classList.add('is-hidden');
        modal.setAttribute('aria-hidden', 'true');
        pendingProject = null;
        startForm.reset();
        setNewTaskMode(false);
        taskSelect.innerHTML = '<option value="">Loading tasks…</option>';
    }

    function resolveTaskName() {
        if (taskSelect.value === NEW_TASK_VALUE) {
            return taskNameInput.value.trim() || 'no-work';
        }

        const selected = taskSelect.options[taskSelect.selectedIndex];
        return selected?.dataset.taskName || selected?.textContent || 'no-work';
    }

    taskSelect.addEventListener('change', () => {
        if (taskSelect.value === NEW_TASK_VALUE) {
            setNewTaskMode(true);
            return;
        }

        setNewTaskMode(false);
    });

    document.addEventListener('click', (event) => {
        const button = event.target.closest('.js-start-timer');
        if (!button) {
            return;
        }

        const preferredTaskName = button.dataset.taskName || '';
        open(button.dataset.projectId, button.dataset.projectName, preferredTaskName);
    });

    startForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (!pendingProject || !taskSelect.value) {
            return;
        }

        const taskName = resolveTaskName();
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
