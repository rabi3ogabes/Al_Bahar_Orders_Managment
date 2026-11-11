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
