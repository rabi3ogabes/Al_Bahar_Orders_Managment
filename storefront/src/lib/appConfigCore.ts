// The public settings the store reads from `GET /app-config` before anyone signs in. Pure (no imports) so it runs in plain node tests (tests/app-config.test.mjs).

export interface AppConfig { alternate_phone_enabled?: boolean; returns_enabled?: boolean }

/** Anything but an explicit `false` means on: an older server, a failed request or a missing value never hides the field. */
export const altPhoneEnabled = (c?: { alternate_phone_enabled?: unknown } | null): boolean => c?.alternate_phone_enabled !== false;

/** The last config seen on this device (so the first paint already follows the dashboard switch), or empty when there is none or it is unreadable. */
export function parseCachedConfig(raw: string | null): AppConfig {
  if (!raw) return {};
  try {
    const v = JSON.parse(raw);
    return v && typeof v === 'object' && !Array.isArray(v) ? { alternate_phone_enabled: typeof v.alternate_phone_enabled === 'boolean' ? v.alternate_phone_enabled : undefined } : {};
  } catch { return {}; }
}
