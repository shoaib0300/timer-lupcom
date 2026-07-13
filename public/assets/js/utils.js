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
    return [h, m].map((v) => String(v).padStart(2, '0')).join(':');
}

const MONTH_ABBR = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];

export function formatCompactDateTime(value) {
    if (!value) {
        return '';
    }

    const date = new Date(value.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    const day = String(date.getDate()).padStart(2, '0');
    const month = MONTH_ABBR[date.getMonth()];
    const year = String(date.getFullYear()).slice(-2);
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');

    return `${day}-${month}-${year} ${hours}:${minutes}`;
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
