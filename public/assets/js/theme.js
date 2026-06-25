const STORAGE_KEY = 'timer-theme';

function getTheme() {
    return document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
}

function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem(STORAGE_KEY, theme);

    const btn = document.getElementById('theme-toggle');
    if (btn) {
        const label = theme === 'light'
            ? (window.__I18N__?.theme_dark ?? 'Dark mode')
            : (window.__I18N__?.theme_light ?? 'Light mode');
        btn.setAttribute('aria-label', label);
        btn.setAttribute('title', label);
    }
}

export function initThemeToggle() {
    const btn = document.getElementById('theme-toggle');
    if (!btn) {
        return;
    }

    applyTheme(getTheme());

    btn.addEventListener('click', () => {
        applyTheme(getTheme() === 'dark' ? 'light' : 'dark');
    });
}
