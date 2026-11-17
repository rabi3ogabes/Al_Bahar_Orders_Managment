import { PackagePlus } from 'lucide-react';
import { useI18n } from '../lib/i18n';
import { cx, money } from '../lib/format';
import { productExtraFee, productExtraFeeText } from '../lib/deliveryFee';
import type { ProductCard } from '../lib/types';

/**
 * A calm note for products that carry an extra delivery fee (bulky items): "Extra delivery fee QAR 50.00 per item", or "... for this
 * item, whatever the quantity". Shown before the customer adds the item, so the fee at checkout is never a surprise. Nothing when the
 * product has no extra fee (0, missing, or a server that does not send it yet).
 */
export function ExtraDeliveryNote({ p, className }: { p: Pick<ProductCard, 'extra_delivery_fee' | 'extra_delivery_fee_mode' | 'price'>; className?: string }) {
  const { t, locale } = useI18n();
  const x = productExtraFee(p, (v, cur) => money(v, cur, locale));
  if (!x) return null;
  return (
    <p className={cx('flex items-start gap-2.5 rounded-2xl bg-brass-tint/50 px-3.5 py-2.5 text-[13px] leading-snug text-ink-soft', className)} data-testid="extra-delivery-note" data-mode={x.mode}>
      <PackagePlus size={17} strokeWidth={1.6} aria-hidden="true" className="mt-px shrink-0 text-brass-deep" />
      <span>{productExtraFeeText(x, t)}</span>
    </p>
  );
}
