import { useState, type FormEvent } from 'react';
import { ApiError, patch, post } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useI18n } from '../lib/i18n';
import type { Address } from '../lib/types';
import { normalizePhone } from './AuthModal';
import { Alert, Button, Checkbox, Input, Textarea } from './ui';

export function AddressForm({ initial, onSaved, onCancel }: { initial?: Address; onSaved: (a: Address) => void; onCancel?: () => void }) {
  const { t } = useI18n();
  const { user } = useAuth();
  const [v, setV] = useState({
    label: initial?.label ?? '', recipient_name: initial?.recipient_name ?? user?.name ?? '', recipient_phone: initial?.recipient_phone ?? user?.phone ?? '',
    zone_number: initial?.zone_number ?? '', street_number: initial?.street_number ?? '', building_number: initial?.building_number ?? '', unit_number: initial?.unit_number ?? '',
    area: initial?.area ?? '', landmark: initial?.landmark ?? '', instructions: initial?.instructions ?? '', is_default: initial?.is_default ?? false,
  });
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<ApiError | null>(null);
  const set = (k: keyof typeof v) => (e: { target: { value: string } }) => setV({ ...v, [k]: e.target.value });

  const submit = async (e: FormEvent) => {
    e.preventDefault(); setBusy(true); setErr(null);
    try {
      const body = { ...v, recipient_phone: normalizePhone(v.recipient_phone), label: v.label || null, unit_number: v.unit_number || null, area: v.area || null, landmark: v.landmark || null, instructions: v.instructions || null };
      const res = initial ? await patch<{ data: Address }>(`/me/addresses/${initial.id}`, body) : await post<{ data: Address }>('/me/addresses', body);
      onSaved(res.data);
    } catch (e2) { setErr(e2 instanceof ApiError ? e2 : null); } finally { setBusy(false); }
  };
  const f = (n: string) => err?.field(n);
  return (
    <form onSubmit={submit} className="space-y-4">
      {err && !Object.keys(err.errors).length && <Alert tone="danger">{err.message}</Alert>}
      <Input label={t('address.label')} value={v.label} onChange={set('label')} placeholder={t('address.label_ph')} maxLength={48} error={f('label')} />
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label={t('address.recipient')} required value={v.recipient_name} onChange={set('recipient_name')} autoComplete="name" error={f('recipient_name')} />
        <Input label={t('address.phone')} required value={v.recipient_phone} onChange={set('recipient_phone')} inputMode="tel" autoComplete="tel" error={f('recipient_phone')} />
      </div>
      <div className="rounded-3xl border border-line bg-paper p-4">
        <p className="mb-3 text-[13px] text-muted">{t('address.national_hint')}</p>
        <div className="grid grid-cols-3 gap-3">
          <Input label={t('address.zone')} required inputMode="numeric" value={v.zone_number} onChange={set('zone_number')} error={f('zone_number')} />
          <Input label={t('address.street')} required inputMode="numeric" value={v.street_number} onChange={set('street_number')} error={f('street_number')} />
          <Input label={t('address.building')} required inputMode="numeric" value={v.building_number} onChange={set('building_number')} error={f('building_number')} />
        </div>
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label={t('address.unit')} value={v.unit_number} onChange={set('unit_number')} error={f('unit_number')} />
        <Input label={t('address.area')} value={v.area} onChange={set('area')} error={f('area')} />
      </div>
      <Input label={t('address.landmark')} value={v.landmark} onChange={set('landmark')} error={f('landmark')} />
      <Textarea label={t('address.instructions')} value={v.instructions} onChange={set('instructions')} className="[&_textarea]:min-h-20" error={f('instructions')} />
      <Checkbox checked={v.is_default} onChange={(c) => setV({ ...v, is_default: c })}>{t('address.make_default')}</Checkbox>
      <div className="flex gap-3 pt-1">
        <Button type="submit" loading={busy}>{t('action.save')}</Button>
        {onCancel && <Button type="button" variant="ghost" onClick={onCancel}>{t('action.cancel')}</Button>}
      </div>
    </form>
  );
}
