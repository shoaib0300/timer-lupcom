function toggleTaskDescription(button) {
    const taskId = button.dataset.taskId;
    const detailRow = document.getElementById(`task-desc-${taskId}`);

    if (!detailRow) {
        return;
    }

    const expanded = button.getAttribute('aria-expanded') === 'true';
    button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    detailRow.classList.toggle('is-hidden', expanded);
}

export function initProjectTaskDetails(root = document) {
    root.querySelectorAll('.project-show__task-toggle').forEach((button) => {
        if (button.dataset.bound === '1') {
            return;
        }

        button.dataset.bound = '1';
        button.addEventListener('click', () => toggleTaskDescription(button));
    });
}
