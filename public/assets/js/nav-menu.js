const MENU_QUERY = '(max-width: 1024px)';

export function initNavMenu() {
    const toggle = document.getElementById('nav-toggle');
    const menu = document.getElementById('site-nav');
    const backdrop = document.getElementById('site-nav-backdrop');

    if (!toggle || !menu || !backdrop) {
        return;
    }

    const openLabel = toggle.dataset.labelOpen || toggle.getAttribute('aria-label') || 'Menu';
    const closeLabel = toggle.dataset.labelClose || openLabel;
    let open = false;

    function isCompact() {
        return window.matchMedia(MENU_QUERY).matches;
    }

    function setOpen(next) {
        if (!isCompact()) {
            open = false;
            menu.classList.remove('is-open');
            toggle.classList.remove('is-open');
            backdrop.hidden = true;
            document.body.classList.remove('site-nav-open');
            toggle.setAttribute('aria-expanded', 'false');
            toggle.setAttribute('aria-label', openLabel);
            return;
        }

        open = next;
        menu.classList.toggle('is-open', open);
        toggle.classList.toggle('is-open', open);
        backdrop.hidden = !open;
        document.body.classList.toggle('site-nav-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? closeLabel : openLabel);
    }

    toggle.addEventListener('click', () => setOpen(!open));
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
        if (!isCompact() && open) {
            setOpen(false);
        }
    });

    setOpen(false);
}
