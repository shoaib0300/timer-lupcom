import Fuse from 'fuse.js';

/**
 * @param {HTMLInputElement} inputEl
 * @param {() => Array<{ id: string, searchText: string, show: () => void, hide: () => void }>} getItems
 * @param {{ onActiveChange?: (active: boolean) => void, emptyEl?: HTMLElement | null }} [options]
 */
export function attachProjectSearch(inputEl, getItems, options = {}) {
    const { onActiveChange, emptyEl } = options;
    let fuse = new Fuse([], { keys: ['searchText'], threshold: 0.35, ignoreLocation: true });

    function rebuildIndex() {
        const items = getItems();
        fuse = new Fuse(items, { keys: ['searchText'], threshold: 0.35, ignoreLocation: true });
        return items;
    }

    function applyFilter() {
        const query = inputEl.value.trim();
        const items = rebuildIndex();

        if (!query) {
            items.forEach((item) => item.show());
            emptyEl?.classList.add('is-hidden');
            onActiveChange?.(false);
            return;
        }

        onActiveChange?.(true);
        const visibleIds = new Set(fuse.search(query).map((result) => result.item.id));

        items.forEach((item) => {
            if (visibleIds.has(item.id)) {
                item.show();
            } else {
                item.hide();
            }
        });

        if (emptyEl) {
            emptyEl.classList.toggle('is-hidden', visibleIds.size > 0);
        }
    }

    inputEl.addEventListener('input', applyFilter);
    inputEl.addEventListener('search', applyFilter);
}
