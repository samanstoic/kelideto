export function createRouter({ root, routes, renderNotFound, state }) {
    if (!(root instanceof Element)) {
        throw new TypeError('Router requires a valid root element.');
    }

    let currentRouteCleanup;

    function renderCurrentRoute() {
        currentRouteCleanup?.();
        currentRouteCleanup = undefined;
        const path = window.location.pathname;
        const render = routes[path];
        root.replaceChildren();
        state?.setState({ path });

        if (render) {
            const cleanup = render(root);
            if (typeof cleanup === 'function') {
                currentRouteCleanup = cleanup;
            }
        } else {
            renderNotFound(root, path);
        }
    }

    function navigate(path, { replace = false } = {}) {
        const target = new URL(path, window.location.origin);
        if (target.origin !== window.location.origin) {
            throw new TypeError('Router only supports same-origin navigation.');
        }

        if (replace) {
            window.history.replaceState({}, '', target);
        } else {
            window.history.pushState({}, '', target);
        }
        renderCurrentRoute();

        const hashTarget = target.hash ? root.querySelector(target.hash) : null;
        if (hashTarget && typeof hashTarget.scrollIntoView === 'function') {
            hashTarget.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else if (typeof window.scrollTo === 'function' && !/jsdom/i.test(navigator.userAgent)) {
            window.scrollTo({ top: 0 });
        }
    }

    function handleClick(event) {
        if (
            event.defaultPrevented ||
            event.button !== 0 ||
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey
        ) {
            return;
        }

        const anchor = event.target.closest('a[data-router-link]');
        if (!anchor || anchor.target || anchor.hasAttribute('download')) {
            return;
        }

        const target = new URL(anchor.href, window.location.origin);
        if (target.origin !== window.location.origin) {
            return;
        }

        event.preventDefault();
        navigate(`${target.pathname}${target.search}${target.hash}`);
    }

    const handlePopState = () => renderCurrentRoute();
    root.addEventListener('click', handleClick);
    window.addEventListener('popstate', handlePopState);
    renderCurrentRoute();

    return {
        navigate,
        destroy() {
            currentRouteCleanup?.();
            currentRouteCleanup = undefined;
            root.removeEventListener('click', handleClick);
            window.removeEventListener('popstate', handlePopState);
        },
    };
}
