<?php

namespace App\Livewire\Admin\Sales;

use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Orders\Printing\PrintParts;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\BusinessTime;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Print for the branch": the button and the side window on the Orders, Deliveries and Pickup screens. It picks a batch (everything waiting to be
 * prepared, or the deliveries of a day and a driver), shows how many parts that is and links to the one PDF (picking lists or delivery notes,
 * a page for every part). The PDF itself comes from PrintDocumentController, which checks the same access again: this window only builds the link.
 * Docs: docs/guides/PICKING_LIST_AND_DELIVERY_NOTE.md.
 */
class PrintWindow extends Component
{
    use InteractsWithAdmin;

    /** Where the window sits: `orders` (both documents), `deliveries` (delivery notes first, delivery parts only) or `pickup` (picking lists of pickup parts). */
    #[Locked]
    public string $context = 'orders';

    public bool $show = false;

    /** picking | note */
    public string $kind = 'picking';

    public string $date = '';

    /** '' = every driver, `none` = deliveries nobody has taken yet, or a driver's id */
    public string $driver = '';

    public string $branch = '';

    public function mount(string $context = 'orders'): void
    {
        $this->context = in_array($context, ['orders', 'deliveries', 'pickup'], true) ? $context : 'orders';
        $this->kind = $this->context === 'deliveries' ? 'note' : 'picking';
        $this->date = BusinessTime::now()->toDateString();
    }

    public function open(): void
    {
        abort_unless($this->can('orders.view'), 403);
        $this->show = true;
    }

    public function updatedKind(): void
    {
        if (! in_array($this->kind, $this->kinds(), true)) {
            $this->kind = $this->kinds()[0];
        }
    }

    public function updatedDate(): void
    {
        $this->driver = '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) !== 1 || strtotime($this->date) === false) {
            $this->date = BusinessTime::now()->toDateString();
        }
    }

    /** @return list<string> */
    private function kinds(): array
    {
        return match ($this->context) {
            'pickup' => ['picking'],
            'deliveries' => ['note', 'picking'],
            default => ['picking', 'note'],
        };
    }

    /** The picking list of the deliveries screen covers delivery parts only, that of the pickup screen pickup parts only. */
    private function type(): ?string
    {
        return match ($this->context) {
            'deliveries' => 'delivery',
            'pickup' => 'pickup',
            default => null,
        };
    }

    public function render(PrintParts $parts, AccessControl $acl)
    {
        $user = auth()->user();
        if (! $user instanceof User || ! $acl->allows($user, 'orders.view')) {
            return view('livewire.admin.sales.print-window', ['allowed' => false]);
        }
        $data = ['allowed' => true, 'kinds' => $this->kinds(), 'branches' => collect(), 'drivers' => [], 'count' => 0, 'total' => 0, 'capped' => false, 'url' => null, 'max' => PrintParts::MAX_PARTS];
        if (! $this->show) {
            return view('livewire.admin.sales.print-window', $data);
        }

        $scope = $acl->scope($user, 'orders.view');
        $branches = Branch::query()->when(! $scope->all, fn ($q) => $q->whereIn('id', $scope->branchIds))->orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_ar']);
        $branchId = $branches->contains('id', $this->branch) ? $this->branch : null;
        $driver = $this->driver === 'none' || Str::isUuid($this->driver) ? $this->driver : null;
        if ($this->kind === 'note') {
            $count = $parts->countDeliveries($user, $this->date, $driver, $branchId);
            $data['drivers'] = $parts->driversOn($user, $this->date, $branchId);
            $url = route('admin.print.note.batch', array_filter(['date' => $this->date, 'driver' => $driver, 'branch' => $branchId]));
        } else {
            $count = $parts->countWaiting($user, $branchId, $this->type());
            $url = route('admin.print.picking.batch', array_filter(['branch' => $branchId, 'type' => $this->type()]));
        }

        return view('livewire.admin.sales.print-window', array_merge($data, [
            'branches' => $branches,
            'count' => min($count, PrintParts::MAX_PARTS),
            'total' => $count,
            'capped' => $count > PrintParts::MAX_PARTS,
            'url' => $count > 0 ? $url : null,
        ]));
    }
}
