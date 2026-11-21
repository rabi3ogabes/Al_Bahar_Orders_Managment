// "Shop by room": the pure helpers (tests/rooms.test.mjs). No imports, so they run in plain node.

/** One room as `GET /rooms` and the `rooms` section of `GET /home` serve it. The server leaves out rooms with no product. */
export interface Room { id: string; slug: string; name: string; description: string | null; image_url: string | null; thumb_url?: string | null; products_count: number; link: { type: string; value: string } }

/** Where a room's tile leads: the shop filtered to that room. */
export const roomHref = (r: Pick<Room, 'slug'>): string => `/shop?room=${encodeURIComponent(r.slug)}`;

/** The room with this address word, if the shop knows it (a hidden or empty room is not in the list). */
export const findRoom = (rooms: readonly Room[] | undefined, slug: string): Room | undefined => (slug ? rooms?.find((r) => r.slug === slug) : undefined);

/** How many columns the tiles take on a wide screen: four rooms fill a row of four, more fill a row of five. */
export const roomColumns = (n: number): string => (n <= 4 ? 'lg:grid-cols-4' : 'lg:grid-cols-5');

/** Rooms a page may show: the ones with something in them, in the server's order, and never more than `limit`. */
export const visibleRooms = (items: readonly Room[], limit = 12): Room[] => items.filter((r) => r.products_count > 0 && r.slug && r.name).slice(0, limit);

/** The picture's `srcset`: the small copy (480 px) and the big one (1000 px) when the room has its own picture; one file otherwise (a category's picture has no small copy). */
export const roomSrcSet = (r: Pick<Room, 'image_url' | 'thumb_url'>): string | undefined => (r.image_url && r.thumb_url ? `${r.thumb_url} 480w, ${r.image_url} 1000w` : undefined);
