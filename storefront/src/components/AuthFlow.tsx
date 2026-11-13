import { useEffect, useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, ArrowRight, KeyRound, MessageSquareText } from 'lucide-react';
import { useAuth } from '../lib/auth';
import { ApiError, get, post } from '../lib/api';
import { latinDigits } from '../lib/format';
import { localPhone, normalizePhone, storedReferral } from '../lib/authHelpers';
import { useI18n } from '../lib/i18n';
import { Alert, Button, Checkbox, Input } from './ui';
import { Modal } from './Dialogs';

type Step = 'phone' | 'code' | 'register' | 'password' | 'reset';

export default function AuthFlow({ reason, onClose }: { reason?: string; onClose: () => void }) {
  const { t, dir } = useI18n();
  const auth = useAuth();
  const [step, setStep] = useState<Step>('phone');
  const [phone, setPhone] = useState('');
  const [code, setCode] = useState('');
  const [regToken, setRegToken] = useState('');
  const [identifier, setIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [newPass, setNewPass] = useState('');
  const [consent, setConsent] = useState(false);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<ApiError | null>(null);
  const [info, setInfo] = useState('');
  const [wait, setWait] = useState(0);
  const [resetting, setResetting] = useState(false);
  const [referral, setReferral] = useState(() => storedReferral());
  const refOn = useQuery({ queryKey: ['referral-status'], staleTime: 5 * 60_000, queryFn: async () => (await get<{ data: { enabled: boolean } }>('/referrals/status')).data.enabled });
  const Back = dir === 'rtl' ? ArrowRight : ArrowLeft;

  useEffect(() => { if (wait <= 0) return; const id = setTimeout(() => setWait(wait - 1), 1000); return () => clearTimeout(id); }, [wait]);

  const run = async (fn: () => Promise<void>) => { if (busy) return; setBusy(true); setErr(null); setInfo(''); try { await fn(); } catch (e) { setErr(e instanceof ApiError ? e : new ApiError(0, { message: t('error.generic') })); } finally { setBusy(false); } };

  const sendCode = (purpose: 'login' | 'password_reset' = 'login') => run(async () => {
    const r = await auth.requestOtp(normalizePhone(phone), purpose);
    setWait(r.resend_in ?? 60);
    if (r.debug_code) setInfo(`${t('auth.dev_code')}: ${r.debug_code}`);
    setCode(''); setStep(purpose === 'login' ? 'code' : 'reset');
  });
  const verify = (e: FormEvent) => { e.preventDefault(); void run(async () => {
    const r = await auth.verifyOtp(normalizePhone(phone), code);
    if (r.status === 'registration_required') { setRegToken(r.registration_token!); setStep('register'); }
  }); };
  const register = (e: FormEvent) => { e.preventDefault(); void run(() => auth.register({ registration_token: regToken, name: name.trim(), email: email.trim() || undefined, password: newPass || undefined, password_confirmation: newPass || undefined, marketing_consent: consent, referral_code: refOn.data && referral.trim() ? referral.trim().toUpperCase() : undefined })); };
  const login = (e: FormEvent) => { e.preventDefault(); void run(() => auth.passwordLogin(identifier, password)); };
  const reset = (e: FormEvent) => { e.preventDefault(); void run(async () => {
    await post('/auth/password/reset', { phone: normalizePhone(phone), code, password: newPass, password_confirmation: newPass });
    setInfo(t('auth.password_updated')); setPassword(''); setIdentifier(phone); setStep('password');
  }); };

  const titles: Record<Step, string> = { phone: resetting ? t('auth.reset_title') : t('auth.title'), code: t('auth.code_title'), register: t('auth.register_title'), password: t('auth.password_title'), reset: t('auth.reset_title') };
  const f = (n: string) => err?.field(n);

  return (
    <Modal open onClose={onClose} title={titles[step]}>
      {reason && step === 'phone' && <p className="-mt-2 mb-5 text-sm text-muted">{reason}</p>}
      {info && <div className="mb-4"><Alert tone="success">{info}</Alert></div>}
      {err && !Object.keys(err.errors).length && <div className="mb-4"><Alert tone="danger">{err.message}</Alert></div>}

      {step === 'phone' && (
        <form onSubmit={(e) => { e.preventDefault(); if (!busy) void sendCode(resetting ? 'password_reset' : 'login'); }} className="space-y-5">
          <Input label={t('auth.phone')} type="tel" inputMode="tel" autoComplete="tel" data-autofocus="" required maxLength={20} value={phone} onChange={(e) => setPhone(e.target.value)} prefix="+974" placeholder="5522 0011" error={f('phone')} hint={t('auth.phone_hint')} />
          <Button type="submit" size="lg" block loading={busy} disabled={latinDigits(phone).replace(/\D/g, '').length < 8}><MessageSquareText size={18} />{t('auth.send_code')}</Button>
          <button type="button" onClick={() => { setErr(null); setResetting(false); setStep('password'); }} className="flex w-full items-center justify-center gap-2 text-sm font-medium text-ink-soft hover:text-ink"><KeyRound size={15} />{t('auth.use_password')}</button>
        </form>
      )}

      {step === 'code' && (
        <form onSubmit={verify} className="space-y-5">
          <p className="text-sm text-muted">{t('auth.code_sent', { phone: normalizePhone(phone) })}</p>
          <Input label={t('auth.code')} inputMode="numeric" autoComplete="one-time-code" autoFocus required maxLength={8} value={code} onChange={(e) => setCode(latinDigits(e.target.value).replace(/\D/g, ''))} className="[&_input]:text-center [&_input]:text-2xl [&_input]:tracking-[0.5em]" error={f('code')} />
          <Button type="submit" size="lg" block loading={busy} disabled={code.length < 4}>{t('auth.verify')}</Button>
          <div className="flex items-center justify-between text-sm">
            <button type="button" onClick={() => setStep('phone')} className="flex items-center gap-1 text-ink-soft hover:text-ink"><Back size={14} />{t('auth.change_phone')}</button>
            <button type="button" disabled={wait > 0 || busy} onClick={() => sendCode()} className="font-medium text-ink-soft hover:text-ink disabled:opacity-50">{wait > 0 ? t('auth.resend_in', { s: wait }) : t('auth.resend')}</button>
          </div>
        </form>
      )}

      {step === 'register' && (
        <form onSubmit={register} className="space-y-4">
          <p className="text-sm text-muted">{t('auth.welcome_new')}</p>
          <Input label={t('auth.name')} autoComplete="name" autoFocus required maxLength={120} value={name} onChange={(e) => setName(e.target.value)} error={f('name')} />
          <Input label={t('auth.email_optional')} type="email" autoComplete="email" maxLength={190} value={email} onChange={(e) => setEmail(e.target.value)} error={f('email')} />
          <Input label={t('auth.password_optional')} type="password" autoComplete="new-password" maxLength={128} value={newPass} onChange={(e) => setNewPass(e.target.value)} hint={t('auth.password_rule')} error={f('password')} />
          {refOn.data && <Input label={t('auth.invite_code')} autoComplete="off" autoCapitalize="characters" maxLength={16} value={referral} onChange={(e) => setReferral(e.target.value.replace(/[^A-Za-z0-9-]/g, ''))} error={f('referral_code')} />}
          <Checkbox checked={consent} onChange={setConsent}>{t('auth.marketing')}</Checkbox>
          <Button type="submit" size="lg" block loading={busy} disabled={name.trim().length < 2}>{t('auth.create_account')}</Button>
        </form>
      )}

      {step === 'password' && (
        <form onSubmit={login} className="space-y-5">
          <Input label={t('auth.phone_or_email')} autoComplete="username" autoFocus required value={identifier} onChange={(e) => setIdentifier(e.target.value)} error={f('phone') || f('email')} />
          <Input label={t('auth.password')} type="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} error={f('password')} />
          <Button type="submit" size="lg" block loading={busy}>{t('auth.sign_in')}</Button>
          <div className="flex items-center justify-between text-sm">
            <button type="button" onClick={() => { setErr(null); setResetting(false); setStep('phone'); }} className="flex items-center gap-1 text-ink-soft hover:text-ink"><Back size={14} />{t('auth.use_code')}</button>
            <button type="button" onClick={() => { setErr(null); setResetting(true); setPhone(identifier.includes('@') ? '' : localPhone(identifier)); setStep('phone'); setInfo(t('auth.reset_hint')); }} className="text-ink-soft hover:text-ink">{t('auth.forgot')}</button>
          </div>
        </form>
      )}

      {step === 'reset' && (
        <form onSubmit={reset} className="space-y-4">
          <p className="text-sm text-muted">{t('auth.code_sent', { phone: normalizePhone(phone) })}</p>
          <Input label={t('auth.code')} inputMode="numeric" autoComplete="one-time-code" required maxLength={8} value={code} onChange={(e) => setCode(latinDigits(e.target.value).replace(/\D/g, ''))} error={f('code')} />
          <Input label={t('auth.new_password')} type="password" autoComplete="new-password" required value={newPass} onChange={(e) => setNewPass(e.target.value)} hint={t('auth.password_rule')} error={f('password')} />
          <Button type="submit" size="lg" block loading={busy} disabled={code.length < 4 || newPass.length < 8}>{t('auth.save_password')}</Button>
          <div className="flex items-center justify-between text-sm">
            <button type="button" onClick={() => setStep('phone')} className="flex items-center gap-1 text-ink-soft hover:text-ink"><Back size={14} />{t('auth.change_phone')}</button>
            <button type="button" disabled={wait > 0 || busy} onClick={() => sendCode('password_reset')} className="font-medium text-ink-soft hover:text-ink disabled:opacity-50">{wait > 0 ? t('auth.resend_in', { s: wait }) : t('auth.resend')}</button>
          </div>
        </form>
      )}
      <p className="mt-6 text-center text-xs text-muted">{t('auth.legal')}</p>
    </Modal>
  );
}
