// Partner statistics page: password unlock, one business day of orders (table / grid), order drawer with items,
// fees, SADAD and the recorded route, and a live driver map. Plain DOM code: no framework on this public page.
const body = document.body;
const TOKEN = body.dataset.token;
const BASE = `/partner/${TOKEN}`;
const csrf = document.querySelector('meta[name=csrf-token]')?.content ?? '';

const T = {
  en: {
    lock_title: 'Partner statistics', lock_hint: 'Enter the password you were given.', password: 'Password', open: 'Open statistics',
    wrong: 'That password is not right. Try again.', too_many: 'Too many attempts. Please wait a few minutes.', title: 'Partner statistics', live: 'Live', today: 'Today', table: 'Table', grid: 'Grid', signout: 'Lock',
    drivers_live: 'Drivers, live', orders: 'Orders', items: 'Items', paid: 'Paid', delivery: 'Delivery fees', sadad_fee: 'SADAD fees', net: 'Net', subtotal: 'Items total', discount: 'Discount', service: 'Service fee', tax: 'Tax', grand: 'Order total',
    number: 'Order', time: 'Time', customer: 'Customer', status: 'Status', sadad_txn: 'SADAD transaction', sadad_status: 'SADAD status', method: 'Payment', none: '—', empty: 'No orders on this day.', empty_hint: 'Pick another date to see its orders.',
    items_n: (n) => `${n} item${n === 1 ? '' : 's'}`, qty: 'Qty', unit: 'Unit price', line: 'Total', cancelled: 'cancelled', close: 'Close', order_items: 'Items', fees: 'Money', route: 'Route history', no_route: 'No route recorded for this order.', transactions: 'SADAD transactions',
    driver: 'Driver', assigned: 'Assigned', picked: 'Picked up', delivered: 'Delivered', no_driver: 'No driver yet', estimate: 'SADAD fee rate is not set yet, so the fee shows 0. Set it in Settings → Integrations.', updated: 'Updated', no_drivers: 'No drivers on duty.', idle: 'Free', job: (n) => `Order ${n}`,
    net_note: 'Net = paid − SADAD fee − delivery fee', load_error: 'Could not load. Retrying…', copied: 'Copied',
  },
  ar: {
    lock_title: 'إحصائيات الشركاء', lock_hint: 'أدخل كلمة المرور التي تم تزويدك بها.', password: 'كلمة المرور', open: 'فتح الإحصائيات',
    wrong: 'كلمة المرور غير صحيحة. حاول مرة أخرى.', too_many: 'محاولات كثيرة. يرجى الانتظار بضع دقائق.', title: 'إحصائيات الشركاء', live: 'مباشر', today: 'اليوم', table: 'جدول', grid: 'بطاقات', signout: 'قفل',
    drivers_live: 'السائقون مباشرة', orders: 'الطلبات', items: 'القطع', paid: 'المدفوع', delivery: 'رسوم التوصيل', sadad_fee: 'رسوم سداد', net: 'الصافي', subtotal: 'مجموع المنتجات', discount: 'الخصم', service: 'رسوم الخدمة', tax: 'الضريبة', grand: 'إجمالي الطلب',
    number: 'الطلب', time: 'الوقت', customer: 'العميل', status: 'الحالة', sadad_txn: 'معاملة سداد', sadad_status: 'حالة سداد', method: 'الدفع', none: '—', empty: 'لا توجد طلبات في هذا اليوم.', empty_hint: 'اختر تاريخاً آخر لعرض طلباته.',
    items_n: (n) => `${n} قطعة`, qty: 'الكمية', unit: 'سعر الوحدة', line: 'الإجمالي', cancelled: 'ملغاة', close: 'إغلاق', order_items: 'المنتجات', fees: 'المبالغ', route: 'سجل المسار', no_route: 'لا يوجد مسار مسجل لهذا الطلب.', transactions: 'معاملات سداد',
    driver: 'السائق', assigned: 'تم التعيين', picked: 'تم الاستلام', delivered: 'تم التسليم', no_driver: 'لا يوجد سائق بعد', estimate: 'نسبة رسوم سداد غير محددة بعد، لذلك تظهر الرسوم صفر. حددها من الإعدادات ← التكاملات.', updated: 'آخر تحديث', no_drivers: 'لا يوجد سائقون في الدوام.', idle: 'متاح', job: (n) => `الطلب ${n}`,
    net_note: 'الصافي = المدفوع − رسوم سداد − رسوم التوصيل', load_error: 'تعذر التحميل. نعيد المحاولة…', copied: 'تم النسخ',
  },
};
let lang = (() => { try { return localStorage.getItem('partner_lang') === 'ar' ? 'ar' : 'en'; } catch { return 'en'; } })();
const t = (k, ...a) => { const v = T[lang][k] ?? T.en[k] ?? k; return typeof v === 'function' ? v(...a) : v; };
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const money = (v) => (v === null || v === undefined || v === '' ? t('none') : `QAR ${Number(v).toLocaleString(lang === 'ar' ? 'ar-QA' : 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`);
const fmtTime = (iso) => (iso ? new Date(iso).toLocaleTimeString(lang === 'ar' ? 'ar-QA' : 'en-GB', { hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Qatar' }) : t('none'));
const fmtDateTime = (iso) => (iso ? new Date(iso).toLocaleString(lang === 'ar' ? 'ar-QA' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Qatar' }) : t('none'));
const label = (s) => String(s ?? '').replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());

const api = async (path, opts = {}) => {
  const r = await fetch(`${BASE}${path}`, { credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, ...opts });
  if (r.status === 401 || r.status === 419) { location.reload(); throw new Error('locked'); }
  return r;
};

const applyLang = () => {
  document.documentElement.lang = lang; document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
  document.querySelectorAll('[data-i18n]').forEach((el) => { el.textContent = t(el.dataset.i18n); });
  document.querySelectorAll('#lang').forEach((el) => { el.textContent = lang === 'ar' ? 'English' : 'العربية'; });
};
const toggleLang = (after) => { lang = lang === 'ar' ? 'en' : 'ar'; try { localStorage.setItem('partner_lang', lang); } catch {} applyLang(); after?.(); };

/* ───────────── Lock screen ───────────── */
const unlock = document.getElementById('unlock');
if (unlock) {
  applyLang();
  document.getElementById('lang').addEventListener('click', () => toggleLang());
  const err = document.getElementById('pw-err'); const go = document.getElementById('go');
  unlock.addEventListener('submit', async (e) => {
    e.preventDefault(); err.classList.add('hidden'); go.disabled = true;
    try {
      const r = await api('/unlock', { method: 'POST', body: JSON.stringify({ password: unlock.password.value }) });
      if (r.ok) { location.reload(); return; }
      err.textContent = r.status === 429 ? t('too_many') : t('wrong'); err.classList.remove('hidden'); unlock.password.select();
    } catch { /* reloaded */ } finally { go.disabled = false; }
  });
}

/* ───────────── Dashboard ───────────── */
const app = document.getElementById('app');
if (app) {
  const state = { date: body.dataset.today, view: (() => { try { return localStorage.getItem('partner_view') || 'table'; } catch { return 'table'; } })(), day: null, open: null, lastFocus: null };
  const $ = (id) => document.getElementById(id);
  const dateInput = $('date');
  const shift = (d, n) => { const x = new Date(`${d}T12:00:00Z`); x.setUTCDate(x.getUTCDate() + n); return x.toISOString().slice(0, 10); };

  const kpi = (labelKey, value, tone = '') => `<div class="card p-4"><p class="text-xs font-medium uppercase tracking-wide text-ink-500">${t(labelKey)}</p><p class="mt-1 font-display text-2xl font-semibold ${tone}">${value}</p></div>`;
  const renderKpis = () => {
    const x = state.day?.totals; if (!x) { $('kpis').innerHTML = Array.from({ length: 6 }, () => '<div class="card h-[76px] animate-pulse"></div>').join(''); return; }
    $('kpis').innerHTML = kpi('orders', x.orders) + kpi('items', x.items) + kpi('paid', money(x.paid)) + kpi('delivery', money(x.delivery_fee)) + kpi('sadad_fee', money(x.sadad_fee)) + kpi('net', money(x.net), 'text-emerald-700 dark:text-emerald-400');
  };

  const pill = (s) => {
    const good = ['paid', 'completed', 'delivered', 'captured'].includes(s); const bad = ['failed', 'cancelled', 'expired', 'refunded'].includes(s);
    const cls = good ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : bad ? 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300' : 'bg-sand-100 text-ink-600 dark:bg-ink-700 dark:text-ink-200';
    return s ? `<span class="inline-flex whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium ${cls}">${esc(label(s))}</span>` : t('none');
  };

  const renderOrders = () => {
    const box = $('orders'); const d = state.day;
    if (!d) { box.innerHTML = '<div class="card h-64 animate-pulse"></div>'; return; }
    if (!d.orders.length) { box.innerHTML = `<div class="card grid place-items-center px-6 py-16 text-center"><p class="font-display text-xl font-semibold">${t('empty')}</p><p class="mt-1 text-sm text-ink-500">${t('empty_hint')}</p></div>`; return; }
    const note = d.sadad_fee_configured ? '' : `<p class="mb-3 rounded-xl bg-amber-50 px-4 py-2.5 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">${t('estimate')}</p>`;
    if (state.view === 'grid') {
      box.innerHTML = note + `<div class="grid gap-3 sm:grid-cols-2 2xl:grid-cols-3">${d.orders.map((o) => `
        <button type="button" data-order="${o.id}" class="card block w-full p-4 text-start transition hover:-translate-y-0.5 hover:shadow-lg focus-visible:ring-2 focus-visible:ring-brass-500">
          <div class="flex items-start justify-between gap-2"><div><p class="font-display text-lg font-semibold">${esc(o.number)}</p><p class="text-xs text-ink-500">${fmtTime(o.placed_at)} · ${esc(o.customer)}</p></div>${pill(o.status)}</div>
          <p class="mt-3 text-sm text-ink-600 dark:text-ink-300">${t('items_n', o.item_count)} · ${esc(label(o.payment_method))}</p>
          <dl class="mt-3 grid grid-cols-3 gap-2 text-xs"><div><dt class="text-ink-500">${t('paid')}</dt><dd class="font-semibold">${money(o.paid)}</dd></div><div><dt class="text-ink-500">${t('delivery')}</dt><dd class="font-semibold">${money(o.delivery_fee)}</dd></div><div><dt class="text-ink-500">${t('net')}</dt><dd class="font-semibold text-emerald-700 dark:text-emerald-400">${money(o.net)}</dd></div></dl>
          ${o.sadad.reference ? `<p class="mt-3 truncate text-xs text-ink-500" dir="ltr">SADAD ${esc(o.sadad.reference)} · ${esc(label(o.sadad.status))}</p>` : ''}
        </button>`).join('')}</div>`;
    } else {
      box.innerHTML = note + `<div class="card overflow-x-auto"><table class="w-full min-w-[1100px] text-sm [&_td]:whitespace-nowrap"><thead class="bg-sand-50 text-xs uppercase tracking-wide text-ink-500 dark:bg-ink-800"><tr>
        ${['number', 'time', 'customer', 'items', 'subtotal', 'delivery', 'paid', 'sadad_txn', 'sadad_status', 'sadad_fee', 'net', 'status'].map((k, i) => `<th scope="col" class="px-3 py-3 text-start font-medium ${i === 0 ? 'sticky start-0 bg-sand-50 dark:bg-ink-800' : ''}">${t(k)}</th>`).join('')}</tr></thead>
        <tbody class="divide-y divide-sand-100 dark:divide-ink-700">${d.orders.map((o) => `<tr data-order="${o.id}" tabindex="0" role="button" class="cursor-pointer hover:bg-sand-50 focus-visible:bg-sand-50 dark:hover:bg-ink-800">
          <td class="sticky start-0 bg-white px-3 py-3 font-semibold dark:bg-ink-900">${esc(o.number)}</td><td class="px-3 py-3">${fmtTime(o.placed_at)}</td><td class="px-3 py-3">${esc(o.customer)}</td>
          <td class="px-3 py-3">${o.item_count}</td><td class="px-3 py-3">${money(o.subtotal)}</td><td class="px-3 py-3">${money(o.delivery_fee)}</td><td class="px-3 py-3 font-medium">${money(o.paid)}</td>
          <td class="px-3 py-3" dir="ltr">${o.sadad.reference ? esc(o.sadad.reference) : t('none')}</td><td class="px-3 py-3">${pill(o.sadad.status)}</td><td class="px-3 py-3">${money(o.sadad.fee)}</td>
          <td class="px-3 py-3 font-semibold text-emerald-700 dark:text-emerald-400">${money(o.net)}</td><td class="px-3 py-3">${pill(o.status)}</td></tr>`).join('')}</tbody></table></div>`;
    }
  };
  const setView = (v) => { state.view = v; try { localStorage.setItem('partner_view', v); } catch {}
    $('v-table').setAttribute('aria-pressed', v === 'table'); $('v-grid').setAttribute('aria-pressed', v === 'grid');
    [['v-table', 'table'], ['v-grid', 'grid']].forEach(([id, k]) => { $(id).className = `h-11 px-3.5 text-sm ${v === k ? 'bg-ink-900 text-ivory dark:bg-brass-500 dark:text-ink-950' : 'bg-white dark:bg-ink-800'}`; });
    renderOrders(); };

  let loading = false;
  const loadDay = async (silent = false) => {
    if (loading) return; loading = true; if (!silent) { state.day = null; renderKpis(); renderOrders(); }
    try { const r = await api(`/data?date=${state.date}`); state.day = (await r.json()).data; $('updated').textContent = `${t('updated')} ${new Date().toLocaleTimeString(lang === 'ar' ? 'ar-QA' : 'en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' })}`; renderKpis(); renderOrders(); }
    catch (e) { if (e.message !== 'locked') toast(t('load_error')); } finally { loading = false; }
  };
  const setDate = (d) => { state.date = d; dateInput.value = d; loadDay(); };

  /* Drawer */
  const drawer = $('drawer'); const scrim = $('scrim');
  const MD = window.matchMedia('(min-width: 768px)');
  const closedCls = () => (MD.matches ? ['md:ltr:translate-x-full', 'md:rtl:-translate-x-full'] : ['translate-y-full']);
  let mapRoute = null; let leaflet = null;
  const loadLeaflet = async () => (leaflet ??= await Promise.all([import('leaflet'), import('leaflet/dist/leaflet.css')]).then(([m]) => m.default));
  const tiles = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
  const pin = (L, color, glyph) => L.divIcon({ className: '', iconSize: [30, 30], iconAnchor: [15, 15], html: `<div style="width:30px;height:30px;border-radius:50%;background:${color};color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;box-shadow:0 2px 8px rgba(0,0,0,.35);border:2px solid #fff">${glyph}</div>` });

  const row = (k, v, strong = false) => `<div class="flex justify-between gap-4 py-1.5 ${strong ? 'border-t border-sand-200 pt-2.5 font-semibold dark:border-ink-700' : ''}"><dt class="text-ink-500">${k}</dt><dd>${v}</dd></div>`;
  const drawerHtml = (o) => {
    const del = o.deliveries ?? [];
    return `<header class="flex items-start justify-between gap-3 border-b border-sand-200 px-5 py-4 dark:border-ink-700"><div><p class="text-xs uppercase tracking-wide text-ink-500">${t('number')}</p><h2 id="drawer-title" class="font-display text-2xl font-semibold">${esc(o.number)}</h2><p class="mt-1 text-sm text-ink-500">${fmtDateTime(o.placed_at)} · ${esc(o.customer)} ${o.phone ? `· <span dir="ltr">${esc(o.phone)}</span>` : ''}</p></div>
      <button id="d-close" class="grid size-11 shrink-0 place-items-center rounded-xl hover:bg-sand-100 dark:hover:bg-ink-800" aria-label="${t('close')}">✕</button></header>
      <div class="flex-1 space-y-6 overflow-y-auto overscroll-contain px-5 py-5">
        <div class="flex flex-wrap gap-2">${pill(o.status)}${pill(o.payment_status)}<span class="text-sm text-ink-500">${esc(label(o.payment_method))}</span></div>
        <section><h3 class="mb-2 font-display text-lg font-semibold">${t('order_items')} <span class="text-sm font-normal text-ink-500">(${t('items_n', o.item_count)})</span></h3>
          <div class="overflow-x-auto rounded-xl border border-sand-200 dark:border-ink-700"><table class="w-full text-sm"><thead class="bg-sand-50 text-xs text-ink-500 dark:bg-ink-800"><tr><th class="px-3 py-2 text-start font-medium"></th><th class="px-3 py-2 text-start font-medium">${t('qty')}</th><th class="px-3 py-2 text-start font-medium">${t('unit')}</th><th class="px-3 py-2 text-start font-medium">${t('line')}</th></tr></thead>
          <tbody class="divide-y divide-sand-100 dark:divide-ink-700">${o.items.map((i) => `<tr><td class="px-3 py-2"><p class="font-medium">${esc(lang === 'ar' ? i.name_ar || i.name : i.name)}</p><p class="text-xs text-ink-500" dir="ltr">${esc(i.sku)}${i.variant ? ' · ' + esc(i.variant) : ''}</p></td><td class="px-3 py-2">${i.quantity}${i.cancelled ? ` <span class="text-xs text-red-600">(${i.cancelled} ${t('cancelled')})</span>` : ''}</td><td class="px-3 py-2">${money(i.unit_price)}</td><td class="px-3 py-2 font-medium">${money(i.line_total)}</td></tr>`).join('')}</tbody></table></div></section>
        <section><h3 class="mb-2 font-display text-lg font-semibold">${t('fees')}</h3><dl class="rounded-xl border border-sand-200 px-4 py-2 text-sm dark:border-ink-700">
          ${row(t('subtotal'), money(o.subtotal))}${Number(o.discount) ? row(t('discount'), '− ' + money(o.discount)) : ''}${row(t('delivery'), money(o.delivery_fee))}${Number(o.service_fee) ? row(t('service'), money(o.service_fee)) : ''}${Number(o.tax) ? row(t('tax'), money(o.tax)) : ''}
          ${row(t('grand'), money(o.grand_total), true)}${row(t('paid'), money(o.paid))}${row(t('sadad_fee'), '− ' + money(o.sadad_fee))}${row(t('delivery'), '− ' + money(o.delivery_fee))}${row(t('net'), `<span class="text-emerald-700 dark:text-emerald-400">${money(o.net)}</span>`, true)}</dl><p class="mt-1.5 text-xs text-ink-500">${t('net_note')}</p></section>
        ${o.sadad.reference || o.transactions.length ? `<section><h3 class="mb-2 font-display text-lg font-semibold">${t('transactions')}</h3><div class="mb-2 text-sm"><span class="text-ink-500">${t('sadad_txn')}:</span> <span dir="ltr" class="font-medium">${esc(o.sadad.reference ?? t('none'))}</span> ${pill(o.sadad.status)}</div>
          <ul class="divide-y divide-sand-100 rounded-xl border border-sand-200 text-sm dark:divide-ink-700 dark:border-ink-700">${o.transactions.map((x) => `<li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2"><span>${esc(label(x.type))} · ${pill(x.status)}</span><span class="text-xs text-ink-500">${money(x.amount)} · ${fmtDateTime(x.at)}</span></li>`).join('')}</ul></section>` : ''}
        <section><h3 class="mb-2 font-display text-lg font-semibold">${t('route')}</h3>
          ${del.length ? del.map((d) => `<div class="mb-3 rounded-xl border border-sand-200 p-3 text-sm dark:border-ink-700"><div class="flex flex-wrap items-center justify-between gap-2"><span class="font-medium">${t('driver')}: ${esc(d.driver ?? t('no_driver'))}</span>${pill(d.status)}</div>
            <p class="mt-1 text-xs text-ink-500">${t('assigned')} ${fmtTime(d.assigned_at)} · ${t('picked')} ${fmtTime(d.picked_up_at)} · ${t('delivered')} ${fmtTime(d.delivered_at)}</p></div>`).join('') : `<p class="text-sm text-ink-500">${t('no_driver')}</p>`}
          <div id="route-map" class="h-64 w-full overflow-hidden rounded-xl bg-sand-100 dark:bg-ink-800"></div><p id="route-empty" class="mt-2 hidden text-sm text-ink-500">${t('no_route')}</p></section>
      </div>`;
  };
  const drawRoute = async (o) => {
    const L = await loadLeaflet(); const el = $('route-map'); if (!el) return;
    mapRoute?.remove(); mapRoute = L.map(el).setView([25.2854, 51.531], 12); L.tileLayer(tiles, { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(mapRoute);
    const bounds = []; let any = false;
    (o.deliveries ?? []).forEach((d) => { const pts = d.route.map((p) => [p.lat, p.lng]); if (pts.length > 1) { any = true; L.polyline(pts, { color: '#8A1538', weight: 4, opacity: 0.85 }).addTo(mapRoute); L.marker(pts[0], { icon: pin(L, '#B08D57', '▶') }).addTo(mapRoute); L.marker(pts.at(-1), { icon: pin(L, '#2E7D5B', '🛵') }).addTo(mapRoute); bounds.push(...pts); } });
    if (o.destination) { L.marker([o.destination.lat, o.destination.lng], { icon: pin(L, '#8A1538', '⌂') }).addTo(mapRoute); bounds.push([o.destination.lat, o.destination.lng]); }
    if (bounds.length) mapRoute.fitBounds(bounds, { padding: [30, 30], maxZoom: 16 });
    if (!any) $('route-empty')?.classList.remove('hidden');
    setTimeout(() => mapRoute?.invalidateSize(), 320);
  };
  const openOrder = async (id, fromPop = false) => {
    state.lastFocus = document.activeElement; state.open = id;
    drawer.innerHTML = '<div class="p-8"><div class="h-8 w-40 animate-pulse rounded bg-sand-100 dark:bg-ink-800"></div></div>';
    drawer.classList.remove('hidden'); drawer.classList.add('flex'); scrim.classList.remove('hidden'); document.body.style.overflow = 'hidden';
    requestAnimationFrame(() => closedCls().forEach((c) => drawer.classList.remove(c)));
    if (!fromPop) history.pushState({ order: id }, '');
    try {
      const r = await api(`/orders/${id}`); if (!r.ok) throw new Error('x'); const o = (await r.json()).data;
      if (state.open !== id) return;
      drawer.innerHTML = drawerHtml(o); $('d-close').addEventListener('click', () => closeOrder()); $('d-close').focus(); drawRoute(o);
    } catch (e) { if (e.message !== 'locked') { drawer.innerHTML = `<div class="p-8 text-sm">${t('load_error')}</div>`; } }
  };
  const closeOrder = (fromPop = false) => {
    if (!state.open) return; state.open = null; closedCls().forEach((c) => drawer.classList.add(c)); scrim.classList.add('hidden'); document.body.style.overflow = '';
    setTimeout(() => { if (!state.open) { drawer.classList.add('hidden'); drawer.classList.remove('flex'); mapRoute?.remove(); mapRoute = null; } }, 300);
    if (!fromPop && history.state?.order) history.back();
    state.lastFocus?.focus?.();
  };
  window.addEventListener('popstate', () => { if (state.open) closeOrder(true); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && state.open) closeOrder(); });
  scrim.addEventListener('click', () => closeOrder());
  $('orders').addEventListener('click', (e) => { const r = e.target.closest('[data-order]'); if (r) openOrder(r.dataset.order); });
  $('orders').addEventListener('keydown', (e) => { if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('[data-order]')) { e.preventDefault(); openOrder(e.target.dataset.order); } });

  /* Live fleet */
  let fmap = null; const markers = {}; let fitted = false;
  const loadFleet = async () => {
    try {
      const r = await api('/fleet'); const { drivers } = (await r.json()).data; const L = await loadLeaflet();
      if (!fmap) { fmap = L.map($('fleet-map')).setView([25.2854, 51.531], 11); L.tileLayer(tiles, { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(fmap); }
      const seen = new Set(); const pts = [];
      drivers.forEach((d) => { if (d.lat == null) return; seen.add(d.id); pts.push([d.lat, d.lng]);
        const tip = `<strong>${esc(d.name)}</strong><br>${d.job ? esc(t('job', d.job.order_number)) : t('idle')}`; const icon = pin(L, d.job ? '#B08D57' : '#2E7D5B', '🛵');
        if (markers[d.id]) markers[d.id].setLatLng([d.lat, d.lng]).setIcon(icon).setTooltipContent(tip); else markers[d.id] = L.marker([d.lat, d.lng], { icon }).addTo(fmap).bindTooltip(tip); });
      Object.keys(markers).forEach((id) => { if (!seen.has(id)) { fmap.removeLayer(markers[id]); delete markers[id]; } });
      if (!fitted && pts.length) { fmap.fitBounds(pts, { padding: [40, 40], maxZoom: 15 }); fitted = true; }
      $('fleet-count').textContent = drivers.length ? `${drivers.length}` : '';
      $('fleet-list').innerHTML = drivers.length ? drivers.map((d) => `<li><button type="button" data-driver="${d.id}" class="flex min-h-11 w-full items-center gap-3 px-4 py-2.5 text-start hover:bg-sand-50 dark:hover:bg-ink-800"><span class="size-2.5 shrink-0 rounded-full ${d.job ? 'bg-brass-500' : 'bg-emerald-500'}"></span><span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium">${esc(d.name)}</span><span class="block truncate text-xs text-ink-500">${d.job ? esc(t('job', d.job.order_number)) + ' · ' + esc(label(d.job.status)) : t('idle')}</span></span></button></li>`).join('') : `<li class="px-4 py-6 text-center text-sm text-ink-500">${t('no_drivers')}</li>`;
    } catch { /* retried on the next tick */ }
  };
  $('fleet-list').addEventListener('click', (e) => { const b = e.target.closest('[data-driver]'); const m = b && markers[b.dataset.driver]; if (m) { fmap.setView(m.getLatLng(), 16); m.openTooltip(); } });

  /* Wire-up */
  const toast = (m) => { const el = $('toast'); el.innerHTML = `<div class="pointer-events-auto rounded-xl bg-ink-900 px-4 py-2.5 text-sm text-ivory shadow-lg">${esc(m)}</div>`; setTimeout(() => { el.innerHTML = ''; }, 3000); };
  $('prev').addEventListener('click', () => setDate(shift(state.date, -1)));
  $('next').addEventListener('click', () => setDate(shift(state.date, 1)));
  $('today').addEventListener('click', () => setDate(body.dataset.today));
  dateInput.addEventListener('change', () => dateInput.value && setDate(dateInput.value));
  $('v-table').addEventListener('click', () => setView('table')); $('v-grid').addEventListener('click', () => setView('grid'));
  document.querySelectorAll('#lang').forEach((b) => b.addEventListener('click', () => toggleLang(() => { renderKpis(); renderOrders(); setView(state.view); loadFleet(); })));
  $('signout').addEventListener('click', async () => { await api('/lock', { method: 'POST' }).catch(() => {}); location.reload(); });

  applyLang(); dateInput.value = state.date; setView(state.view); renderKpis(); loadDay(); loadFleet();
  setInterval(() => { if (!document.hidden) { if (state.date === body.dataset.today) loadDay(true); } }, 30000);
  setInterval(() => { if (!document.hidden) loadFleet(); }, 10000);
}
