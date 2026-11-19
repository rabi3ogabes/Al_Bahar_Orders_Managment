import { MessageCircle, Phone, Store } from 'lucide-react';
import { useI18n } from '../lib/i18n';
import { telHref } from '../lib/format';
import { basketShareText, whatsappShareHref, type ShareLine } from '../lib/shareBasket';
import { Button } from './ui';

const secondary = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-full border border-line bg-paper px-6 text-sm font-medium text-ink transition-colors hover:border-primary';

/**
 * Shown when the quote leaves the customer no way to pay (online payment is off and cash on delivery does not take a basket this size).
 * Never a dead end: collect at the showroom and pay there (when one allows it), call the showroom (its own number, as the API gives it),
 * or send the basket to someone in the family on WhatsApp.
 */
export function NoPaymentPanel({ pickup, phone, lines, total }: {
  /** A showroom where the customer can collect and pay on the spot; `onChoose` switches the order to it. */
  pickup: { name: string; onChoose: () => void } | null
  phone: string | null
  lines: ShareLine[]
  total: string | null
}) {
  const { t } = useI18n();
  const share = whatsappShareHref(basketShareText(lines, { intro: t('pay.none.share_intro'), total: t('pay.none.share_total', { total: total ?? '' }) }, window.location.origin));
  return (
    <div className="mt-5 rounded-2xl border border-line bg-brass-tint/50 p-5" data-testid="no-payment" role="status">
      <p className="font-semibold">{t('pay.none.title')}</p>
      <p className="mt-1.5 text-sm text-ink-soft">{t('pay.none.text')}</p>
      <div className="mt-4 flex flex-col gap-2.5 sm:flex-row sm:flex-wrap">
        {pickup && <Button type="button" variant="brass" onClick={pickup.onChoose}><Store size={16} />{t('pay.none.pickup', { b: pickup.name })}</Button>}
        {phone && <a href={telHref(phone)} className={secondary}><Phone size={16} aria-hidden="true" />{t('pay.none.call')}<bdi className="tabular-nums text-ink-soft">{phone}</bdi></a>}
        {lines.length > 0 && <a href={share} target="_blank" rel="noopener noreferrer" className={secondary}><MessageCircle size={16} aria-hidden="true" />{t('pay.none.share')}</a>}
      </div>
      <p className="mt-3 text-[13px] text-muted">{t('pay.none.saved')}</p>
    </div>
  );
}
