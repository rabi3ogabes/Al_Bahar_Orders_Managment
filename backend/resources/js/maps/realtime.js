// Connects the fleet map to Reverb (Laravel Echo): private channels carrying "driver.moved" (and "dispatch.updated" for the jobs), and an honest connection state.
// Without Echo, or when the channels are refused, the state is "polling" and the map refreshes on its timer instead.

/** @returns {{ setChannels(list: string[]): void, stop(): void }} */
export function connectDriverMoves({ channels, onMove, onState, onDispatch }) {
    const echo = typeof window.echo === 'function' ? window.echo() : null;
    if (!echo) {
        onState('polling');
        return { setChannels() {}, stop() {} };
    }
    const connection = echo.connector?.pusher?.connection;
    const joined = new Set();
    const failed = new Set();

    const state = () => {
        if (joined.size > 0 && failed.size >= joined.size) return 'polling';
        return connection?.state === 'connected' ? 'live' : 'reconnecting';
    };
    const report = () => onState(state());
    const onChange = () => report();
    connection?.bind('state_change', onChange);

    const join = (name) => {
        if (joined.has(name)) return;
        joined.add(name);
        const channel = echo.private(name);
        channel.listen('.driver.moved', (frame) => onMove(frame));
        // An order or a delivery moved (accepted, assigned, picked up, delivered, failed): the jobs on the map change without waiting for the poll.
        if (onDispatch) channel.listen('.dispatch.updated', (message) => onDispatch(message));
        channel.error?.(() => {
            failed.add(name);
            report();
        });
    };
    const leave = (name) => {
        joined.delete(name);
        failed.delete(name);
        try { echo.leave(name); } catch { /* socket already closed */ }
    };

    channels.forEach(join);
    report();

    return {
        setChannels(list) {
            const next = new Set(list);
            [...joined].filter((n) => !next.has(n)).forEach(leave);
            list.forEach(join);
            report();
        },
        stop() {
            connection?.unbind('state_change', onChange);
            [...joined].forEach(leave);
        },
    };
}
