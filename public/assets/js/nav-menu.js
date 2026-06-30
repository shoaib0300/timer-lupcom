import { lockScroll, unlockScroll } from './scroll-lock.js';
import { isTablet } from './breakpoints.js';

export function initNavMenu() {
    const toggle = document.getElementById('nav-toggle');
    const menu = document.getElementById('site-nav');
    const backdrop = document.getElementById('site-nav-backdrop');
    const closeBtn = document.getElementById('site-nav-close');
    const slot = document.getElementById('site-nav-slot');

    if (!toggle || !menu || !backdrop) {
        return;
    }

    const openLabel = toggle.dataset.labelOpen || toggle.getAttribute('aria-label') || 'Menu';
    const closeLabel = toggle.dataset.labelClose || openLabel;
    let open = false;

    function mountNav() {
        if (isTablet()) {
            if (menu.parentElement !== document.body) {
                document.body.appendChild(menu);
            }
            slot?.setAttribute('aria-hidden', 'true');
            return;
        }

        if (slot && menu.parentElement !== slot) {
            slot.appendChild(menu);
        }
        slot?.setAttribute('aria-hidden', 'false');
        menu.classList.remove('is-open');
        toggle.classList.remove('is-open');
        backdrop.hidden = true;
        if (open) {
            open = false;
            unlockScroll();
        }
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', openLabel);
    }

    function setOpen(next) {
        if (!isTablet()) {
            open = false;
            menu.classList.remove('is-open');
            toggle.classList.remove('is-open');
            backdrop.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
            toggle.setAttribute('aria-label', openLabel);
            unlockScroll();
            return;
        }

        if (next === open) {
            return;
        }

        open = next;
        menu.classList.toggle('is-open', open);
        toggle.classList.toggle('is-open', open);
        backdrop.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? closeLabel : openLabel);

        if (open) {
            lockScroll();
        } else {
            unlockScroll();
        }
    }

    mountNav();

    toggle.addEventListener('click', () => setOpen(!open));
    closeBtn?.addEventListener('click', () => setOpen(false));
    backdrop.addEventListener('click', () => setOpen(false));

    menu.addEventListener('click', (event) => {
        if (event.target.closest('.nav a')) {
            setOpen(false);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && open) {
            setOpen(false);
        }
    });

    window.addEventListener('resize', () => {
        mountNav();
        if (!isTablet() && open) {
            setOpen(false);
        }
    });

    setOpen(false);
}
