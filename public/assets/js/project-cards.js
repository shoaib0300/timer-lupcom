import { attachProjectSearch } from './projects-search.js';
import { t } from './utils.js';

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

function restoreCollapsedState(grid) {
    const limit = Number.parseInt(grid.dataset.visibleLimit || '3', 10);
    [...grid.querySelectorAll('.project-card[data-project-id]')].forEach((card, index) => {
        card.classList.remove('project-card--hidden');
        card.classList.toggle('project-card--collapsed', index >= limit);
    });
}

function initProjectGridSearch(grid, showMoreButton) {
    const input = document.getElementById('project-search');

    if (!input || !grid) {
        return;
    }

    attachProjectSearch(
        input,
        () => [...grid.querySelectorAll('.project-card[data-project-id]')].map((card) => ({
            id: card.dataset.projectId || '',
            searchText: [
                card.dataset.projectName || '',
                card.dataset.planioId || '',
            ].join(' ').trim(),
            show: () => {
                card.classList.remove('project-card--hidden');
                card.classList.remove('project-card--collapsed');
            },
            hide: () => {
                card.classList.add('project-card--hidden');
            },
        })),
        {
            emptyEl: document.getElementById('project-search-empty'),
            onActiveChange: (active) => {
                if (!active) {
                    restoreCollapsedState(grid);
                }
                if (showMoreButton) {
                    showMoreButton.hidden = active;
                    if (!active) {
                        const event = new Event('input', { bubbles: true });
                        document.getElementById('project-search')?.dispatchEvent(event);
                    }
                }
            },
        },
    );
}

export function initProjectGridExpand() {
    const button = document.querySelector('.js-show-more-projects');
    const grid = document.getElementById('projects-grid');

    if (!grid) {
        return;
    }

    initProjectGridSearch(grid, button);

    if (!button) {
        return;
    }

    const increment = Number.parseInt(grid.dataset.showIncrement || '3', 10);

    function collapsedCards() {
        return [...grid.querySelectorAll('.project-card--collapsed:not(.project-card--hidden)')];
    }

    function updateButton() {
        const input = document.getElementById('project-search');
        if (input?.value.trim()) {
            button.hidden = true;
            return;
        }

        const hidden = collapsedCards();

        if (hidden.length === 0) {
            button.hidden = true;
            return;
        }

        const nextCount = Math.min(increment, hidden.length);
        button.textContent = t('show_more_projects', { count: nextCount });
        button.hidden = false;
    }

    updateButton();

    button.addEventListener('click', () => {
        collapsedCards()
            .slice(0, increment)
            .forEach((card) => card.classList.remove('project-card--collapsed'));

        updateButton();
    });

    const input = document.getElementById('project-search');
    input?.addEventListener('input', updateButton);
}

export function initProjectsTableSearch() {
    const input = document.getElementById('project-search');
    const tbody = document.querySelector('#projects-table tbody');

    if (!input || !tbody) {
        return;
    }

    attachProjectSearch(
        input,
        () => [...tbody.querySelectorAll('tr[data-project-id]')].map((row) => ({
            id: row.dataset.projectId || '',
            searchText: [
                row.dataset.projectName || '',
                row.dataset.planioId || '',
            ].join(' ').trim(),
            show: () => {
                row.hidden = false;
            },
            hide: () => {
                row.hidden = true;
            },
        })),
        {
            emptyEl: document.getElementById('project-search-empty'),
        },
    );
}
