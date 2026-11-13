import { useEffect, useState } from 'react';
import { ApiError, put } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useI18n } from '../lib/i18n';
import { useToast } from '../lib/toast';
import { cx } from '../lib/format';
import { Alert, Button, Checkbox, Input } from './ui';

/** Profile and password forms, shared by the /account/profile page and the profile side window. `embedded` drops the card chrome. */
export function ProfileForm({ embedded, onDirty, onSaved }: { embedded?: boolean; onDirty?: (dirty: boolean) => void; onSaved?: () => void }) {
  const { t } = useI18n();
  const { user, updateProfile } = useAuth();
  const toast = useToast();
  const [name, setName] = useState(user?.name ?? '');
  const [email, setEmail] = useState(user?.email ?? '');
  const [marketing, setMarketing] = useState(user?.marketing_consent ?? false);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<ApiError | null>(null);
  const dirty = !!user && (name !== user.name || email !== (user.email ?? '') || marketing !== user.marketing_consent);
  useEffect(() => { onDirty?.(dirty); }, [dirty, onDirty]);
  if (!user) return null;
  const save = async (e: React.FormEvent) => {
    e.preventDefault(); if (busy) return; setBusy(true); setErr(null);
    try { await updateProfile({ name: name.trim(), email: email.trim() || null, marketing_consent: marketing }); toast.push(t('profile.saved')); onSaved?.(); }
    catch (x) { setErr(x instanceof ApiError ? x : null); } finally { setBusy(false); }
  };
  return (
    <form onSubmit={save} className={cx('space-y-4', !embedded && 'rounded-[28px] border border-line bg-paper p-6')}>
      {!embedded && <h2 className="text-3xl">{t('account.profile')}</h2>}
      {err && !Object.keys(err.errors).length && <Alert tone="danger">{err.message}</Alert>}
      <Input label={t('auth.name')} value={name} onChange={(e) => setName(e.target.value)} required maxLength={120} autoComplete="name" error={err?.field('name')} />
      <Input label={t('auth.phone')} value={user.phone} disabled hint={t('profile.phone_locked')} />
      <Input label={t('auth.email_optional')} type="email" value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="email" error={err?.field('email')} />
      <Checkbox checked={marketing} onChange={setMarketing}>{t('auth.marketing')}</Checkbox>
      <Button type="submit" loading={busy} disabled={!dirty} block={embedded}>{t('action.save')}</Button>
    </form>
  );
}

export function PasswordForm({ embedded }: { embedded?: boolean }) {
  const { t } = useI18n();
  const { user, reload } = useAuth();
  const toast = useToast();
  const [pw, setPw] = useState({ current: '', next: '' });
  const [err, setErr] = useState<ApiError | null>(null);
  if (!user) return null;
  const save = async (e: React.FormEvent) => {
    e.preventDefault(); setErr(null);
    try { await put('/me/password', { current_password: user.has_password ? pw.current : undefined, password: pw.next, password_confirmation: pw.next }); toast.push(t('profile.password_saved')); setPw({ current: '', next: '' }); void reload(); }
    catch (x) { setErr(x instanceof ApiError ? x : null); }
  };
  return (
    <form onSubmit={save} className={cx('space-y-4', !embedded && 'rounded-[28px] border border-line bg-paper p-6')}>
      <h2 className="text-2xl">{user.has_password ? t('profile.change_password') : t('profile.set_password')}</h2>
      {err && !Object.keys(err.errors).length && <Alert tone="danger">{err.message}</Alert>}
      {user.has_password && <Input label={t('profile.current_password')} type="password" autoComplete="current-password" value={pw.current} onChange={(e) => setPw({ ...pw, current: e.target.value })} error={err?.field('current_password')} />}
      <Input label={t('auth.new_password')} type="password" autoComplete="new-password" value={pw.next} onChange={(e) => setPw({ ...pw, next: e.target.value })} hint={t('auth.password_rule')} error={err?.field('password')} />
      <Button type="submit" variant="outline" disabled={pw.next.length < 8} block={embedded}>{t('action.save')}</Button>
    </form>
  );
}
