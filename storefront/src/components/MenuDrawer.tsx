import type { ReactNode } from 'react';
import { useI18n } from '../lib/i18n';
import { SideDrawer } from './SideDrawer';

/** The phone / tablet navigation menu: a side window from the start edge on every screen size. */
export default function MenuDrawer({ open, onClose, children }: { open: boolean; onClose: () => void; children: ReactNode }) {
  const { t } = useI18n();
  return <SideDrawer open={open} onClose={onClose} side="start" phone="side" size="sm" title={t('nav.menu')}>{children}</SideDrawer>;
}
