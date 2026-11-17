import { useId, useState } from 'react';
import { ChevronDown } from 'lucide-react';
import { useI18n } from '../lib/i18n';
import { cx } from '../lib/format';
import { extraItemCaption, type DeliveryFeeLine, type ExtraDelivery } from '../lib/deliveryFee';

/**
 * The delivery fee line of an order summary (a row of a <dl>). It is always there: before there is a quote it says the fee comes
 * at checkout; a free-delivery coupon shows the fee struck through with "Free" and the code (lib/deliveryFee.ts).
 */
export function DeliveryFeeRow({ line }: { line?: DeliveryFeeLine | null }) {
  const { t } = useI18n();
  const row = (label: string, value: React.ReactNode, muted?: boolean) => (
    <div className="flex justify-between gap-4" data-testid="delivery-fee-row" data-kind={line?.kind ?? 'pending'}>
      <dt className="text-muted">{label}</dt>
      <dd className={muted ? 'text-muted' : 'text-end tabular-nums'}>{value}</dd>
    </div>
  );
  if (!line || line.kind === 'pending' || !line.fee) return row(t('checkout.delivery_fee'), t('cart.at_checkout'), true);
  if (line.kind === 'fee') return row(t('checkout.delivery_fee'), <span className="font-medium">{line.fee.formatted}</span>);
  if (line.kind === 'zero') return row(line.pickup ? t('checkout.pickup') : t('checkout.delivery_fee'), <span className="font-medium">{t('checkout.free')}</span>);
  return row(t('checkout.delivery_fee'), (
    <>
      <span className="flex items-baseline justify-end gap-2">
        <span className="sr-only">{t('checkout.fee_was')}</span>
        <s className="text-muted"><bdi>{line.fee.formatted}</bdi></s>
        <span className="font-semibold text-success">{t('checkout.free')}</span>
      </span>
      {line.code && <span className="block text-xs text-muted">{t('checkout.fee_code')}: <bdi className="font-medium text-ink-soft">{line.code}</bdi></span>}
    </>
  ));
}

/**
 * "Includes extra delivery fee for bulky items" under the delivery line (a row of a <dl>, lib/deliveryFee.ts `extraDelivery`).
 * The delivery line above keeps the gross total, so this reads as a part of it, never as one more charge. With the product detail
 * the row opens a short list (product, "×3 · QAR 50.00 each" or "charged once", amount). A free-delivery coupon strikes it through
 * with the rest of the fee.
 */
export function ExtraDeliveryRow({ extra }: { extra?: ExtraDelivery | null }) {
  const { t } = useI18n();
  const [open, setOpen] = useState(false);
  const listId = useId();
  if (!extra) return null;
  const canOpen = extra.items.length > 0;
  const label = t('checkout.extra_delivery');
  const was = extra.waived ? <span className="sr-only">{t('checkout.fee_was')} </span> : null;
  const strike = (v: string) => <>{was}<bdi className={cx('tabular-nums', extra.waived && 'line-through')}>{v}</bdi></>;
  return (
    <div className="-mt-1 flex flex-wrap items-baseline justify-between gap-x-4 border-s-2 border-brass/40 ps-3 text-[13px] leading-snug" data-testid="extra-delivery-row" data-waived={extra.waived ? 'true' : 'false'}>
      <dt className="min-w-0 flex-1 text-muted">
        {canOpen ? (
          <button type="button" aria-expanded={open} aria-controls={listId} onClick={() => setOpen((v) => !v)} className="-my-3.5 inline-flex min-h-11 items-center gap-1.5 rounded-lg text-start transition-colors hover:text-ink">
            <span>{label}</span><ChevronDown size={14} aria-hidden="true" className={cx('shrink-0 transition-transform duration-200', open && 'rotate-180')} />
          </button>
        ) : label}
      </dt>
      <dd className="shrink-0 whitespace-nowrap text-muted">{strike(extra.amount.formatted)}</dd>
      {canOpen && (
        <dd id={listId} hidden={!open} className="basis-full pt-2.5" data-testid="extra-delivery-list">
          <ul className="space-y-2.5 pb-0.5">
            {extra.items.map((i, k) => (
              <li key={`${i.productId ?? i.name}-${k}`} className="flex items-baseline justify-between gap-4">
                <span className="min-w-0"><span className="line-clamp-2 text-ink-soft">{i.name}</span><span className="block text-xs text-muted">{extraItemCaption(i, t)}</span></span>
                <span className="shrink-0 whitespace-nowrap text-ink-soft">{strike(i.amount.formatted)}</span>
              </li>
            ))}
          </ul>
        </dd>
      )}
    </div>
  );
}
