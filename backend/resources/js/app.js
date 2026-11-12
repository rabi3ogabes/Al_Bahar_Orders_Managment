import './bootstrap';
import Chart from 'chart.js/auto';

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

// Lazy-load Leaflet (fleet map) only on pages that need it.
window.loadLeaflet = () => Promise.all([import('leaflet'), import('leaflet/dist/leaflet.css')]).then(([m]) => m.default);

// Lazy-load the live order toasts only on pages that show live events (fleet map).
window.loadLiveToasts = () => import('./live-toasts.js');
