// Loader for the map kit. Every page loads the kit lazily (Leaflet and the map code are a separate chunk), so a page without a
// map never pays for it. If the chunk cannot be fetched, the host shows a plain message with a retry button; the classes used here
// belong to the page stylesheet, because the map stylesheet is part of the chunk that failed. Retry reloads the page: the browser
// keeps a failed module import cached as failed, so importing it again in the same page would fail again.

import { TEXT } from './text.js';

export const loadMapKit = () => import('./index.js');

const lang = () => (document.documentElement.lang || 'en').toLowerCase().startsWith('ar') ? 'ar' : 'en';

function loadingBox(host) {
    const box = document.createElement('div');
    box.className = 'h-full min-h-[340px] w-full animate-pulse bg-sand-100 dark:bg-ink-800';
    box.setAttribute('role', 'status');
    box.setAttribute('aria-label', TEXT[lang()].loading);
    host.replaceChildren(box);
}

function errorBox(host, retry) {
    const t = TEXT[lang()];
    const box = document.createElement('div');
    box.className = 'flex h-full min-h-[340px] w-full flex-col items-center justify-center gap-3 bg-sand-50 p-6 text-center text-sm text-ink-600 dark:bg-ink-900 dark:text-sand-200';
    box.setAttribute('role', 'alert');
    const msg = document.createElement('p');
    msg.textContent = t.load_error;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn-secondary min-h-11';
    btn.textContent = t.retry;
    btn.addEventListener('click', retry);
    box.append(msg, btn);
    host.replaceChildren(box);
}

/**
 * Loads the kit, then runs `build(kit)` (which creates the map in `host`). Returns whatever build returns, or null when the
 * host was removed meanwhile or the kit could not be loaded (a retry box is shown then).
 */
export async function bootMap(host, build) {
    loadingBox(host);
    try {
        const kit = await loadMapKit();
        if (!host.isConnected) return null;
        return await build(kit);
    } catch (e) {
        console.error('Map failed to start', e);
        // A module that failed to download is remembered as failed by the browser until the page is loaded again, so Retry reloads the page.
        if (host.isConnected) errorBox(host, () => location.reload());
        return null;
    }
}

/** Builds when the host first scrolls into view (cards below the fold do not load the map until needed). */
export function bootMapWhenVisible(host, build) {
    if (typeof IntersectionObserver !== 'function') return bootMap(host, build);
    return new Promise((resolve) => {
        const io = new IntersectionObserver((entries) => {
            if (!entries.some((e) => e.isIntersecting)) return;
            io.disconnect();
            resolve(bootMap(host, build));
        }, { rootMargin: '200px' });
        io.observe(host);
    });
}
