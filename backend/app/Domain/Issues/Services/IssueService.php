<?php

namespace App\Domain\Issues\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Issues\Models\IssueReport;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Payments\Models\Payment;
use App\Support\DomainException;

class IssueService
{
    public function __construct(private readonly AuditLogger $audit, private readonly AccessControl $access) {}

    /**
     * @param  array{kind: string, app: string, severity?: string, title: string, details?: ?string, order_number?: ?string, payment_reference?: ?string}  $data
     */
    public function report(User $by, array $data): IssueReport
    {
        // A branch-limited reporter can link only orders their branches take part in (a number from another branch reads as "not found", never as "exists").
        $scope = $this->access->scope($by, 'orders.view');
        $visibleOrders = fn ($q) => $q->when(! $scope->all, fn ($w) => $w->whereIn('id', $scope->apply(OrderFulfillment::query()->select('order_id')->getQuery(), 'branch_id')));
        $order = ! empty($data['order_number']) ? Order::where('number', trim($data['order_number']))->tap($visibleOrders)->first() : null;
        if (! empty($data['order_number']) && ! $order) {
            throw new DomainException('issue_order_not_found');
        }
        $payment = ! empty($data['payment_reference']) ? Payment::where('provider_reference', trim($data['payment_reference']))
            ->when(! $scope->all, fn ($q) => $q->whereIn('order_id', Order::query()->tap($visibleOrders)->select('id')))->first() : null;
        $payment ??= $order && ($data['kind'] === 'payment') ? $order->payments()->latest()->first() : null;

        $issue = IssueReport::create([
            'kind' => $data['kind'], 'app' => $data['app'], 'severity' => $data['severity'] ?? 'medium', 'title' => trim($data['title']),
            'details' => $data['details'] ?? null, 'order_id' => $order?->id, 'payment_id' => $payment?->id, 'reported_by' => $by->id,
        ]);
        $this->audit->log('issue.reported', $issue, [], ['reference' => $issue->reference, 'kind' => $issue->kind, 'severity' => $issue->severity], []);

        return $issue;
    }

    public function setStatus(IssueReport $issue, string $status, User $by, ?string $resolution = null): IssueReport
    {
        if (! in_array($status, IssueReport::STATUSES, true)) {
            throw new DomainException('issue_bad_status');
        }
        $closing = in_array($status, ['resolved', 'wont_fix'], true);
        $before = $issue->status;
        $issue->fill([
            'status' => $status,
            'resolution' => $closing ? ($resolution ?: $issue->resolution) : null,
            'resolved_by' => $closing ? $by->id : null,
            'resolved_at' => $closing ? now() : null,
        ])->save();
        $this->audit->log('issue.status_changed', $issue, ['status' => $before], ['status' => $status], ['reference' => $issue->reference]);

        return $issue;
    }
}
