import test from 'node:test';
import assert from 'node:assert/strict';
import { findRoom, roomColumns, roomHref, roomSrcSet, visibleRooms } from '../src/lib/roomsCore.ts';
import { en } from '../src/locales/en.ts';
import { ar } from '../src/locales/ar.ts';

const room = (slug, over = {}) => ({ id: `id-${slug}`, slug, name: slug[0].toUpperCase() + slug.slice(1), description: null, image_url: null, thumb_url: null, products_count: 3, link: { type: 'room', value: slug }, ...over });

test('a room tile leads to the shop filtered to that room, with the address word encoded', () => {
  assert.equal(roomHref(room('bedroom')), '/shop?room=bedroom');
  assert.equal(roomHref({ slug: 'living room&x=1' }), '/shop?room=living%20room%26x%3D1');
});

test('findRoom matches the address word only, and an empty word finds nothing', () => {
  const rooms = [room('bedroom'), room('kitchen')];
  assert.equal(findRoom(rooms, 'kitchen')?.id, 'id-kitchen');
  assert.equal(findRoom(rooms, 'dining'), undefined);
  assert.equal(findRoom(rooms, ''), undefined);
  assert.equal(findRoom(undefined, 'kitchen'), undefined);
});

test('only rooms with something in them are shown, in the server order, and not more than the limit', () => {
  const items = [room('a'), room('b', { products_count: 0 }), room('c', { name: '' }), room('d'), room('e'), room('f')];
  assert.deepEqual(visibleRooms(items).map((r) => r.slug), ['a', 'd', 'e', 'f']);
  assert.deepEqual(visibleRooms(items, 2).map((r) => r.slug), ['a', 'd']);
  assert.deepEqual(visibleRooms([]), []);
});

test('four rooms fill a row of four and more fill a row of five', () => {
  assert.equal(roomColumns(1), 'lg:grid-cols-4');
  assert.equal(roomColumns(4), 'lg:grid-cols-4');
  assert.equal(roomColumns(5), 'lg:grid-cols-5');
  assert.equal(roomColumns(8), 'lg:grid-cols-5');
});

test('a room with its own picture offers the small and the big copy; one without offers one file', () => {
  assert.equal(roomSrcSet(room('a', { image_url: 'https://x/r.webp', thumb_url: 'https://x/r-480.webp' })), 'https://x/r-480.webp 480w, https://x/r.webp 1000w');
  assert.equal(roomSrcSet(room('a', { image_url: 'https://x/category.webp' })), undefined);
  assert.equal(roomSrcSet(room('a')), undefined);
});

test('every room text exists in English and Arabic', () => {
  for (const k of ['rooms.title', 'rooms.eyebrow', 'rooms.count', 'rooms.count_one', 'filter.room']) assert.ok(en[k]?.trim() && ar[k]?.trim(), k);
  assert.ok(en['rooms.count'].includes('{n}') && ar['rooms.count'].includes('{n}'));
});
