let lockCount = 0;
let scrollY = 0;

export function lockScroll() {
    if (lockCount === 0) {
        scrollY = window.scrollY;
        document.body.style.position = 'fixed';
        document.body.style.top = `-${scrollY}px`;
        document.body.style.left = '0';
        document.body.style.right = '0';
        document.body.style.width = '100%';
        document.body.classList.add('overlay-scroll-lock');
    }
    lockCount += 1;
}

export function unlockScroll() {
    if (lockCount <= 0) {
        return;
    }
    lockCount -= 1;
    if (lockCount !== 0) {
        return;
    }
    document.body.style.position = '';
    document.body.style.top = '';
    document.body.style.left = '';
    document.body.style.right = '';
    document.body.style.width = '';
    document.body.classList.remove('overlay-scroll-lock');
    window.scrollTo(0, scrollY);
}
