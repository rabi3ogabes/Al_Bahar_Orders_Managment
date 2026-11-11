import { createContext, useCallback, useContext, useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { ArrowLeft, ArrowRight, KeyRound, MessageSquareText } from 'lucide-react';
import { useAuth } from '../lib/auth';
import { ApiError, post } from '../lib/api';
import { useI18n } from '../lib/i18n';
import { Alert, Button, Checkbox, Input, Modal } from './ui';

interface Ctx { open: (opts?: { reason?: string; onDone?: () => void }) => void; close: () => void }
const C = createContext<Ctx>({ open: () => {}, close: () => {} });
export const useAuthModal = () => useContext(C);

type Step = 'phone' | 'code' | 'register' | 'password' | 'reset';

/** Qatar numbers: accept "55220011", "+974 5522 0011" or "97455220011". */
export function normalizePhone(raw: string): string {
  const d = raw.replace(/[^\d+]/g, '');
  if (d.startsWith('+')) return d;
  if (d.startsWith('974') && d.length > 8) return `+${d}`;
  if (d.startsWith('00')) return `+${d.slice(2)}`;
  return `+974${d}`;
}

export function AuthModalProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<{ reason?: string; onDone?: () => void } | null>(null);
  const { user } = useAuth();
  const done = useRef<(() => void) | undefined>();
  const open = useCallback((o?: { reason?: string; onDone?: () => void }) => { done.current = o?.onDone; setState(o ?? {}); }, []);
  const close = useCallback(() => setState(null), []);
  // Signed in → run the action that needed an account, then get out of the way.
  useEffect(() => { if (user && state) { const fn = done.current; done.current = undefined; setState(null); fn?.(); } }, [user, state]);
  return (
    <C.Provider value={{ open, close }}>
      {children}
      {state && <AuthFlow reason={state.reason} onClose={close} />}
    </C.Provider>
  );
}

function AuthFlow({ reason, onClose }: { reason?: string; onClose: () => void }) {
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
  const Back = dir === 'rtl' ? ArrowRight : ArrowLeft;

  useEffect(() => { if (wait <= 0) return; const id = setTimeout(() => setWait(wait - 1), 1000); return () => clearTimeout(id); }, [wait]);

  const run = async (fn: () => Promise<void>) => { setBusy(true); setErr(null); setInfo(''); try { await fn(); } catch (e) { setErr(e instanceof ApiError ? e : new ApiError(0, { message: t('error.generic') })); } finally { setBusy(false); } };

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
  const register = (e: FormEvent) => { e.preventDefault(); void run(() => auth.register({ registration_token: regToken, name, email: email || undefined, password: newPass || undefined, password_confirmation: newPass || undefined, marketing_consent: consent })); };
  const login = (e: FormEvent) => { e.preventDefault(); void run(() => auth.passwordLogin(identifier, password)); };
  const reset = (e: FormEvent) => { e.preventDefault(); void run(async () => {
    await post('/auth/password/reset', { phone: normalizePhone(phone), code, password: newPass, password_confirmation: newPass });
    setInfo(t('auth.password_updated')); setPassword(''); setIdentifier(phone); setStep('password');
  }); };

  const titles: Record<Step, string> = { phone: t('auth.title'), code: t('auth.code_title'), register: t('auth.register_title'), password: t('auth.password_title'), reset: t('auth.reset_title') };
  const f = (n: string) => err?.field(n);

  return (
    <Modal open onClose={onClose} title={titles[step]}>
      {reason && step === 'phone' && <p className="-mt-2 mb-5 text-sm text-muted">{reason}</p>}
      {info && <div className="mb-4"><Alert tone="success">{info}</Alert></div>}
      {err && !Object.keys(err.errors).length && <div className="mb-4"><Alert tone="danger">{err.message}</Alert></div>}

      {step === 'phone' && (
        <form onSubmit={(e) => { e.preventDefault(); void sendCode(); }} className="space-y-5">
          <Input label={t('auth.phone')} inputMode="tel" autoComplete="tel" autoFocus required value={phone} onChange={(e) => setPhone(e.target.value)} prefix="+974" placeholder="5522 0011" error={f('phone')} hint={t('auth.phone_hint')} />
          <Button type="submit" size="lg" block loading={busy} disabled={phone.replace(/\D/g, '').length < 8}><MessageSquareText size={18} />{t('auth.send_code')}</Button>
          <button type="button" onClick={() => { setErr(null); setStep('password'); }} className="flex w-full items-center justify-center gap-2 text-sm font-medium text-ink-soft hover:text-ink"><KeyRound size={15} />{t('auth.use_password')}</button>
        </form>
      )}

      {step === 'code' && (
        <form onSubmit={verify} className="space-y-5">
          <p className="text-sm text-muted">{t('auth.code_sent', { phone: normalizePhone(phone) })}</p>
          <Input label={t('auth.code')} inputMode="numeric" autoComplete="one-time-code" autoFocus required maxLength={8} value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} className="[&_input]:text-center [&_input]:text-2xl [&_input]:tracking-[0.5em]" error={f('code')} />
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
          <Input label={t('auth.name')} autoComplete="name" autoFocus required value={name} onChange={(e) => setName(e.target.value)} error={f('name')} />
          <Input label={t('auth.email_optional')} type="email" autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} error={f('email')} />
          <Input label={t('auth.password_optional')} type="password" autoComplete="new-password" value={newPass} onChange={(e) => setNewPass(e.target.value)} hint={t('auth.password_rule')} error={f('password')} />
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
            <button type="button" onClick={() => { setErr(null); setStep('phone'); }} className="flex items-center gap-1 text-ink-soft hover:text-ink"><Back size={14} />{t('auth.use_code')}</button>
            <button type="button" onClick={() => { setErr(null); setPhone(identifier.includes('@') ? '' : identifier); setStep('phone'); setInfo(t('auth.reset_hint')); }} className="text-ink-soft hover:text-ink">{t('auth.forgot')}</button>
          </div>
        </form>
      )}

      {step === 'reset' && (
        <form onSubmit={reset} className="space-y-4">
          <p className="text-sm text-muted">{t('auth.code_sent', { phone: normalizePhone(phone) })}</p>
          <Input label={t('auth.code')} inputMode="numeric" autoComplete="one-time-code" required maxLength={8} value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} error={f('code')} />
          <Input label={t('auth.new_password')} type="password" autoComplete="new-password" required value={newPass} onChange={(e) => setNewPass(e.target.value)} hint={t('auth.password_rule')} error={f('password')} />
          <Button type="submit" size="lg" block loading={busy}>{t('auth.save_password')}</Button>
        </form>
      )}
      <p className="mt-6 text-center text-xs text-muted">{t('auth.legal')}</p>
    </Modal>
  );
}
