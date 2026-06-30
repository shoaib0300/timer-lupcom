export const TABLET_QUERY = '(max-width: 1024px)';
export const DESKTOP_COMPACT_QUERY = '(max-width: 1650px)';

export function isTablet() {
    return window.matchMedia(TABLET_QUERY).matches;
}

export function isDesktopCompact() {
    return window.matchMedia(DESKTOP_COMPACT_QUERY).matches;
}
