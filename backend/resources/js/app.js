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

// Themed confirmation for every wire:confirm button: the message is the question, the buttons are Cancel and the action's own verb
// (instead of the browser's OK/Cancel box). Livewire's directive then runs as usual once window.confirm is told "yes".
document.addEventListener('click', (e) => {
    const el = e.target.closest?.('[wire\\:confirm]');
    if (!el || el.dataset.confirmed === '1') return;
    e.preventDefault();
    e.stopImmediatePropagation();
    const ar = document.documentElement.lang.startsWith('ar');
    const verb = (el.getAttribute('aria-label') || el.innerText || '').trim().split('\n')[0] || (ar ? 'تأكيد' : 'Confirm');
    const opener = document.activeElement;
    const wrap = document.createElement('div');
    wrap.className = 'fixed inset-0 z-[90] flex items-end justify-center p-4 sm:items-center';
    wrap.setAttribute('role', 'alertdialog');
    wrap.setAttribute('aria-modal', 'true');
    wrap.innerHTML = '<div class="absolute inset-0 bg-ink-950/60 backdrop-blur-sm" data-x="cancel"></div>'
        + '<div class="card relative w-full max-w-md p-6 shadow-[var(--shadow-lift)]"><p class="font-display text-xl font-semibold text-ink-900 dark:text-ivory" data-q></p>'
        + '<div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><button type="button" class="btn-secondary min-h-11" data-x="cancel"></button><button type="button" class="btn-primary min-h-11" data-x="ok"></button></div></div>';
    wrap.querySelector('[data-q]').textContent = el.getAttribute('wire:confirm').replaceAll('\\n', '\n');
    const [cancelBtn, okBtn] = wrap.querySelectorAll('button');
    cancelBtn.textContent = ar ? 'إلغاء' : 'Cancel';
    okBtn.textContent = verb;
    wrap.setAttribute('aria-label', wrap.querySelector('[data-q]').textContent);
    const close = () => { document.removeEventListener('keydown', onKey, true); wrap.remove(); opener?.focus?.(); };
    const onKey = (k) => {
        if (k.key === 'Escape') { k.stopPropagation(); close(); }
        if (k.key === 'Tab') { k.preventDefault(); (document.activeElement === okBtn ? cancelBtn : okBtn).focus(); }
    };
    wrap.addEventListener('click', (c) => {
        const t = c.target.closest('[data-x]')?.dataset.x;
        if (t === 'cancel') close();
        if (t === 'ok') {
            close();
            el.dataset.confirmed = '1';
            const native = window.confirm;
            window.confirm = () => true;
            el.click();
            window.confirm = native;
            setTimeout(() => delete el.dataset.confirmed, 0);
        }
    });
    document.addEventListener('keydown', onKey, true);
    document.body.appendChild(wrap);
    okBtn.focus();
}, true);
