<?php

namespace App\Livewire\Admin\Messaging;

use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Services\ContactHistory;
use App\Domain\Messaging\Services\CustomerMessaging;
use App\Domain\Notifications\Services\DevicePushReach;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Livewire\Admin\Sales\CustomerShow;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Messages sent to this customer": the notifications and WhatsApp messages the team sent by hand and the ones the automatic journeys sent,
 * newest first, each with its result. Lives on the customer's page (and in the customer's side window). A message links to the send it
 * belonged to only when the viewer may open that send (company-wide notification rights, or their own).
 */
class CustomerTimeline extends Component
{
    use InteractsWithAdmin;

    public const STEP = 15;

    public const MAX = 150;

    #[Locked]
    public string $customerId = '';

    /** How many entries are shown; "Show more" raises it by STEP. */
    #[Locked]
    public int $shown = self::STEP;

    public function mount(User $customer): void
    {
        $this->authorizeViewer($customer);
        $this->customerId = $customer->id;
    }

    /** The message window just queued a send: the list is read again, so the new message shows at the top without a reload. */
    #[On('messages-sent')]
    public function refreshAfterSend(string $batch = '', string $source = ''): void
    {
        $this->authorizeViewer($this->customer());
    }

    public function showMore(): void
    {
        $this->authorizeViewer($this->customer());
        $this->shown = min(self::MAX, $this->shown + self::STEP);
    }

    private function customer(): User
    {
        return User::query()->where('type', 'customer')->findOrFail($this->customerId);
    }

    /** Same gate as the customer's page, plus the right to see sent messages. */
    private function authorizeViewer(User $customer): void
    {
        abort_unless($this->can('notifications.view') && CustomerShow::viewableBy(auth()->user(), $customer), 403);
    }

    public function render(ContactHistory $history, CustomerMessaging $messaging, DevicePushReach $phones)
    {
        $customer = $this->customer();
        $this->authorizeViewer($customer);
        // One more than shown, so the page knows whether "Show more" is worth offering.
        $entries = $history->timeline($customer->id, $this->shown + 1);
        $more = $entries->count() > $this->shown;
        $entries = $entries->take($this->shown)->values();
        $batchIds = $entries->pluck('batch_id')->filter()->unique()->values()->all();
        $openable = $batchIds === [] ? [] : array_flip($messaging->visibleBatches(auth()->user())->whereIn('id', $batchIds)->pluck('id')->map(fn ($id) => (string) $id)->all());
        $last = $entries->first(fn (array $e) => in_array($e['status'], ['sent', 'delivered'], true));

        return view('livewire.admin.messaging.customer-timeline', [
            'entries' => $entries,
            'more' => $more && $this->shown < self::MAX,
            'openable' => $openable,
            'last' => $last,
            'reach' => $phones->forUser($customer->id),
        ]);
    }
}
