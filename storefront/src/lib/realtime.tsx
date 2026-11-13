import { useEffect, useState, useSyncExternalStore } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { get, getTokens } from './api';
import { useAuth } from './auth';
import { useI18n } from './i18n';
import { useToast } from './toast';
import { orderToasts } from './webpush-core';

/**
 * Live order updates. One socket per signed-in customer (Laravel Reverb, Pusher protocol) on their private channel;
 * every `order.updated` refreshes the order queries, shows a short toast and flashes the changed row. The socket library
 * is loaded on demand. If the socket is not available (not configured, blocked, down) the pages fall back to polling:
 * `useLivePoll()` returns an interval only while the socket is not live.
 */
export type LiveState = 'off' | 'connecting' | 'live' | 'reconnecting';

let state: LiveState = 'off';
const subs = new Set<() => void>();
const setState = (s: LiveState) => { if (state !== s) { state = s; subs.forEach((f) => f()); } };
const subscribe = (f: () => void) => { subs.add(f); return () => { subs.delete(f); }; };
export const useLiveState = (): LiveState => useSyncExternalStore(subscribe, () => state, () => 'off' as LiveState);

/** Polling interval (ms) to use while the socket is not live, or false when updates arrive by push. */
export const useLivePoll = (everyMs = 20_000): number | false => (useLiveState() === 'live' ? false : everyMs);

interface OrderEvent { order_id: string; number: string; status: string; status_label: string; delivery_status: string | null }
const changeSubs = new Set<(e: OrderEvent) => void>();

/** Ids of orders that changed in the last few seconds (for a brief highlight). */
export function useFlashingOrders(): Set<string> {
  const [ids, setIds] = useState<Set<string>>(new Set());
  useEffect(() => {
    const timers = new Map<string, number>();
    const on = (e: OrderEvent) => {
      setIds((s) => new Set(s).add(e.order_id));
      window.clearTimeout(timers.get(e.order_id));
      timers.set(e.order_id, window.setTimeout(() => setIds((s) => { const n = new Set(s); n.delete(e.order_id); return n; }), 2400));
    };
    changeSubs.add(on);
    return () => { changeSubs.delete(on); timers.forEach((t) => window.clearTimeout(t)); };
  }, []);
  return ids;
}

interface RealtimeConfig { enabled: boolean; key: string | null; auth_endpoint: string; path: string }

/** Mounted once under the providers. Renders nothing. */
export function LiveOrders() {
  const { user } = useAuth();
  const qc = useQueryClient();
  const toast = useToast();
  const { t } = useI18n();
  const userId = user?.id;

  useEffect(() => {
    if (!userId) { setState('off'); return; }
    let closed = false;
    let pusher: import('pusher-js').default | null = null;
    const onVisible = () => { if (document.visibilityState === 'visible') void qc.invalidateQueries({ queryKey: ['orders'] }); };

    (async () => {
      try {
        setState('connecting');
        const cfg = (await get<{ data: RealtimeConfig }>('/realtime-config')).data;
        if (closed || !cfg.enabled || !cfg.key) { setState('off'); return; }
        const { default: Pusher } = await import('pusher-js');
        if (closed) return;
        const https = window.location.protocol === 'https:';
        const port = Number(window.location.port) || (https ? 443 : 80);
        pusher = new Pusher(cfg.key, {
          cluster: 'mt1', wsHost: window.location.hostname, wsPort: port, wssPort: port, wsPath: '', forceTLS: https,
          enabledTransports: ['ws', 'wss'], disableStats: true,
          channelAuthorization: {
            endpoint: cfg.auth_endpoint, transport: 'ajax',
            headersProvider: () => ({ Accept: 'application/json', Authorization: `Bearer ${getTokens()?.access_token ?? ''}` }),
          },
        });
        pusher.connection.bind('state_change', ({ current }: { current: string }) => {
          if (closed) return;
          setState(current === 'connected' ? 'live' : current === 'connecting' || current === 'initialized' ? 'connecting' : 'reconnecting');
          // After a reconnect, catch up on anything missed while the socket was down.
          if (current === 'connected') void qc.invalidateQueries({ queryKey: ['orders'] });
        });
        const join = (retry: boolean) => {
          const ch = pusher!.subscribe(`private-users.${userId}`);
          ch.bind('order.updated', (e: OrderEvent) => {
            void qc.invalidateQueries({ queryKey: ['orders'] });
            void qc.invalidateQueries({ queryKey: ['order', e.order_id] });
            void qc.invalidateQueries({ queryKey: ['timeline', e.order_id] });
            // A push message for the same change may have shown its own toast a moment ago (lib/webpush.tsx): one toast per change.
            if (orderToasts.claim('socket', e.order_id)) toast.push(t('orders.live_update', { n: e.number, s: e.status_label }), 'info');
            changeSubs.forEach((f) => f(e));
          });
          ch.bind('pusher:subscription_error', async () => {
            if (closed || !retry) { setState('reconnecting'); return; }
            try { await get('/me'); } catch { /* refresh failed: the poll fallback keeps the page current */ }
            pusher?.unsubscribe(`private-users.${userId}`);
            if (!closed) join(false);
          });
        };
        join(true);
        document.addEventListener('visibilitychange', onVisible);
      } catch {
        if (!closed) setState('off');
      }
    })();

    return () => {
      closed = true;
      document.removeEventListener('visibilitychange', onVisible);
      pusher?.disconnect();
      setState('off');
    };
  // The toast/i18n helpers are stable enough; reconnecting on every language change would drop the socket needlessly.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [userId, qc]);

  return null;
}

/** Small "Live" pill for the orders heading: green when pushed in real time, amber while reconnecting. */
export function LivePill() {
  const s = useLiveState();
  const { t } = useI18n();
  if (s === 'off' || s === 'connecting') return null;
  const live = s === 'live';
  return (
    <span role="status" className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold ${live ? 'border-success/40 text-success' : 'border-warning/40 text-warning'}`}>
      <span className={`h-2 w-2 rounded-full ${live ? 'bg-success live-dot' : 'bg-warning'}`} aria-hidden="true" />
      {live ? t('orders.live') : t('orders.reconnecting')}
    </span>
  );
}
