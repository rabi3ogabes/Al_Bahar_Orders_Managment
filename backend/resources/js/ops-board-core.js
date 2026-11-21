// Pure logic of the live operations board (no DOM, no timers), tested with `npm test` (tests/js/ops-board-core.test.mjs).
// The Alpine component in ops-board.js wires it to the page.

/** Lanes that get a "new card" welcome: a new order, and anything that has just gone wrong. Cards that only moved on are not announced. */
export const WELCOME_LANES = ['new', 'attention'];
export const FRESH_MS = 9000;
/** A board that has not been re-drawn for this many polls is "not updating". */
export const STALE_POLLS = 3;
export const STALE_MIN_S = 60;

/**
 * Where the board's updates come from, in the words the person sees:
 *  live          the socket is connected: new orders and moves arrive by themselves (the timer is only a safety net)
 *  reconnecting  the socket is trying: the board checks on a timer meanwhile
 *  polling       there is no socket (not set up, blocked, refused): the board checks on a timer
 *
 * @param {{hasEcho: boolean, state?: string, joined?: number, failed?: number}} c  the Echo connection state and how many private channels were joined / refused
 */
export function linkState({ hasEcho, state = 'initialized', joined = 0, failed = 0 }) {
    if (!hasEcho || state === 'failed') return 'polling';
    if (joined > 0 && failed >= joined) return 'polling';
    return state === 'connected' ? 'live' : 'reconnecting';
}

/** Seconds between two checks: slow when the socket is live (a safety net and the clocks), quick when it is not. */
export const pollSeconds = (link, poll) => (link === 'live' ? poll.live : poll.down);

/** The board is stale when it has not been re-drawn for STALE_POLLS polls (and at least a minute): the connection to the server is probably gone. */
export const isStale = (renderedMs, nowMs, link, poll) => nowMs - renderedMs > Math.max(STALE_MIN_S, pollSeconds(link, poll) * STALE_POLLS) * 1000;

/** The text of the indicator. `text.polling` holds ":s" for the seconds. */
export function linkText(link, stale, text, poll) {
    if (stale) return text.stale;
    if (link === 'live') return text.live;
    if (link === 'reconnecting') return text.reconnecting;
    return String(text.polling).replace(':s', String(pollSeconds('polling', poll)));
}

/**
 * Cards to welcome since the last look: a card that has just appeared in the New or Needs attention lane, or one that has just MOVED into Needs attention
 * (it turned red, failed, or the customer asked to cancel). A card that only moved on to the next step is not announced, and the first look only learns what
 * is on the board (a page that has just opened has nothing new). Returns the ids to welcome and the lane of every card now.
 *
 * @param {Map<string, string>|null} seen  card id => the lane it was in at the last look; null on the first look
 * @param {Array<{id: string, lane: string}>} cards  what is on the board now
 * @returns {{fresh: string[], seen: Map<string, string>}}
 */
export function welcome(seen, cards) {
    const now = new Map(cards.map((c) => [c.id, c.lane]));
    if (seen === null) return { fresh: [], seen: now };
    const fresh = cards.filter((c) => {
        const before = seen.get(c.id);
        return before === undefined ? WELCOME_LANES.includes(c.lane) : c.lane === 'attention' && before !== 'attention';
    }).map((c) => c.id);

    return { fresh, seen: now };
}

/** The wall clock of the shop (Qatar time), with Latin digits whatever the dashboard language. */
export function clockText(date, lang = 'en', zone = 'Asia/Qatar') {
    try {
        return new Intl.DateTimeFormat(`${String(lang).slice(0, 2)}-u-nu-latn`, { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: zone }).format(date);
    } catch {
        return '';
    }
}
