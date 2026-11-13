// The live fleet map, used by the admin console and by the partner page. It owns the map, the markers and their animation, the
// driver panel, the selected-driver card, filters, clustering above 40 drivers, the route trail, follow mode and the polling
// fallback. The host (admin Livewire page / partner page) only feeds it data and says how positions arrive.

import { L, claimHost, createBaseMap } from './base.js';
import { DriverMarker, Mover, clusterIcon, paintDriver, pinIcon } from './markers.js';
import { agoText, clockText, h, lazy, numberFormat } from './dom.js';
import { fmt } from './text.js';
import {
    applyFull, applyMove, counts, filterDrivers, firstName, gridCluster, initials, isMoving, isStale, normaliseDriver, normaliseMove, phoneHref, sortDrivers, statusOf,
} from './logic.js';
import { buildCard, buildConnection, buildLegend, buildOverlays, buildPanel, buildRow, setText, updateRow } from './fleet-ui.js';

const CLUSTER_MIN = 40;
const CLUSTER_MAX_ZOOM = 16;
const CELL_PX = 64;
const humanise = (s) => String(s ?? '').replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());

/**
 * @param {object} o
 * @param {HTMLElement} o.host
 * @param {object} o.config      MapConfig::payload()
 * @param {object} o.text        dictionary (text.js)
 * @param {'en'|'ar'} o.lang
 * @param {'admin'|'partner'} o.mode     partner: first names only, no phone numbers, read-only
 * @param {Array} o.drivers      raw driver rows
 * @param {Array} o.branches     [{ id, name, lat, lng }]
 * @param {number|null} o.serverTime  server clock (ms) of the payload
 * @param {(job) => string|null} o.orderHref   admin: link to the order
 * @param {(job) => void} o.onViewOrder        partner: open the order drawer
 * @param {() => Promise<void>} o.refresh      pulls fresh rows (Livewire refresh / fetch)
 * @param {{live: number, down: number}} o.poll  refresh interval in seconds while the socket is up / not up
 * @param {'live'|'reconnecting'|'polling'} o.connection
 * @param {boolean} o.loading    show the skeleton until the first update()
 * @param {{center: object, zoom: number}} o.view  restore this view instead of fitting
 */
export function createFleetMap(o) {
    const { host, text, lang, mode = 'admin' } = o;
    claimHost(host);
    const base = createBaseMap({ host, config: o.config, text, lang, kind: 'fleet', cooperative: mode === 'partner' ? 'full' : 'touch', label: text.map_label });
    const { map, root, shell, stage, ui, toast, reduced } = base;
    const staleMs = base.cfg.staleMs;
    const nf = numberFormat(lang);
    root.dataset.mode = mode;

    const store = new Map();
    const markers = new Map();
    const rows = new Map();
    const clusters = new Map();
    const branchMarkers = new Map();
    const filters = { query: '', status: 'all' };
    const layers = { branches: true, names: true, trail: true };
    let selectedId = null;
    let followId = null;
    let hoverId = null;
    let connection = o.connection ?? 'polling';
    let fitted = Boolean(o.view);
    let userMoved = Boolean(o.view);
    let progUntil = 0;
    let loaded = !o.loading;
    let refreshing = false;
    let lastUnknownRefresh = 0;
    let destroyed = false;
    let suspended = false;
    let pollTimer = null;
    let tickTimer = null;
    let tick = 0;
    const nowMs = () => Date.now();

    const branchLayer = L.layerGroup().addTo(map);
    const trailLayer = L.layerGroup().addTo(map);
    const driverLayer = L.layerGroup().addTo(map);
    const clusterLayer = L.layerGroup().addTo(map);

    /* ───────────── Chrome around the canvas ───────────── */

    const panel = buildPanel({
        text,
        onQuery: (q) => { filters.query = q; applyFilters(); },
        onStatus: (s) => { filters.status = s; applyFilters(); },
        onToggleSheet: () => panel.setOpen(!panel.isOpen()),
        onListKey: (e) => {
            if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
            const btns = [...panel.list.querySelectorAll('.abm-row')];
            const i = btns.indexOf(document.activeElement);
            if (i < 0) return;
            e.preventDefault();
            btns[Math.max(0, Math.min(btns.length - 1, i + (e.key === 'ArrowDown' ? 1 : -1)))].focus();
        },
    });
    panel.setOpen(false);
    shell.prepend(panel.el);

    const conn = buildConnection(text);
    const chips = h('div', { class: 'abm-chips' }, conn.el);
    const overlays = buildOverlays(text, { onRetry: () => runRefresh() });
    const legend = buildLegend(text, true);
    const card = buildCard({
        text,
        onClose: () => select(null),
        onFollow: () => setFollow(followId === null),
        onZoom: () => { const m = markers.get(selectedId); if (m) flyTo(m.pos); },
        onCopy: async () => {
            const d = store.get(selectedId);
            if (!d?.phone) return;
            try {
                await navigator.clipboard.writeText(d.phone);
                toast.show(text.card_copied, 2500);
            } catch {
                toast.show(d.phone, 6000);
            }
        },
    });
    ui.append(chips, legend, card.el, overlays.empty, overlays.error, overlays.skeleton);
    overlays.skeleton.hidden = loaded;

    /* ───────────── Toolbar ───────────── */

    base.addTool({ name: 'fit', label: text.fit_all, onClick: () => fitAll(true) });
    const followBtn = base.addTool({ name: 'follow', label: text.follow_pick, onClick: () => setFollow(followId === null), pressed: false });
    followBtn.disabled = true;
    base.addSeparator();
    const layerBtn = (key, name, label) => {
        const b = base.addTool({
            name, label, pressed: layers[key], group: 'layer',
            onClick: () => {
                layers[key] = !layers[key];
                b.setAttribute('aria-pressed', String(layers[key]));
                applyLayers();
            },
        });
        return b;
    };
    layerBtn('branches', 'store', text.layer_branches);
    layerBtn('names', 'tag', text.layer_names);
    layerBtn('trail', 'trail', text.layer_trail);
    base.addSeparator();
    const legendBtn = base.addTool({
        name: 'legend', label: text.legend, pressed: false,
        onClick: () => {
            legend.hidden = !legend.hidden;
            legendBtn.setAttribute('aria-pressed', String(!legend.hidden));
        },
    });
    base.addFullscreenTool();

    /* ───────────── Marker engine ───────────── */

    const mover = new Mover((m) => {
        if (followId === m.id) keepInView(m.pos);
    });

    const nameOf = (d) => (mode === 'partner' ? firstName(d.name) : d.name) || '—';
    const orderText = (d) => (d.job?.orderNumber ? fmt(text.job, { n: d.job.orderNumber }) : '');
    const jobStatusText = (d) => (d.job?.status ? (text[`st_${d.job.status}`] ?? humanise(d.job.status)) : '');

    function statusText(d, n) {
        const st = statusOf(d);
        if (st === 'offline') return text.st_offline;
        if (isStale(d, n, staleMs)) return text.st_stale;
        if (st === 'idle') return text.st_idle;
        return jobStatusText(d) || text.legend_assigned;
    }

    function tooltipFor(id) {
        const d = store.get(id);
        const n = nowMs();
        if (!d) return '';
        const stale = isStale(d, n, staleMs);
        return h('div', { class: 'abm-tip__b' },
            h('strong', {}, nameOf(d)),
            h('span', {}, [orderText(d), statusText(d, n)].filter(Boolean).join(' · ')),
            stale && d.seenAt ? h('span', { class: 'abm-tip__s' }, fmt(text.last_seen, { ago: agoText(n - d.seenAt, lang, text) })) : null);
    }

    function paintMarker(id) {
        const d = store.get(id);
        const m = markers.get(id);
        if (!d || !m) return;
        const n = nowMs();
        const moving = isMoving(d, n, staleMs);
        paintDriver(m.el, {
            status: statusOf(d),
            stale: d.onDuty && isStale(d, n, staleMs),
            moving,
            selected: id === selectedId,
            highlighted: id === hoverId,
            heading: m.pos.heading ?? d.heading,
            tag: firstName(nameOf(d)),
            label: fmt(text.marker_label, { name: nameOf(d), status: statusText(d, n) }),
        });
        m.marker.setZIndexOffset(id === selectedId ? 1000 : moving ? 300 : statusOf(d) === 'out' ? 200 : 0);
    }

    function ensureMarker(d) {
        let m = markers.get(d.id);
        if (!m) {
            m = new DriverMarker(d, {
                onClick: (id) => select(id, { fly: false }),
                onHover: (id, on) => highlight(on ? id : null),
                tooltip: tooltipFor,
            });
            markers.set(d.id, m);
        }
        return m;
    }

    function moveMarker(d, animate) {
        const m = ensureMarker(d);
        const target = { lat: d.lat, lng: d.lng, heading: d.heading };
        if (!animate) {
            m.moveTo(target, performance.now(), true);
            return;
        }
        if (m.moveTo(target, performance.now(), reduced)) mover.add(m);
    }

    /** Keeps the followed driver inside the middle of the view by nudging the map only when they near the edge. */
    function keepInView(pos) {
        const size = map.getSize();
        const pt = map.latLngToContainerPoint([pos.lat, pos.lng]);
        const mx = size.x * 0.3;
        const my = size.y * 0.3;
        const dx = pt.x < mx ? pt.x - mx : pt.x > size.x - mx ? pt.x - (size.x - mx) : 0;
        const dy = pt.y < my ? pt.y - my : pt.y > size.y - my ? pt.y - (size.y - my) : 0;
        if (dx || dy) {
            progUntil = performance.now() + 400;
            map.panBy([dx, dy], { animate: false });
        }
    }

    /* ───────────── Layout: which markers show, clusters ───────────── */

    const visibleDrivers = () => filterDrivers([...store.values()], filters, nowMs(), staleMs).filter((d) => d.lat !== null);

    function syncClusters(list) {
        const keep = new Set(list.map((c) => c.key));
        for (const [key, cm] of clusters) {
            if (!keep.has(key)) {
                clusterLayer.removeLayer(cm.marker);
                clusters.delete(key);
            }
        }
        for (const c of list) {
            let cm = clusters.get(c.key);
            if (!cm) {
                const marker = L.marker([c.lat, c.lng], { icon: clusterIcon(c.count, c.status), keyboard: true });
                cm = { marker, count: c.count, status: c.status, ids: c.ids };
                marker.on('click', () => zoomToIds(cm.ids));
                clusterLayer.addLayer(marker);
                clusters.set(c.key, cm);
            } else {
                cm.marker.setLatLng([c.lat, c.lng]);
                cm.ids = c.ids;
                if (cm.count !== c.count || cm.status !== c.status) {
                    cm.marker.setIcon(clusterIcon(c.count, c.status));
                    cm.count = c.count;
                    cm.status = c.status;
                }
            }
            cm.marker.getElement()?.setAttribute('aria-label', fmt(text.cluster_label, { n: c.count }));
        }
    }

    function layout() {
        if (destroyed) return;
        const vis = visibleDrivers();
        const visIds = new Set(vis.map((d) => d.id));
        for (const [id, m] of markers) {
            const d = store.get(id);
            if (!d || d.lat === null) {
                driverLayer.removeLayer(m.marker);
                mover.remove(m);
                markers.delete(id);
            }
        }
        vis.forEach((d) => ensureMarker(d));

        const zoom = map.getZoom();
        const hidden = new Set();
        if (vis.length > CLUSTER_MIN && zoom < CLUSTER_MAX_ZOOM) {
            const items = vis.map((d) => ({ id: d.id, lat: markers.get(d.id).pos.lat, lng: markers.get(d.id).pos.lng, status: statusOf(d) }));
            const result = gridCluster(items, (lat, lng) => map.project([lat, lng], zoom), CELL_PX, new Set(selectedId ? [selectedId] : []));
            result.clusters.forEach((c) => c.ids.forEach((id) => hidden.add(id)));
            syncClusters(result.clusters);
        } else {
            syncClusters([]);
        }

        for (const [id, m] of markers) {
            const show = visIds.has(id) && !hidden.has(id);
            const on = driverLayer.hasLayer(m.marker);
            if (show && !on) driverLayer.addLayer(m.marker);
            else if (!show && on) driverLayer.removeLayer(m.marker);
            if (show) paintMarker(id);
        }
    }
    const scheduleLayout = lazy(layout, 400);

    function applyLayers() {
        branchMarkers.forEach((b) => (layers.branches ? branchLayer.addLayer(b) : branchLayer.removeLayer(b)));
        root.classList.toggle('abm--names', layers.names);
        renderTrail();
    }

    function syncBranches(list) {
        const seen = new Set();
        for (const b of list ?? []) {
            const lat = Number(b.lat);
            const lng = Number(b.lng);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) continue;
            const key = String(b.id ?? `${lat},${lng}`);
            seen.add(key);
            let marker = branchMarkers.get(key);
            if (!marker) {
                marker = L.marker([lat, lng], { icon: pinIcon('branch', 'store'), keyboard: true, riseOnHover: true });
                marker.on('click', () => flyTo({ lat, lng }, 15));
                marker.bindTooltip(String(b.name ?? ''), { direction: 'top', offset: [0, -6], opacity: 1, className: 'abm-tip' });
                branchMarkers.set(key, marker);
            } else {
                marker.setLatLng([lat, lng]);
                marker.setTooltipContent(String(b.name ?? ''));
            }
            marker.getElement()?.setAttribute('aria-label', fmt(text.branch_label, { name: String(b.name ?? '') }));
        }
        for (const [key, marker] of branchMarkers) {
            if (!seen.has(key)) {
                branchLayer.removeLayer(marker);
                branchMarkers.delete(key);
            }
        }
        applyLayers();
        branchMarkers.forEach((m) => m.getElement()?.setAttribute('aria-label', m.getTooltip()?.getContent() ? fmt(text.branch_label, { name: String(m.getTooltip().getContent()) }) : text.legend_branch));
    }

    /* ───────────── Selection, follow, trail ───────────── */

    function highlight(id) {
        const prev = hoverId;
        hoverId = id;
        if (prev && prev !== id) paintMarker(prev);
        if (id) paintMarker(id);
    }

    function select(id, { fly = true } = {}) {
        const prev = selectedId;
        selectedId = id && store.has(id) ? id : null;
        if (prev && prev !== selectedId) paintMarker(prev);
        if (!selectedId) setFollow(false);
        scheduleLayout.cancel?.();
        layout();
        if (selectedId) {
            paintMarker(selectedId);
            const m = markers.get(selectedId);
            if (fly && m) flyTo(m.pos, Math.max(map.getZoom(), 15));
            panel.setOpen(false);
        }
        renderCard();
        renderTrail();
        renderList();
        const row = selectedId ? rows.get(selectedId) : null;
        row?.li.scrollIntoView?.({ block: 'nearest' });
        followBtn.disabled = !selectedId;
        followBtn.setAttribute('aria-label', selectedId ? text.follow_on : text.follow_pick);
        followBtn.title = followBtn.getAttribute('aria-label');
    }

    function setFollow(on) {
        followId = on && selectedId ? selectedId : null;
        followBtn.setAttribute('aria-pressed', String(followId !== null));
        const name = followId ? text.follow_off : selectedId ? text.follow_on : text.follow_pick;
        followBtn.setAttribute('aria-label', name);
        followBtn.title = name;
        card.setFollow(followId !== null, Boolean(selectedId));
        if (followId) {
            const m = markers.get(followId);
            if (m) {
                progUntil = performance.now() + 1500;
                if (reduced) map.setView([m.pos.lat, m.pos.lng], Math.max(map.getZoom(), 15), { animate: false });
                else map.panTo([m.pos.lat, m.pos.lng], { animate: true, duration: 0.8 });
            }
        }
    }

    function renderTrail() {
        trailLayer.clearLayers();
        const d = store.get(selectedId);
        if (!d) return;
        const m = markers.get(d.id);
        if (layers.trail) {
            const pts = d.trail.map((p) => [p.lat, p.lng]);
            if (m) pts.push([m.pos.lat, m.pos.lng]);
            if (pts.length >= 2) {
                const chunks = Math.min(4, pts.length - 1);
                const size = Math.ceil((pts.length - 1) / chunks);
                for (let i = 0; i < chunks; i += 1) {
                    const seg = pts.slice(i * size, Math.min(pts.length, (i + 1) * size + 1));
                    if (seg.length > 1) L.polyline(seg, { className: 'abm-trail', weight: 4, opacity: 0.25 + (0.7 * (i + 1)) / chunks, lineCap: 'round', interactive: false }).addTo(trailLayer);
                }
            }
        }
        const dest = d.job?.destination;
        if (dest && m) {
            L.polyline([[m.pos.lat, m.pos.lng], [dest.lat, dest.lng]], { className: 'abm-route-line', weight: 3, dashArray: '2 9', lineCap: 'round', interactive: false }).addTo(trailLayer);
            L.marker([dest.lat, dest.lng], { icon: pinIcon('destination', 'home'), keyboard: false, interactive: true })
                .bindTooltip(text.destination_label, { direction: 'top', offset: [0, -6], opacity: 1, className: 'abm-tip' })
                .addTo(trailLayer);
        }
    }
    const scheduleTrail = lazy(renderTrail, 600);

    /* ───────────── Card ───────────── */

    function renderCard() {
        const d = store.get(selectedId);
        if (!d) {
            card.el.hidden = true;
            return;
        }
        const n = nowMs();
        const stale = d.onDuty && isStale(d, n, staleMs);
        const st = statusOf(d);
        card.el.hidden = false;
        card.el.dataset.status = st;
        card.el.classList.toggle('is-stale', stale);
        card.el.setAttribute('aria-label', nameOf(d));
        setText(card.avatar, initials(nameOf(d)));
        card.avatar.dataset.status = st;
        card.avatar.classList.toggle('is-stale', stale);
        setText(card.name, nameOf(d));
        setText(card.status, statusText(d, n));
        card.status.dataset.status = stale ? 'stale' : st;

        const setKv = (k, value, title) => {
            const row = card.kv[k];
            row.wrap.hidden = !value;
            setText(row.dd, value || '');
            if (title) row.dd.title = title;
        };
        setKv('order', d.job ? [orderText(d), jobStatusText(d)].filter(Boolean).join(' · ') : text.card_none);
        const parked = d.speed !== null && d.speed < 2;
        setKv('speed', d.speed === null ? '' : parked ? text.card_parked : fmt(text.card_kmh, { n: nf.format(Math.round(d.speed)) }));
        const eta = d.job?.etaMinutes ?? (d.job?.etaAt ? Math.max(0, Math.round((d.job.etaAt - n) / 60000)) : null);
        setKv('eta', eta === null ? '' : fmt(text.card_eta_min, { n: nf.format(Math.max(1, Math.round(eta))) }));
        setKv('updated', d.seenAt ? agoText(n - d.seenAt, lang, text) : text.no_location, d.seenAt ? clockText(d.seenAt, lang, true) : '');
        setKv('vehicle', d.vehicle ?? '');
        setKv('branch', mode === 'admin' ? d.branches.join(' · ') : '');
        setKv('accuracy', d.accuracy === null ? '' : fmt(text.card_meters, { n: nf.format(Math.round(d.accuracy)) }));

        const href = mode === 'admin' && d.job ? o.orderHref?.(d.job) : null;
        card.open.hidden = !href;
        if (href && card.open.getAttribute('href') !== href) card.open.setAttribute('href', href);
        if (o.navigate && href) card.open.setAttribute('wire:navigate', '');
        card.viewBtn.hidden = !(mode === 'partner' && d.job?.orderNumber && o.onViewOrder);
        card.viewBtn.onclick = () => o.onViewOrder?.(d.job);

        const tel = mode === 'admin' && o.canCall !== false ? phoneHref(d.phone) : null;
        card.callRow.hidden = !tel;
        if (tel) {
            card.call.setAttribute('href', tel);
            setText(card.call.querySelector('.abm-btn__t'), fmt(`${text.card_call} :name`, { name: firstName(d.name) }));
            setText(card.phoneText, d.phone);
        }
        card.setFollow(followId === d.id, true);
    }

    /* ───────────── Panel ───────────── */

    function rowView(d, n) {
        const stale = d.onDuty && isStale(d, n, staleMs);
        const parts = [];
        if (d.lat === null) parts.push(text.no_location);
        else parts.push([orderText(d), statusText(d, n)].filter(Boolean).join(' · '));
        return {
            status: statusOf(d),
            stale,
            moving: isMoving(d, n, staleMs),
            hasPosition: d.lat !== null,
            selected: d.id === selectedId,
            initials: initials(nameOf(d)),
            name: nameOf(d),
            sub: parts.join(''),
            meta: mode === 'admin' ? d.branches.join(' · ') : '',
            age: d.seenAt && d.onDuty ? agoText(n - d.seenAt, lang, text) : '',
            label: `${nameOf(d)}, ${statusText(d, n)}`,
        };
    }

    function renderList() {
        const n = nowMs();
        const all = [...store.values()];
        const shown = sortDrivers(filterDrivers(all, filters, n, staleMs), n, staleMs);
        const keep = new Set(shown.map((d) => d.id));
        for (const [id, r] of rows) {
            if (!keep.has(id)) {
                r.li.remove();
                rows.delete(id);
            }
        }
        const ordered = shown.map((d) => {
            let r = rows.get(d.id);
            if (!r) {
                r = buildRow(d.id, (id) => select(id));
                rows.set(d.id, r);
            }
            updateRow(r, rowView(d, n));
            return r.li;
        });
        const cur = panel.list.children;
        let same = cur.length === ordered.length;
        for (let i = 0; same && i < ordered.length; i += 1) if (cur[i] !== ordered[i]) same = false;
        if (!same) panel.list.replaceChildren(...ordered);
        setText(panel.note, all.length === 0 ? text.empty_list : shown.length === 0 ? text.no_match : '');
        panel.note.hidden = panel.note.textContent === '';
        setText(panel.handleCount, `(${nf.format(all.length)})`);
    }
    const scheduleList = lazy(renderList, 1000);

    function renderCounts() {
        const c = counts([...store.values()], nowMs(), staleMs);
        Object.entries({ onDuty: c.onDuty, moving: c.moving, idle: c.idle, stale: c.stale }).forEach(([k, v]) => setText(panel.stats[k], nf.format(v)));
        const none = loaded && c.onDuty === 0;
        overlays.empty.hidden = !none;
        return c;
    }
    const scheduleCounts = lazy(renderCounts, 1000);

    function applyFilters() {
        renderList();
        layout();
    }

    /* ───────────── Data in ───────────── */

    function ingest(list, serverTime) {
        const n = nowMs();
        const seen = new Set();
        for (const raw of list ?? []) {
            const next = normaliseDriver(raw, { now: n, serverNow: serverTime });
            if (!next) continue;
            seen.add(next.id);
            const merged = applyFull(store.get(next.id), next);
            const prevPos = markers.get(next.id)?.pos;
            store.set(next.id, merged);
            if (merged.lat !== null) {
                const m = markers.get(next.id);
                if (m && (!prevPos || prevPos.lat !== merged.lat || prevPos.lng !== merged.lng || prevPos.heading !== merged.heading)) moveMarker(merged, true);
                else if (!m) moveMarker(merged, false);
            }
        }
        for (const id of [...store.keys()]) {
            if (!seen.has(id)) {
                store.delete(id);
                if (id === selectedId) select(null);
            }
        }
    }

    /** Full rows from the server (Livewire render, partner fetch). */
    function update(drivers, branchList, { serverTime = null, fit = false } = {}) {
        if (destroyed) return;
        ingest(drivers, serverTime ?? o.serverTime ?? null);
        o.serverTime = null;
        if (branchList) syncBranches(branchList);
        loaded = true;
        overlays.skeleton.hidden = true;
        setError(false);
        layout();
        renderList();
        renderCounts();
        renderCard();
        renderTrail();
        if (fit || (!fitted && !userMoved)) {
            fitAll(false);
            fitted = [...store.values()].some((d) => d.lat !== null) || branchMarkers.size > 0;
        }
    }

    /** A live "driver.moved" frame. */
    function move(raw) {
        if (destroyed) return;
        const m = normaliseMove(raw);
        if (!m) return;
        const prev = store.get(m.id);
        if (!prev) {
            const n = nowMs();
            if (n - lastUnknownRefresh > 10_000) {
                lastUnknownRefresh = n;
                runRefresh();
            }
            return;
        }
        const next = applyMove(prev, m);
        if (!next) return;
        store.set(m.id, next);
        if (prev.lat === null) scheduleLayout();
        else moveMarker(next, true);
        if (m.id === selectedId) {
            renderCard();
            scheduleTrail();
        }
        scheduleList();
        scheduleCounts();
        const mk = markers.get(m.id);
        if (mk && (prev.job?.status !== next.job?.status || statusOf(prev) !== statusOf(next))) paintMarker(m.id);
    }

    /* ───────────── View ───────────── */

    const sheetPad = () => (getComputedStyle(panel.el).position === 'absolute' ? panel.el.offsetHeight : 0);

    function fitAll(userAction) {
        const pts = visibleDrivers().map((d) => ({ lat: d.lat, lng: d.lng }));
        if (layers.branches || !pts.length) branchMarkers.forEach((b) => pts.push({ lat: b.getLatLng().lat, lng: b.getLatLng().lng }));
        progUntil = performance.now() + 1200;
        base.fit(pts, { maxZoom: 15, padding: [56, 56], paddingBottomRight: [56, 56 + sheetPad()] });
        if (userAction) {
            userMoved = false;
            setFollow(false);
        }
    }

    function flyTo(pos, zoom = 16) {
        progUntil = performance.now() + 1500;
        base.flyTo([pos.lat, pos.lng], zoom);
    }

    function zoomToIds(ids) {
        const pts = ids.map((id) => markers.get(id)?.pos).filter(Boolean);
        progUntil = performance.now() + 1200;
        if (pts.length) map.fitBounds(L.latLngBounds(pts.map((p) => [p.lat, p.lng])), { padding: [70, 70], maxZoom: 17, animate: !reduced });
    }

    let hadSize = map.getSize().x > 0 && map.getSize().y > 0;
    base.onResized((size) => {
        const has = size.x > 0 && size.y > 0;
        if (has && !hadSize && !userMoved) fitAll(false);
        hadSize = has;
    });
    map.on('dragstart', () => { userMoved = true; if (followId) setFollow(false); });
    map.on('zoomstart', () => { if (performance.now() > progUntil) userMoved = true; });
    map.on('zoomend', () => {
        stage.dataset.zoom = map.getZoom() >= 14 ? 'hi' : 'lo';
        scheduleLayout();
    });
    stage.dataset.zoom = map.getZoom() >= 14 ? 'hi' : 'lo';
    base.on(root, 'keydown', (e) => {
        if (e.key === 'Escape' && selectedId && !base.isFullscreen()) select(null);
    });
    base.on(panel.list, 'mouseover', (e) => highlight(e.target.closest?.('.abm-row')?.dataset.id ?? null));
    base.on(panel.list, 'mouseleave', () => highlight(null));
    base.on(panel.list, 'focusin', (e) => highlight(e.target.closest?.('.abm-row')?.dataset.id ?? null));
    base.on(panel.list, 'focusout', () => highlight(null));

    // Swipe the bottom sheet (narrow screens): up opens, down closes.
    let swipeY = null;
    const handle = panel.el.querySelector('.abm-panel__handle');
    base.on(handle, 'pointerdown', (e) => { swipeY = e.clientY; });
    base.on(handle, 'pointerup', (e) => {
        if (swipeY === null) return;
        const dy = e.clientY - swipeY;
        swipeY = null;
        if (Math.abs(dy) > 28) {
            panel.setOpen(dy < 0);
            handle.dataset.swiped = '1';
            setTimeout(() => delete handle.dataset.swiped, 50);
        }
    });
    base.on(handle, 'click', (e) => { if (handle.dataset.swiped) e.stopImmediatePropagation(); }, true);

    /* ───────────── Connection, polling, ticker ───────────── */

    const pollSeconds = () => (connection === 'live' ? (o.poll?.live ?? 30) : (o.poll?.down ?? 15));

    function setConnection(state) {
        const was = connection;
        connection = state;
        conn.set(state, pollSeconds());
        restartPoll();
        if (state === 'live' && was !== 'live') runRefresh();
    }

    function setError(on) {
        overlays.error.hidden = !on;
    }

    async function runRefresh() {
        if (!o.refresh || refreshing || destroyed || document.hidden) return;
        refreshing = true;
        try {
            await o.refresh();
            setError(false);
        } catch {
            setError(true);
        } finally {
            refreshing = false;
        }
    }

    function restartPoll() {
        clearInterval(pollTimer);
        pollTimer = null;
        if (!o.refresh || suspended || document.hidden || destroyed) return;
        pollTimer = setInterval(runRefresh, pollSeconds() * 1000);
    }

    function refreshFlags() {
        const n = nowMs();
        for (const [id, d] of store) {
            if (markers.has(id)) paintMarker(id);
            const r = rows.get(id);
            if (r) updateRow(r, rowView(d, n));
        }
        renderCounts();
        if (tick % 10 === 0) renderList();
    }

    function startTicker() {
        clearInterval(tickTimer);
        tickTimer = setInterval(() => {
            if (document.hidden || suspended) return;
            tick += 1;
            if (selectedId) renderCard();
            if (tick % 5 === 0) refreshFlags();
        }, 1000);
    }

    function onVisibility() {
        if (document.hidden) {
            clearInterval(pollTimer);
            pollTimer = null;
        } else if (!suspended) {
            runRefresh();
            restartPoll();
            refreshFlags();
        }
    }
    base.on(document, 'visibilitychange', onVisibility);

    /* ───────────── Boot ───────────── */

    conn.set(connection, pollSeconds());
    root.classList.toggle('abm--names', layers.names);
    followBtn.setAttribute('aria-label', text.follow_pick);
    card.setFollow(false, false);
    if (o.view) map.setView([o.view.center.lat, o.view.center.lng], o.view.zoom, { animate: false });
    syncBranches(o.branches);
    if (o.drivers) update(o.drivers, null, { serverTime: o.serverTime ?? null });
    else renderList();
    renderCounts();
    startTicker();
    restartPoll();

    const controller = {
        update,
        move,
        select,
        fitAll: () => fitAll(true),
        setConnection,
        setError,
        setLoading(on) {
            loaded = !on;
            overlays.skeleton.hidden = !on;
            renderCounts();
        },
        getView: () => ({ center: map.getCenter(), zoom: map.getZoom() }),
        suspend() {
            suspended = true;
            clearInterval(pollTimer);
            pollTimer = null;
        },
        resume() {
            if (destroyed) return;
            suspended = false;
            map.invalidateSize({ pan: false });
            runRefresh();
            restartPoll();
            refreshFlags();
        },
        destroy() {
            if (destroyed) return;
            destroyed = true;
            clearInterval(pollTimer);
            clearInterval(tickTimer);
            mover.stop();
            [scheduleLayout, scheduleList, scheduleCounts, scheduleTrail].forEach((f) => f.cancel?.());
            base.destroy();
            claimHost(host, null);
        },
        get map() { return map; },
        get selectedId() { return selectedId; },
    };
    claimHost(host, controller);
    return controller;
}
