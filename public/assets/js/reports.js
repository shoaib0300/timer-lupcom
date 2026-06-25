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
