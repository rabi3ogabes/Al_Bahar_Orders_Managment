import { useQuery } from '@tanstack/react-query';
import { get } from './api';
import { altPhoneEnabled, parseCachedConfig, type AppConfig } from './appConfigCore';

const KEY = 'albahar.app-config';

function cached(): AppConfig {
  try { return parseCachedConfig(localStorage.getItem(KEY)); } catch { return {}; }
}

/**
 * Public store settings from `/app-config`. Never blocks rendering: until the answer arrives the page uses the last value seen on this device,
 * and when there is none every switch is on (the way the store behaved before the switches existed).
 */
export function useAppConfig(): AppConfig {
  const q = useQuery({
    queryKey: ['app-config'],
    queryFn: async () => {
      const data = (await get<{ data: AppConfig }>('/app-config')).data;
      try { localStorage.setItem(KEY, JSON.stringify({ alternate_phone_enabled: data.alternate_phone_enabled })); } catch { /* private mode: the live value still works */ }
      return data;
    },
    placeholderData: cached,
    staleTime: 60_000,
    retry: 1,
  });
  return q.data ?? {};
}

/** Does the dashboard switch "Ask for an alternative phone number" allow the field in the profile and at checkout? */
export const useAltPhoneEnabled = (): boolean => altPhoneEnabled(useAppConfig());
