<?php

namespace App\Livewire\Admin\Inventory;

use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\StockMovement;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Support\BusinessTime;
use Livewire\Attributes\Url;
use Livewire\Component;

/** The immutable stock ledger — every change to on-hand or reserved, with resulting balances. Read-only. */
class MovementIndex extends Component
{
    use WithAdminTable;

    #[Url(except: '')]
    public string $filterType = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function mount(): void
    {
        abort_unless($this->can('inventory.view'), 403);
        $this->perPage = 30;
    }

    public function render()
    {
        $s = trim($this->search);
        $q = StockMovement::query()->with(['branch:id,code,name_en,name_ar', 'variant.product.translations', 'variant.attributeValues', 'actor:id,name'])
            ->when($s !== '', fn ($q) => $q->where(fn ($w) => $w->whereHas('variant', fn ($v) => $v->where('sku', 'ilike', addcslashes($s, '%_\\').'%')->orWhere('barcode', $s))
                ->orWhere('reference_id', $s)->orWhere('reason', 'ilike', '%'.addcslashes($s, '%_\\').'%')))
            ->when($this->filterType, fn ($q, $t) => $q->where('type', $t))
            ->when($this->from && strtotime($this->from), fn ($q) => $q->where('created_at', '>=', BusinessTime::dateRange($this->from, $this->from)[0]))
            ->when($this->to && strtotime($this->to), fn ($q) => $q->where('created_at', '<=', BusinessTime::dateRange($this->to, $this->to)[1]))
            ->orderByDesc('id');
        $this->branchScope('inventory.view')->apply($q->getQuery());

        return view('livewire.admin.inventory.movements', [
            'movements' => $q->paginate($this->perPage),
            'types' => array_map(fn ($c) => $c->value, MovementType::cases()),
            'branches' => $this->scopedBranches('inventory.view'),
        ])->title(__('nav.movements'));
    }
}
