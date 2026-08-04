import { deleteTimeEntry } from './timer-api.js';
import { t } from './utils.js';

const projectSelect = document.getElementById('filter-project');
const taskSelect = document.getElementById('filter-task');
const filterForm = document.getElementById('reports-filter-form');

if (projectSelect && taskSelect && filterForm) {
    projectSelect.addEventListener('change', () => {
        taskSelect.value = '';
        filterForm.submit();
    });

    taskSelect.addEventListener('change', () => {
        filterForm.submit();
    });
}

document.addEventListener('click', async (event) => {
    const button = event.target instanceof Element
        ? event.target.closest('.js-report-entry-delete')
        : null;
    if (!button) {
        return;
    }

    const entryId = Number(button.dataset.entryId || 0);
    if (!entryId) {
        return;
    }

    const hasPlanio = Boolean(button.dataset.planioTimeEntryId);
    const confirmMessage = hasPlanio
        ? t('entry_delete_confirm_planio')
        : t('entry_delete_confirm');
    if (!window.confirm(confirmMessage)) {
        return;
    }

    const result = await deleteTimeEntry(entryId);
    if (!result?.deleted) {
        return;
    }

    button.closest('tr')?.remove();

    const tbody = document.querySelector('.reports-page__day-detail tbody');
    if (tbody && tbody.children.length === 0) {
        window.location.reload();
        return;
    }

    if (result.planio_error) {
        alert(t('entry_delete_success_planio_warn', { error: result.planio_error }));
    } else {
        alert(t('entry_delete_success'));
    }
});
