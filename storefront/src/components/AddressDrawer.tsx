import { useState } from 'react';
import { useI18n } from '../lib/i18n';
import type { Address } from '../lib/types';
import { AddressForm } from './AddressForm';
import { SideDrawer } from './SideDrawer';

/** Add or edit a delivery address in a side window, so the list (or the checkout) stays where it was. */
export default function AddressDrawer({ open, initial, onClose, onSaved }: { open: boolean; initial?: Address; onClose: () => void; onSaved: (a: Address) => void }) {
  const { t } = useI18n();
  const [dirty, setDirty] = useState(false);
  return (
    <SideDrawer open={open} onClose={onClose} dirty={dirty} title={initial ? t('address.edit') : t('address.add')} description={t('address.national_hint')} size="lg">
      <div className="p-6"><AddressForm key={initial?.id ?? 'new'} pinned initial={initial} onDirty={setDirty} onSaved={(a) => { setDirty(false); onSaved(a); }} /></div>
    </SideDrawer>
  );
}
