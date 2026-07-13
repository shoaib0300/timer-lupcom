export function withCsrf(params = new URLSearchParams()) {
    if (typeof window.__CSRF__ === 'string' && window.__CSRF__ !== '') {
        params.set('_token', window.__CSRF__);
    }

    return params;
}

export function csrfField() {
    return typeof window.__CSRF__ === 'string' ? window.__CSRF__ : '';
}
