<?php

namespace App\Livewire\Admin\Insights;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Partners\Models\Partner;
use App\Domain\Partners\Services\PartnerAccess;
use App\Domain\Partners\Services\PartnerLinks;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\BusinessTime;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Partners: one revocable link and password per partner (branch-limited, no personal data, optional expiry), who opened what, and the
 * OLD shared link (still on until the owner turns it off). The password of a new or regenerated link is shown once, in a window.
 */
class PartnerAccessPage extends Component
{
    use InteractsWithAdmin;

    // The old shared link.
    public bool $enabled = false;

    public string $password = '';

    public string $password_confirmation = '';

    public bool $confirmRotate = false;

    // A partner (the side window).
    public bool $showForm = false;

    #[Locked]
    public ?string $editing = null;

    public string $name = '';

    public string $contact = '';

    public bool $allBranches = false;

    /** @var list<string> */
    public array $branchIds = [];

    public string $expiresOn = '';

    /** Regenerate or revoke: asked first. */
    public bool $showConfirm = false;

    public string $confirmAction = '';

    #[Locked]
    public ?string $confirmId = null;

    /** The link and the one-time password, shown once. @var array{name: string, url: string, password: string}|null */
    public ?array $reveal = null;

    public function mount(PartnerAccess $access): void
    {
        abort_unless($this->canGlobally('settings.view'), 403);
        $this->enabled = $access->enabled();
    }

    public function openCreate(): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        $this->reset('editing', 'name', 'contact', 'allBranches', 'branchIds', 'expiresOn');
        $this->resetValidation();
        $this->showForm = true;
    }

    public function openEdit(string $id): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        $p = Partner::with('branches:id')->findOrFail($id);
        $this->editing = $p->id;
        $this->name = $p->name;
        $this->contact = (string) $p->contact;
        $this->allBranches = $p->all_branches;
        $this->branchIds = $p->branches->pluck('id')->all();
        $this->expiresOn = $p->expires_at ? BusinessTime::local($p->expires_at)?->toDateString() ?? '' : '';
        $this->resetValidation();
        $this->showForm = true;
    }

    public function savePartner(PartnerLinks $links): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        $data = $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'contact' => ['nullable', 'string', 'max:191'],
            'allBranches' => ['boolean'],
            'branchIds' => [$this->allBranches ? 'array' : 'required', 'array', 'max:200'],
            'branchIds.*' => ['uuid', 'exists:branches,id'],
            'expiresOn' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ], [], ['name' => __('partner_admin.partner_name'), 'branchIds' => __('partner_admin.branches'), 'expiresOn' => __('partner_admin.expires')]);
        $expires = $data['expiresOn'] ? BusinessTime::dateRange($data['expiresOn'], $data['expiresOn'])[1] : null;
        $contact = trim($data['contact'] ?? '') ?: null;

        if ($this->editing) {
            $links->update(Partner::findOrFail($this->editing), trim($data['name']), $contact, $data['branchIds'], $data['allBranches'], $expires, auth()->user());
            $this->toast(__('partner_admin.partner_saved'));
        } else {
            $made = $links->create(trim($data['name']), $contact, $data['branchIds'], $data['allBranches'], $expires, auth()->user());
            $this->reveal = ['name' => $made['partner']->name, 'url' => $links->url($made['partner']), 'password' => $made['password']];
        }
        $this->showForm = false;
    }

    public function ask(string $action, string $id): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        abort_unless(in_array($action, ['regenerate', 'revoke'], true), 422);
        Partner::findOrFail($id);
        $this->confirmAction = $action;
        $this->confirmId = $id;
        $this->showConfirm = true;
    }

    public function runConfirm(PartnerLinks $links): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        $p = Partner::findOrFail((string) $this->confirmId);
        if ($this->confirmAction === 'revoke') {
            $links->revoke($p, auth()->user());
            $this->toast(__('partner_admin.revoked_done'));
        } else {
            $password = $links->regenerate($p, auth()->user());
            $this->reveal = ['name' => $p->name, 'url' => $links->url($p->fresh() ?? $p), 'password' => $password];
        }
        $this->showConfirm = false;
    }

    public function closeReveal(): void
    {
        $this->reveal = null;
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
        app(AuditLogger::class)->log($on ? 'partners.shared_link_on' : 'partners.shared_link_off', null, [], [], []);
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

    public function render(PartnerAccess $access, PartnerLinks $links)
    {
        $partners = Partner::with('branches:id,name_en,name_ar')->orderBy('name')->orderBy('id')->limit(200)->get();

        return view('livewire.admin.insights.partner-access', [
            'url' => $access->url(), 'hasPassword' => $access->hasPassword(), 'recent' => $access->recent(),
            'canEdit' => $this->canGlobally('settings.edit'),
            'partners' => $partners,
            'links' => $links,
            'branches' => Branch::active()->orderBy('sort_order')->orderBy('id')->get(['id', 'name_en', 'name_ar']),
        ])->title(__('partner_admin.title'));
    }
}
