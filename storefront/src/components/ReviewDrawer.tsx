import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { ApiError, post } from '../lib/api';
import { useI18n } from '../lib/i18n';
import { useToast } from '../lib/toast';
import { cx } from '../lib/format';
import { Alert, Button, Input, Textarea } from './ui';
import { SideDrawer } from './SideDrawer';

/** Write a review in a side window: the product page keeps its scroll position and the new review appears behind it. */
export default function ReviewDrawer({ open, onClose, productId, productName }: { open: boolean; onClose: () => void; productId: string; productName: string }) {
  const { t } = useI18n();
  const toast = useToast();
  const qc = useQueryClient();
  const [rating, setRating] = useState(5);
  const [title, setTitle] = useState('');
  const [body, setBody] = useState('');
  const [err, setErr] = useState('');
  const dirty = title.trim() !== '' || body.trim() !== '' || rating !== 5;
  const submit = useMutation({
    mutationFn: () => post(`/products/${productId}/reviews`, { rating, title: title.trim() || undefined, body: body.trim() || undefined }),
    onSuccess: () => { toast.push(t('reviews.thanks')); setTitle(''); setBody(''); setRating(5); void qc.invalidateQueries({ queryKey: ['reviews', productId] }); onClose(); },
    onError: (e) => setErr(e instanceof ApiError ? e.message : t('error.generic')),
  });
  return (
    <SideDrawer open={open} onClose={onClose} dirty={dirty && !submit.isPending} title={t('reviews.write')} description={productName}
      footer={<Button type="submit" form="review-form" size="lg" block loading={submit.isPending}>{t('reviews.submit')}</Button>}>
      <form id="review-form" onSubmit={(e) => { e.preventDefault(); setErr(''); if (!submit.isPending) submit.mutate(); }} className="space-y-5 p-6">
        <p className="font-display text-xl leading-snug">{productName}</p>
        {err && <Alert tone="danger">{err}</Alert>}
        <div>
          <p id="rate-l" className="mb-1.5 text-[13px] font-medium text-ink-soft">{t('reviews.rating')}</p>
          <div role="radiogroup" aria-labelledby="rate-l" className="flex gap-0.5">
            {[1, 2, 3, 4, 5].map((n) => (
              <button key={n} type="button" role="radio" aria-checked={rating === n} aria-label={`${n} / 5`} onClick={() => setRating(n)} className="flex h-12 w-12 items-center justify-center rounded-full hover:bg-sand">
                <svg width="30" height="30" viewBox="0 0 24 24" aria-hidden className={cx(n <= rating ? 'fill-brass text-brass' : 'fill-transparent text-line')} stroke="currentColor" strokeWidth="1.5"><path d="m12 2 3 6.9 7.5.7-5.7 5 1.7 7.4L12 18l-6.5 4 1.7-7.4-5.7-5L9 8.9z" /></svg>
              </button>
            ))}
          </div>
        </div>
        <Input label={t('reviews.title')} value={title} onChange={(e) => setTitle(e.target.value)} maxLength={120} />
        <Textarea label={t('reviews.body')} value={body} onChange={(e) => setBody(e.target.value)} maxLength={3000} className="[&_textarea]:min-h-36" />
      </form>
    </SideDrawer>
  );
}
