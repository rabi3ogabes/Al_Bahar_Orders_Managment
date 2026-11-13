import './bootstrap';
import './overlays';
import Chart from 'chart.js/auto';
import { bootMap, bootMapWhenVisible } from './maps/boot.js';

window.Chart = Chart;

// Theme: respect saved preference, else system.
const applyTheme = () => {
    const saved = localStorage.getItem('theme');
    const dark = saved ? saved === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
    document.documentElement.classList.toggle('dark', dark);
};
applyTheme();
window.toggleTheme = () => {
    const dark = !document.documentElement.classList.contains('dark');
    localStorage.setItem('theme', dark ? 'dark' : 'light');
    applyTheme();
};

// Lazy-load the QR scanner only on pages that need it (pickup verification).
window.loadQrScanner = () => import('html5-qrcode').then((m) => m.Html5Qrcode);

// Maps: Leaflet and the map kit are one lazy chunk, fetched only by pages that show a map (live fleet, order route card).
// Each call hands a host element to the kit; calling it again on the same element replaces the old map, so wire:navigate and
// Livewire re-renders can never initialise a container twice.
window.mountFleet = (host, boot, wire) => bootMap(host, (kit) => kit.mountAdminFleet(host, boot, wire));
window.mountRouteCard = (host, data) => bootMapWhenVisible(host, (kit) => {
    const lang = kit.pageLang();
    return kit.createRouteCard({ ...data, host, text: kit.textFor(lang), lang });
});

// Lazy-load the live order toasts only on pages that show live events (fleet map).
window.loadLiveToasts = () => import('./live-toasts.js');

// Lazy-load web push (Firebase SDK in its own chunk) only on the Push notifications page.
window.loadPushAdmin = () => import('./push-admin.js');

// Themed confirmation for every wire:confirm button: the message is the question, the buttons are Cancel and the action's own verb
// (instead of the browser's OK/Cancel box). Livewire's directive then runs as usual once window.confirm is told "yes".
// The confirm is an overlay (resources/js/overlays.js): Esc/Back/scrim cancel it, and it works above a side window.
document.addEventListener('click', (e) => {
    const el = e.target.closest?.('[wire\\:confirm]');
    if (!el || el.dataset.confirmed === '1') return;
    e.preventDefault();
    e.stopImmediatePropagation();
    const ar = document.documentElement.lang.startsWith('ar');
    window.Overlays.confirm({
        question: el.getAttribute('wire:confirm').replaceAll('\\n', '\n'),
        ok: (el.getAttribute('aria-label') || el.innerText || '').trim().split('\n')[0] || (ar ? 'تأكيد' : 'Confirm'),
    }).then((yes) => {
        if (!yes) return;
        el.dataset.confirmed = '1';
        const native = window.confirm;
        window.confirm = () => true;
        el.click();
        window.confirm = native;
        setTimeout(() => delete el.dataset.confirmed, 0);
    });
}, true);
