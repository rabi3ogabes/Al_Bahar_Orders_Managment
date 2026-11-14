// The admin "Live fleet" page glue: feeds the shared fleet map from the Livewire component ($wire), listens to Reverb for live
// positions and falls back to polling the component when the socket is not available.

import { createFleetMap } from './fleet.js';
import { connectDriverMoves } from './realtime.js';
import { textFor } from './text.js';
import { pageLang } from './dom.js';

/**
 * @param {HTMLElement} host
 * @param {object} boot  { config, drivers, branches, serverTime, channels, orderUrl, canCall }
 * @param {object} wire  the Livewire $wire proxy of the page component
 */
export function mountAdminFleet(host, boot, wire) {
    const lang = pageLang();
    let lastBranch = boot.branch ?? '';
    let realtime = null;
    let offUpdated = null;

    const fleet = createFleetMap({
        host,
        config: boot.config,
        text: textFor(lang),
        lang,
        mode: 'admin',
        drivers: boot.drivers,
        branches: boot.branches,
        serverTime: boot.serverTime,
        connection: 'polling',
        poll: { live: 30, down: 15 },
        navigate: true,
        canCall: boot.canCall,
        orderHref: (job) => (job?.orderId ? boot.orderUrl.replace('__ID__', encodeURIComponent(job.orderId)) : null),
        refresh: () => wire.$refresh(),
    });

    const onUpdated = (e) => {
        const d = (Array.isArray(e) ? e[0] : e) ?? {};
        const branchChanged = (d.branch ?? '') !== lastBranch;
        lastBranch = d.branch ?? '';
        fleet.update(d.drivers ?? [], d.branches ?? null, { serverTime: d.server_time ?? null, fit: branchChanged });
        if (Array.isArray(d.channels)) realtime?.setChannels(d.channels);
    };
    offUpdated = wire.on('fleet-updated', onUpdated);

    // A burst of order / delivery messages is one refresh.
    let dispatchTimer = null;
    const onDispatch = () => {
        clearTimeout(dispatchTimer);
        dispatchTimer = setTimeout(() => fleet.refresh(), 700);
    };
    realtime = connectDriverMoves({ channels: boot.channels, onMove: (frame) => fleet.move(frame), onState: (s) => fleet.setConnection(s), onDispatch });

    const onShow = (e) => { if (e.persisted) fleet.resume(); };
    const onHide = () => fleet.suspend();
    window.addEventListener('pageshow', onShow);
    window.addEventListener('pagehide', onHide);

    let gone = false;
    const destroy = () => {
        if (gone) return;
        gone = true;
        window.removeEventListener('pageshow', onShow);
        window.removeEventListener('pagehide', onHide);
        document.removeEventListener('livewire:navigating', destroy);
        if (typeof offUpdated === 'function') offUpdated();
        realtime?.stop();
        fleet.destroy();
    };
    document.addEventListener('livewire:navigating', destroy);

    return { fleet, destroy };
}
