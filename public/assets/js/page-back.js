export function initPageBack() {
    const button = document.getElementById('page-back');
    if (!button) {
        return;
    }

    const fallback = button.dataset.fallback || '/';
    const header = document.querySelector('.container .page-header');

    if (header) {
        header.classList.add('page-header--with-back');
        header.insertBefore(button, header.firstChild);
    }

    button.addEventListener('click', () => {
        if (window.history.length > 1) {
            window.history.back();
            return;
        }

        if (document.referrer && document.referrer !== window.location.href) {
            window.location.assign(document.referrer);
            return;
        }

        window.location.assign(fallback);
    });
}
