<?php

namespace App\Livewire\Admin\Insights;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Partners\Services\PartnerAccess;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use Livewire\Component;

/** Partners: switch the shared statistics page on or off, copy its link, set the password, see who opened it. */
class PartnerAccessPage extends Component
{
    use InteractsWithAdmin;

    public bool $enabled = false;

    public string $password = '';

    public string $password_confirmation = '';

    public bool $confirmRotate = false;

    public function mount(PartnerAccess $access): void
    {
        abort_unless($this->canGlobally('settings.view'), 403);
        $this->enabled = $access->enabled();
    }

    /** A toggle acts at once (no Save button): flipping it on needs a password first. */
    public function updatedEnabled(bool $on, PartnerAccess $access): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        if ($on && ! $access->hasPassword()) {
            $this->enabled = false;
            $this->toast(__('partner_admin.need_password'), 'error');

            return;
        }
        $access->setEnabled($on);
        $this->toast($on ? __('partner_admin.on') : __('partner_admin.off'));
    }

    public function savePassword(PartnerAccess $access): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        $this->validate(['password' => ['required', 'string', 'min:10', 'max:100', 'confirmed']], [], ['password' => __('partner_admin.password')]);
        $access->setPassword($this->password);
        app(AuditLogger::class)->log('partners.password_changed', null, [], [], []);
        $this->reset('password', 'password_confirmation');
        $this->toast(__('partner_admin.password_saved'));
    }

    public function rotate(PartnerAccess $access): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        $access->rotateLink();
        app(AuditLogger::class)->log('partners.link_rotated', null, [], [], []);
        $this->confirmRotate = false;
        $this->toast(__('partner_admin.rotated'));
    }

    public function render(PartnerAccess $access)
    {
        return view('livewire.admin.insights.partner-access', [
            'url' => $access->url(), 'hasPassword' => $access->hasPassword(), 'recent' => $access->recent(),
            'canEdit' => $this->canGlobally('settings.edit'),
        ])->title(__('partner_admin.title'));
    }
}
