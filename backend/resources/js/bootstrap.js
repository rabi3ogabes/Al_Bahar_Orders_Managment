import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

/**
 * Lazily open the Reverb connection: only screens with live updates (e.g. the auctioneer console) call
 * window.echo(), so ordinary admin pages never open a WebSocket. Works across wire:navigate page swaps.
 */
window.echo = () => {
    if (!import.meta.env.VITE_REVERB_APP_KEY) return null;
    window.Echo ??= new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
    return window.Echo;
};
