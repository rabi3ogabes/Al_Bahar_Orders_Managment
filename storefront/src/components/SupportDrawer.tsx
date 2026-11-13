import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { ApiError, post } from '../lib/api';
import { useI18n } from '../lib/i18n';
import { useToast } from '../lib/toast';
import { Alert, Button, Input, Select, Textarea } from './ui';
import { SideDrawer } from './SideDrawer';

/** New support request in a side window; the ticket list behind it refreshes when it is sent. */
export default function SupportDrawer({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useI18n();
  const qc = useQueryClient();
  const toast = useToast();
  const [subject, setSubject] = useState('');
  const [message, setMessage] = useState('');
  const [category, setCategory] = useState('general');
  const [err, setErr] = useState<ApiError | null>(null);
  const dirty = subject.trim() !== '' || message.trim() !== '';
  const create = useMutation({
    mutationFn: () => post('/me/support/tickets', { subject: subject.trim(), message: message.trim(), category }),
    onSuccess: () => { toast.push(t('support.sent')); setSubject(''); setMessage(''); void qc.invalidateQueries({ queryKey: ['tickets'] }); onClose(); },
    onError: (e) => setErr(e instanceof ApiError ? e : null),
  });
  return (
    <SideDrawer open={open} onClose={onClose} dirty={dirty && !create.isPending} title={t('support.new')}
      footer={<Button type="submit" form="support-form" size="lg" block loading={create.isPending} disabled={!subject.trim() || !message.trim()}>{t('support.send')}</Button>}>
      <form id="support-form" onSubmit={(e) => { e.preventDefault(); setErr(null); if (!create.isPending) create.mutate(); }} className="space-y-4 p-6">
        {err && !Object.keys(err.errors).length && <Alert tone="danger">{err.message}</Alert>}
        <Select label={t('support.category')} value={category} onChange={(e) => setCategory(e.target.value)}>{['general', 'order', 'payment', 'delivery', 'product', 'returns'].map((c) => <option key={c} value={c}>{t(`support.cat.${c}`)}</option>)}</Select>
        <Input label={t('support.subject')} required value={subject} onChange={(e) => setSubject(e.target.value)} maxLength={160} error={err?.field('subject')} />
        <Textarea label={t('support.message')} required value={message} onChange={(e) => setMessage(e.target.value)} maxLength={4000} error={err?.field('message')} className="[&_textarea]:min-h-40" />
      </form>
    </SideDrawer>
  );
}
