const STORAGE_KEY = 'timer-theme';
const TRANSITION_MS = 1650;
const THEME_SWITCH_AT = 720;

function getTheme() {
    return document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
}

function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function updateToggleLabel(theme) {
    const btn = document.getElementById('theme-toggle');
    if (!btn) {
        return;
    }

    const label = theme === 'light'
        ? (window.__I18N__?.theme_dark ?? 'Dark mode')
        : (window.__I18N__?.theme_light ?? 'Light mode');
    btn.setAttribute('aria-label', label);
    btn.setAttribute('title', label);
}

function applyTheme(theme, { persist = true } = {}) {
    document.documentElement.setAttribute('data-theme', theme);

    if (persist) {
        localStorage.setItem(STORAGE_KEY, theme);
    }

    updateToggleLabel(theme);
}

function runOverlayTransition(nextTheme) {
    const overlay = document.getElementById('theme-transition');
    if (!overlay) {
        applyTheme(nextTheme);
        return;
    }

    const isSunset = nextTheme === 'dark';
    const className = isSunset ? 'theme-transition--sunset' : 'theme-transition--sunrise';

    document.documentElement.classList.add('theme-changing');
    overlay.className = `theme-transition ${className}`;
    overlay.removeAttribute('hidden');

    const btn = document.getElementById('theme-toggle');
    if (btn) {
        btn.disabled = true;
    }

    window.setTimeout(() => {
        applyTheme(nextTheme);
    }, THEME_SWITCH_AT);

    window.setTimeout(() => {
        overlay.className = 'theme-transition';
        overlay.setAttribute('hidden', '');
        document.documentElement.classList.remove('theme-changing');
        if (btn) {
            btn.disabled = false;
        }
    }, TRANSITION_MS);
}

function transitionTheme(nextTheme) {
    if (nextTheme === getTheme()) {
        return;
    }

    if (prefersReducedMotion()) {
        applyTheme(nextTheme);
        return;
    }

    runOverlayTransition(nextTheme);
}

export function initThemeToggle() {
    const btn = document.getElementById('theme-toggle');
    if (!btn) {
        return;
    }

    updateToggleLabel(getTheme());

    btn.addEventListener('click', () => {
        if (btn.disabled) {
            return;
        }

        transitionTheme(getTheme() === 'dark' ? 'light' : 'dark');
    });
}
