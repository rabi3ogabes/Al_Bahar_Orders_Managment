<?php

namespace App\Domain\Support\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Support\Models\SupportMessage;
use App\Domain\Support\Models\SupportTicket;
use App\Support\DocumentNumber;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/** Customer support desk. Internal notes are never visible to customers; first-response time is tracked for reporting. */
class SupportService
{
    public function open(User $customer, array $data): SupportTicket
    {
        return DB::transaction(function () use ($customer, $data) {
            $orderId = ! empty($data['order_id']) && $customer->orders()->whereKey($data['order_id'])->exists() ? $data['order_id'] : null;
            $ticket = SupportTicket::create(['number' => DocumentNumber::make('CS'), 'user_id' => $customer->id, 'order_id' => $orderId,
                'branch_id' => $orderId ? OrderFulfillment::where('order_id', $orderId)->value('branch_id') : null,
                'category' => $data['category'], 'subject' => strip_tags($data['subject']),
                'priority' => in_array($data['category'], ['payment', 'delivery'], true) ? 'high' : 'normal', 'status' => 'open']);
            SupportMessage::create(['support_ticket_id' => $ticket->id, 'author_id' => $customer->id, 'author_type' => 'customer', 'body' => strip_tags($data['message'])]);

            return $ticket;
        });
    }

    public function customerReply(SupportTicket $ticket, User $customer, string $body): SupportMessage
    {
        if ($ticket->user_id !== $customer->id) {
            throw DomainException::make('not_found', 404);
        }
        $msg = SupportMessage::create(['support_ticket_id' => $ticket->id, 'author_id' => $customer->id, 'author_type' => 'customer', 'body' => strip_tags($body)]);
        $ticket->update(['status' => in_array($ticket->status, ['resolved', 'closed'], true) ? 'open' : 'pending_staff']);

        return $msg;
    }

    public function staffReply(SupportTicket $ticket, User $staff, string $body, bool $internal = false, ?string $status = null): SupportMessage
    {
        $this->authorize($staff, 'support.edit', $ticket);
        $msg = SupportMessage::create(['support_ticket_id' => $ticket->id, 'author_id' => $staff->id, 'author_type' => 'staff', 'body' => $body, 'is_internal' => $internal]);
        $updates = [];
        if (! $internal && ! $ticket->first_response_at) {
            $updates['first_response_at'] = now();
        }
        if ($status) {
            $updates['status'] = $status;
            $updates += $status === 'resolved' ? ['resolved_at' => now()] : ($status === 'closed' ? ['closed_at' => now()] : []);
        } elseif (! $internal) {
            $updates['status'] = 'pending_customer';
        }
        $ticket->update($updates);
        if (! $internal) {
            app(NotificationService::class)->notify($ticket->user, 'support.reply', ['reference' => $ticket->number, 'deep_link' => 'albahar://support/'.$ticket->id]);
        }

        return $msg;
    }

    public function assign(SupportTicket $ticket, User $staff, ?User $assignee, ?string $priority = null): SupportTicket
    {
        $this->authorize($staff, 'support.assign', $ticket);
        $ticket->update(array_filter(['assigned_to' => $assignee?->id, 'priority' => $priority], fn ($v) => $v !== null));
        app(AuditLogger::class)->log('support.assigned', $ticket, [], ['assigned_to' => $assignee?->id, 'priority' => $priority], actor: $staff);

        return $ticket;
    }

    private function authorize(User $staff, string $permission, SupportTicket $ticket): void
    {
        if (! app(AccessControl::class)->allows($staff, $permission, $ticket->branch_id)) {
            throw DomainException::make('branch_forbidden', 403);
        }
    }
}
