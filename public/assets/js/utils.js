export const ICONS = {
    pause: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></svg>',
    play: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l11-6.5z"/></svg>',
    stop: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="7" y="7" width="10" height="10" rx="1.5"/></svg>',
    start: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l11-6.5z"/></svg>',
    trash: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 4h6l1 2h4v2H4V6h4l1-2zm1 6h2v9h-2V10zm4 0h2v9h-2V10zM7 10h2v9H7V10z"/></svg>',
};

export function formatClock(seconds) {
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    return [h, m, s].map((v) => String(v).padStart(2, '0')).join(':');
}

export function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

export function t(key, params = {}) {
    const strings = window.__I18N__ || {};
    let message = strings[key] ?? key;

    Object.entries(params).forEach(([name, value]) => {
        message = message.replaceAll(`:${name}`, String(value));
    });

    return message;
}
