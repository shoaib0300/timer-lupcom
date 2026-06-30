const MOBILE_QUERY = '(max-width: 768px)';

export function initQuickDrawer() {
    const sidebar = document.getElementById('quick-sidebar');
    const tab = document.getElementById('quick-drawer-tab');
    const backdrop = document.getElementById('quick-drawer-backdrop');
    const closeBtn = document.getElementById('quick-drawer-close');

    if (!sidebar || !tab || !backdrop) {
        return null;
    }

    let open = false;
    let touchStartX = 0;
    let touchStartY = 0;

    function isMobile() {
        return window.matchMedia(MOBILE_QUERY).matches;
    }

    function setOpen(next) {
        if (!isMobile()) {
            open = false;
            sidebar.classList.remove('is-open');
            backdrop.hidden = true;
            document.body.classList.remove('quick-drawer-open');
            tab.setAttribute('aria-expanded', 'false');
            return;
        }

        open = next;
        sidebar.classList.toggle('is-open', open);
        backdrop.hidden = !open;
        document.body.classList.toggle('quick-drawer-open', open);
        tab.setAttribute('aria-expanded', open ? 'true' : 'false');

        if (open) {
            const input = document.getElementById('task-quick-search-input');
            if (input && document.activeElement !== input) {
                window.setTimeout(() => input.focus(), 280);
            }
        }
    }

    tab.addEventListener('click', () => setOpen(true));
    closeBtn?.addEventListener('click', () => setOpen(false));
    backdrop.addEventListener('click', () => setOpen(false));

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && open) {
            setOpen(false);
        }
    });

    window.addEventListener('resize', () => {
        if (!isMobile() && open) {
            setOpen(false);
        }
    });

    sidebar.addEventListener('touchstart', (event) => {
        if (!open || event.touches.length !== 1) {
            return;
        }
        touchStartX = event.touches[0].clientX;
        touchStartY = event.touches[0].clientY;
    }, { passive: true });

    sidebar.addEventListener('touchend', (event) => {
        if (!open || event.changedTouches.length !== 1) {
            return;
        }
        const deltaX = event.changedTouches[0].clientX - touchStartX;
        const deltaY = event.changedTouches[0].clientY - touchStartY;
        if (Math.abs(deltaX) > 60 && Math.abs(deltaX) > Math.abs(deltaY) && deltaX > 0) {
            setOpen(false);
        }
    }, { passive: true });

    tab.addEventListener('touchstart', (event) => {
        if (open || event.touches.length !== 1) {
            return;
        }
        touchStartX = event.touches[0].clientX;
        touchStartY = event.touches[0].clientY;
    }, { passive: true });

    tab.addEventListener('touchend', (event) => {
        if (open || event.changedTouches.length !== 1) {
            return;
        }
        const deltaX = event.changedTouches[0].clientX - touchStartX;
        const deltaY = event.changedTouches[0].clientY - touchStartY;
        if (Math.abs(deltaX) > 50 && Math.abs(deltaX) > Math.abs(deltaY) && deltaX < 0) {
            setOpen(true);
        }
    }, { passive: true });

    return { setOpen, isMobile };
}
