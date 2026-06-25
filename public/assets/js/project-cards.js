export function syncProjectCards(timers) {
    document.querySelectorAll('.project-card[data-project-id]').forEach((card) => {
        const projectId = card.dataset.projectId;
        const isRunning = timers.some((t) => String(t.project_id) === String(projectId));
        const badge = card.querySelector('.js-project-running-badge');

        card.classList.toggle('is-tracking', isRunning);

        if (badge) {
            badge.hidden = !isRunning;
        }
    });
}

export function initProjectGridExpand() {
    const button = document.querySelector('.js-show-more-projects');
    const grid = document.getElementById('projects-grid');

    if (!button || !grid) {
        return;
    }

    button.addEventListener('click', () => {
        grid.classList.add('is-expanded');
        grid.querySelectorAll('.project-card--collapsed').forEach((card) => {
            card.classList.remove('project-card--collapsed');
        });
        button.hidden = true;
    });
}
