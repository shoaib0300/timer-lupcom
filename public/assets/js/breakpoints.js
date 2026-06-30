export const TABLET_QUERY = '(max-width: 1024px)';

export function isTablet() {
    return window.matchMedia(TABLET_QUERY).matches;
}
