import { useState } from 'react';
import { useI18n } from '../lib/i18n';
import { PasswordForm, ProfileForm } from './ProfileForms';
import { SideDrawer } from './SideDrawer';

/** Edit profile and password without leaving the account page. */
export default function ProfileDrawer({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useI18n();
  const [dirty, setDirty] = useState(false);
  return (
    <SideDrawer open={open} onClose={onClose} title={t('account.profile')} dirty={dirty}>
      <div className="space-y-8 p-6">
        <ProfileForm embedded onDirty={setDirty} onSaved={onClose} />
        <div className="border-t border-line pt-6"><PasswordForm embedded /></div>
      </div>
    </SideDrawer>
  );
}
