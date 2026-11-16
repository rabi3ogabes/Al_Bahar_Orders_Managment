import { useI18n } from '../lib/i18n';
import type { DeliveryFeeLine } from '../lib/deliveryFee';

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
