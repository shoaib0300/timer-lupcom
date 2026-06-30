import { lockScroll, unlockScroll } from './scroll-lock.js';
import { isTablet } from './breakpoints.js';

export function initTimerDrawer() {
    const sidebar = document.getElementById('timer-sidebar');
    const tab = document.getElementById('timer-drawer-tab');
    const backdrop = document.getElementById('timer-drawer-backdrop');
    const closeBtn = document.getElementById('timer-drawer-close');
    const drawerCount = document.getElementById('timer-drawer-count');

    if (!sidebar || !tab || !backdrop) {
        return null;
    }

    let open = false;
    let touchStartX = 0;
    let touchStartY = 0;

    function setOpen(next) {
        if (!isTablet()) {
            if (open) {
                open = false;
                unlockScroll();
            }
            sidebar.classList.remove('is-open');
            backdrop.hidden = true;
            tab.setAttribute('aria-expanded', 'false');
            return;
        }

        if (next === open) {
            return;
        }

        open = next;
        sidebar.classList.toggle('is-open', open);
        backdrop.hidden = !open;
        tab.setAttribute('aria-expanded', open ? 'true' : 'false');

        if (open) {
            lockScroll();
        } else {
            unlockScroll();
        }
    }

    function updateCount(count) {
        const value = String(count);
        if (drawerCount) {
            drawerCount.textContent = value;
            drawerCount.hidden = count <= 0;
        }
        tab.classList.toggle('has-timers', count > 0);
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
        if (!isTablet() && open) {
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
        if (Math.abs(deltaX) > 60 && Math.abs(deltaX) > Math.abs(deltaY) && deltaX < 0) {
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
        if (Math.abs(deltaX) > 50 && Math.abs(deltaX) > Math.abs(deltaY) && deltaX > 0) {
            setOpen(true);
        }
    }, { passive: true });

    return { setOpen, updateCount, isTablet };
}
