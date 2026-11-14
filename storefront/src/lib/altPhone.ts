// Arabic-Indic and Persian digits become 0-9 (the same conversion as `latinDigits` in format.ts, kept here so this file has no imports and runs in plain node tests).
const latin = (v: string) => v.replace(/[\u0660-\u0669]/g, (c) => String(c.charCodeAt(0) - 0x660)).replace(/[\u06f0-\u06f9]/g, (c) => String(c.charCodeAt(0) - 0x6f0));

/**
 * The customer's second number, which the delivery driver may call when the main one is not answered.
 * The same rule as the app and the server: a Qatar mobile number (8 digits starting 3, 5, 6 or 7; Arabic digits accepted) that is not the account's main number.
 * Tested in tests/alt-phone.test.mjs.
 */
export type AltPhoneStatus = 'valid' | 'declined' | 'missing' | 'invalid' | 'same';

/** `+974XXXXXXXX`, or null when the text is not a valid Qatar mobile number. */
export function toE164(input: string): string | null {
  let digits = latin(input).replace(/[^\d+]/g, '');
  if (digits.startsWith('+')) digits = digits.slice(1);
  else if (digits.startsWith('00')) digits = digits.slice(2);
  if (digits.startsWith('974') && digits.length === 11) digits = digits.slice(3);
  return /^[3567]\d{7}$/.test(digits) ? `+974${digits}` : null;
}

/** The 8 national digits of a saved number (what the field shows after the +974 prefix). */
export const nationalDigits = (e164: string | null | undefined): string => {
  const n = e164 ? toE164(e164) : null;
  return n ? n.slice(4) : '';
};

export function altPhoneStatus(text: string, declined: boolean, mainPhone?: string | null): AltPhoneStatus {
  if (text.trim()) {
    const n = toE164(text);
    if (!n) return 'invalid';
    return mainPhone && toE164(mainPhone) === n ? 'same' : 'valid';
  }
  return declined ? 'declined' : 'missing';
}

/** Whether placing may go ahead: a good number, or the tick. */
export const altPhoneDone = (s: AltPhoneStatus) => s === 'valid' || s === 'declined';

/** The fields of the order request once answered (both keys, so the server knows this client asks the question). Nothing before. */
export function altPhoneFields(text: string, declined: boolean, mainPhone?: string | null): { alternate_phone: string | null; no_alternate_phone: boolean } | Record<string, never> {
  const s = altPhoneStatus(text, declined, mainPhone);
  if (!altPhoneDone(s)) return {};
  return { alternate_phone: s === 'valid' ? toE164(text) : null, no_alternate_phone: s === 'declined' };
}
