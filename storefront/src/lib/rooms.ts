import { useQuery } from '@tanstack/react-query';
import { get } from './api';
import { useBranch } from './branch';
import { useI18n } from './i18n';
import type { Room } from './roomsCore';

export { findRoom, roomHref, type Room } from './roomsCore';

/** The rooms of "Shop by room" at the shopper's branch (the shop filter, the room page). The home page gets its tiles inside `GET /home`. */
export function useRooms() {
  const { branchId } = useBranch();
  const { locale } = useI18n();
  return useQuery({ queryKey: ['rooms', branchId, locale], staleTime: 5 * 60_000, queryFn: async () => (await get<{ data: Room[] }>('/rooms', { branch_id: branchId })).data });
}
