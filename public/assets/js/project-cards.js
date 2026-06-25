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

    const increment = Number.parseInt(grid.dataset.showIncrement || '3', 10);

    function collapsedCards() {
        return [...grid.querySelectorAll('.project-card--collapsed')];
    }

    function updateButton() {
        const hidden = collapsedCards();

        if (hidden.length === 0) {
            button.hidden = true;
            return;
        }

        const nextCount = Math.min(increment, hidden.length);
        const label = nextCount === 1 ? 'project' : 'projects';
        button.textContent = `Show ${nextCount} more ${label}`;
        button.hidden = false;
    }

    updateButton();

    button.addEventListener('click', () => {
        collapsedCards()
            .slice(0, increment)
            .forEach((card) => card.classList.remove('project-card--collapsed'));

        updateButton();
    });
}
