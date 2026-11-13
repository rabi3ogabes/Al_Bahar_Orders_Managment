<?php

namespace App\Livewire\Admin\Marketing;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Orders\Models\Order;
use App\Domain\Support\Models\SupportTicket;
use App\Domain\Support\Services\SupportService;
use App\Livewire\Admin\Concerns\WithAdminTable;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Support desk: ticket queue (branch-scoped), threaded conversation with internal notes, assignment, priority and status. */
class SupportIndex extends Component
{
    use WithAdminTable;

    #[Url(except: 'open')]
    public string $filterStatus = 'open';

    #[Url(except: '')]
    public string $filterPriority = '';

    #[Url(except: false)]
    public bool $mine = false;

    #[Url(as: 'ticket', except: '')]
    public string $selected = '';

    /** The ticket window (x-admin.dialog, wire:model.live): the queue stays in view; `?ticket=<id>` keeps it reloadable and Back-friendly. */
    public bool $showThread = false;

    public string $reply = '';

    public bool $internal = false;

    public string $replyStatus = '';

    public function mount(): void
    {
        abort_unless($this->can('support.view'), 403);
        if ($this->selected && (! Str::isUuid($this->selected) || ! $this->visible()->whereKey($this->selected)->exists())) {
            $this->selected = '';
        }
        $this->showThread = $this->selected !== '';
    }

    private function visible()
    {
        $scope = $this->branchScope('support.view');

        return tap(SupportTicket::query(), fn ($q) => $scope->apply($q->getQuery(), 'branch_id'));
    }

    private function ticket(): SupportTicket
    {
        return $this->visible()->findOrFail($this->selected);
    }

    public function open(string $id): void
    {
        $this->selected = $this->visible()->findOrFail($id)->id;
        $this->showThread = true;
        $this->reset('reply', 'internal', 'replyStatus');
        $this->resetValidation();
    }

    public function closeThread(): void
    {
        $this->showThread = false;
        $this->selected = '';
    }

    /** ✕, Esc, scrim and Back all end up here (wire:model.live): drop the id so the URL is clean again. */
    public function updatedShowThread(bool $open): void
    {
        if (! $open) {
            $this->selected = '';
        }
    }

    public function send(SupportService $svc): void
    {
        $t = $this->ticket();
        $this->authorizeBranch('support.edit', $t->branch_id);
        $this->validate(['reply' => 'required|string|min:2|max:5000', 'replyStatus' => 'nullable|in:open,pending_customer,pending_staff,resolved,closed'], [], ['reply' => __('marketing_admin.reply')]);
        if ($this->attempt(fn () => $svc->staffReply($t, auth()->user(), trim($this->reply), $this->internal, $this->replyStatus ?: null), $this->internal ? __('marketing_admin.note_added') : __('marketing_admin.reply_sent'))) {
            $this->reset('reply', 'internal', 'replyStatus');
        }
    }

    public function assign(string $field, string $value, SupportService $svc): void
    {
        abort_unless(in_array($field, ['assignee', 'priority'], true), 422);
        $t = $this->ticket();
        $this->authorizeBranch('support.assign', $t->branch_id);
        if ($field === 'priority') {
            abort_unless(in_array($value, ['low', 'normal', 'high', 'urgent'], true), 422);
            $this->attempt(fn () => $svc->assign($t, auth()->user(), $t->assignee, $value), __('marketing_admin.priority_updated'));
        } else {
            $assignee = $value ? User::where('type', 'staff')->findOrFail($value) : null;
            $this->attempt(fn () => $svc->assign($t, auth()->user(), $assignee), __('marketing_admin.assigned'));
        }
    }

    public function setStatus(string $status, SupportService $svc): void
    {
        abort_unless(in_array($status, ['resolved', 'closed', 'open'], true), 422);
        $t = $this->ticket();
        $this->authorizeBranch('support.edit', $t->branch_id);
        $this->attempt(fn () => $svc->staffReply($t, auth()->user(), __('marketing_admin.status_note', ['status' => __('status.'.$status)]), true, $status), __('marketing_admin.status_changed'));
    }

    public function render()
    {
        $s = trim($this->search);
        $q = $this->visible()->with(['user:id,name', 'assignee:id,name'])->withCount('messages')
            ->when($this->filterStatus === 'open', fn ($q) => $q->whereIn('status', ['open', 'pending_staff', 'pending_customer']), fn ($q) => $q->when($this->filterStatus, fn ($q, $st) => $q->where('status', $st)))
            ->when($this->filterPriority, fn ($q, $p) => $q->where('priority', $p))
            ->when($this->mine, fn ($q) => $q->where('assigned_to', auth()->id()))
            ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('number', 'ilike', "%{$s}%")->orWhere('subject', 'ilike', "%{$s}%")))
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")->orderByRaw("CASE status WHEN 'pending_staff' THEN 0 WHEN 'open' THEN 1 ELSE 2 END")->latest('updated_at');
        $t = $this->showThread && $this->selected !== '' && Str::isUuid($this->selected) ? $this->visible()->with(['user:id,name,phone', 'assignee:id,name', 'messages' => fn ($m) => $m->with('author:id,name')->orderBy('created_at')])->find($this->selected) : null;
        $acl = app(AccessControl::class);
        $agents = $t ? User::where('type', 'staff')->where('status', 'active')->orderBy('name')->get(['id', 'name'])->filter(fn ($u) => $acl->allows($u, 'support.edit', $t->branch_id))->values() : collect();

        return view('livewire.admin.marketing.support', [
            'tickets' => $q->paginate($this->perPage),
            't' => $t,
            'agents' => $agents,
            'canReply' => $t && $this->can('support.edit', $t->branch_id),
            'canAssign' => $t && $this->can('support.assign', $t->branch_id),
            'order' => $t?->order_id ? Order::find($t->order_id, ['id', 'number']) : null,
        ])->title(__('nav.support'));
    }
}
