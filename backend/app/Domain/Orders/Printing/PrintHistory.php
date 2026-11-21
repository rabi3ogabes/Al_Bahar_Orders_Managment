<?php

namespace App\Domain\Orders\Printing;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\OrderFulfillment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who printed which document of which part, and how many times. The audit log is the one record: every printed part writes one entry
 * (`order.picking_list_printed` / `order.delivery_note_printed`, subject = the part, with the copy number), and the reprint counter the
 * dashboard shows ("printed 2 times, last by Noor at 14:05") is read back from those entries. A duplicate delivery note is therefore visible
 * on the part, on the paper itself (copy N) and in the audit log, and the counter can never disagree with the log because it is the log.
 *
 * Reads are index-backed (audit_logs has an index on auditable_type, auditable_id) and bounded by the parts asked about.
 */
final class PrintHistory
{
    /** The audit subject type of a part (class_basename of the model, as AuditLogger writes it). */
    private const SUBJECT = 'OrderFulfillment';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<string>  $partIds
     * @return array<string, array<string, array{count: int, last_at: CarbonImmutable, last_by: ?string}>> part id => document kind => summary (a kind never printed is missing)
     */
    public function summaries(array $partIds): array
    {
        $partIds = array_values(array_unique($partIds));
        if ($partIds === []) {
            return [];
        }
        $actions = PrintKind::auditActions();
        $base = fn () => DB::table('audit_logs as a')->where('a.auditable_type', self::SUBJECT)->whereIn('a.auditable_id', $partIds)->whereIn('a.action', $actions);

        $counts = $base()->groupBy('a.auditable_id', 'a.action')->selectRaw('a.auditable_id, a.action, count(*) as prints')->get();
        $last = $base()->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
            ->selectRaw('DISTINCT ON (a.auditable_id, a.action) a.auditable_id, a.action, a.created_at, u.name as actor_name')
            ->orderBy('a.auditable_id')->orderBy('a.action')->orderByDesc('a.created_at')->orderByDesc('a.id')->get()
            ->keyBy(fn ($r) => $r->auditable_id.'|'.$r->action);

        $out = [];
        foreach ($counts as $row) {
            $kind = PrintKind::fromAuditAction((string) $row->action);
            $latest = $last->get($row->auditable_id.'|'.$row->action);
            if ($kind === null || $latest === null) {
                continue;
            }
            $out[(string) $row->auditable_id][$kind->value] = ['count' => (int) $row->prints, 'last_at' => CarbonImmutable::parse($latest->created_at), 'last_by' => $latest->actor_name];
        }

        return $out;
    }

    /**
     * Writes the print of every part of one request (all or none).
     *
     * @param  Collection<int, OrderFulfillment>  $parts  with their order loaded
     * @param  array<string, int>  $copies  part id => the copy number this print is (see {@see nextCopies()})
     */
    public function record(User $user, PrintKind $kind, Collection $parts, string $paper, array $copies): void
    {
        if ($parts->isEmpty()) {
            return;
        }
        DB::transaction(function () use ($user, $kind, $parts, $paper, $copies) {
            foreach ($parts as $part) {
                $this->audit->log($kind->auditAction(), $part, [], [], ['number' => $part->number, 'order' => $part->order->number, 'copy' => $copies[$part->id] ?? 1,
                    'parts_in_request' => $parts->count(), 'paper' => $paper], branchId: $part->branch_id, actor: $user);
            }
        });
    }

    /**
     * What the next copy of $kind will be for each part: 1 for a part never printed, 2 for one printed once, and so on.
     *
     * @param  list<string>  $partIds
     * @return array<string, int>
     */
    public function nextCopies(PrintKind $kind, array $partIds): array
    {
        $summaries = $this->summaries($partIds);

        return array_combine($partIds, array_map(fn (string $id) => ($summaries[$id][$kind->value]['count'] ?? 0) + 1, $partIds)) ?: [];
    }
}
