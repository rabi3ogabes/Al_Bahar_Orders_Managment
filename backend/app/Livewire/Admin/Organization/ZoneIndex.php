<?php

namespace App\Livewire\Admin\Organization;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\DeliveryZone;
use App\Livewire\Admin\Concerns\WithAdminTable;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Company-wide delivery zones (Qatar zone numbers + optional polygon). Branch fees/ETAs are configured per branch. */
class ZoneIndex extends Component
{
    use WithAdminTable;

    #[Url(except: '')]
    public string $filterStatus = '';

    public bool $showEditor = false;

    public ?string $editingId = null;

    public array $form = [];

    public function mount(): void
    {
        abort_unless($this->can('zones.view'), 403);
        $this->form = $this->blank();
    }

    private function blank(): array
    {
        return ['code' => '', 'name_en' => '', 'name_ar' => '', 'zone_numbers' => '', 'polygon' => '', 'is_remote' => false, 'is_active' => true];
    }

    public function create(): void
    {
        abort_unless($this->canGlobally('zones.create'), 403);
        $this->resetValidation();
        $this->editingId = null;
        $this->form = $this->blank();
        $this->showEditor = true;
    }

    public function edit(string $id): void
    {
        abort_unless($this->canGlobally('zones.edit'), 403);
        $zone = DeliveryZone::findOrFail($id);
        $this->resetValidation();
        $this->editingId = $zone->id;
        $this->form = $zone->only(['code', 'name_en', 'name_ar', 'zone_numbers', 'is_remote', 'is_active']);
        $this->form['zone_numbers'] = (string) $zone->zone_numbers;
        $this->form['polygon'] = $zone->polygon ? json_encode($zone->polygon, JSON_PRETTY_PRINT) : '';
        $this->showEditor = true;
    }

    public function save(AuditLogger $audit): void
    {
        abort_unless($this->canGlobally($this->editingId ? 'zones.edit' : 'zones.create'), 403);
        $this->form['code'] = strtoupper(trim((string) ($this->form['code'] ?? ''))); // codes are stored upper-case: check uniqueness on that form
        $data = $this->validate([
            'form.code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9\-_]+$/', Rule::unique('delivery_zones', 'code')->ignore($this->editingId)],
            'form.name_en' => 'required|string|max:120',
            'form.name_ar' => 'required|string|max:120',
            'form.zone_numbers' => ['nullable', 'string', 'max:255', 'regex:/^\s*\d{1,3}(\s*,\s*\d{1,3})*\s*$/'],
            'form.polygon' => ['nullable', 'string', 'max:200000', function ($attr, $value, $fail) {
                if ($value !== null && trim($value) !== '' && $this->parsePolygon($value) === null) {
                    $fail(__('org_admin.polygon_invalid'));
                }
            }],
            'form.is_remote' => 'boolean',
            'form.is_active' => 'boolean',
        ], [], ['form.code' => __('org_admin.code'), 'form.zone_numbers' => __('org_admin.zone_numbers')])['form'];

        $attributes = [
            'code' => strtoupper(trim($data['code'])), 'name_en' => $data['name_en'], 'name_ar' => $data['name_ar'],
            'zone_numbers' => $data['zone_numbers'] ? implode(',', array_map('trim', explode(',', $data['zone_numbers']))) : null,
            'polygon' => trim((string) $data['polygon']) !== '' ? $this->parsePolygon($data['polygon']) : null,
            'is_remote' => (bool) $data['is_remote'], 'is_active' => (bool) $data['is_active'],
        ];

        if ($this->editingId) {
            $zone = DeliveryZone::findOrFail($this->editingId);
            $zone->fill($attributes);
            $dirty = $zone->getDirty();
            $old = array_intersect_key($zone->getOriginal(), $dirty);
            $zone->save();
            if ($dirty) {
                $audit->log('zone.updated', $zone, $old, $dirty);
            }
        } else {
            $zone = DeliveryZone::create($attributes);
            $audit->log('zone.created', $zone, [], $attributes);
        }
        $this->showEditor = false;
        $this->toast(__('admin.saved'));
    }

    public function toggleActive(string $id, AuditLogger $audit): void
    {
        abort_unless($this->canGlobally('zones.edit'), 403);
        $zone = DeliveryZone::findOrFail($id);
        $zone->update(['is_active' => ! $zone->is_active]);
        $audit->log('zone.updated', $zone, ['is_active' => ! $zone->is_active], ['is_active' => $zone->is_active]);
        $this->toast(__('admin.saved'));
    }

    public function delete(string $id, AuditLogger $audit): void
    {
        abort_unless($this->canGlobally('zones.delete'), 403);
        $zone = DeliveryZone::withCount('branchConfigs')->findOrFail($id);
        if ($zone->branch_configs_count > 0) {
            $this->toast(__('org_admin.zone_in_use'), 'error');

            return;
        }
        $snapshot = $zone->only(['code', 'name_en', 'zone_numbers']);
        $zone->delete();
        $audit->log('zone.deleted', 'DeliveryZone', $snapshot + ['id' => $id]);
        $this->toast(__('org_admin.zone_deleted'));
    }

    /** Accepts [[lng,lat],...] or a GeoJSON Polygon/Feature; returns the outer ring or null if invalid. */
    private function parsePolygon(string $json): ?array
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return null;
        }
        $data = $data['geometry'] ?? $data;
        if (($data['type'] ?? null) === 'Polygon') {
            $data = $data['coordinates'][0] ?? null;
        }
        if (! is_array($data) || count($data) < 3) {
            return null;
        }
        $ring = [];
        foreach ($data as $pt) {
            if (! is_array($pt) || count($pt) < 2 || ! is_numeric($pt[0]) || ! is_numeric($pt[1]) || abs($pt[0]) > 180 || abs($pt[1]) > 90) {
                return null;
            }
            $ring[] = [(float) $pt[0], (float) $pt[1]];
        }

        return $ring;
    }

    public function render()
    {
        $s = $this->search;
        $zones = DeliveryZone::query()
            ->with(['branchConfigs' => fn ($q) => $q->orderBy('priority')->with('branch:id,code,name_en,name_ar')])
            ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('name_en', 'ilike', "%{$s}%")->orWhere('name_ar', 'like', "%{$s}%")
                ->orWhere('code', 'ilike', "%{$s}%")->orWhereRaw("(',' || replace(coalesce(zone_numbers, ''), ' ', '') || ',') like ?", ['%,'.trim($s).',%'])))
            ->when($this->filterStatus === 'active', fn ($q) => $q->where('is_active', true))
            ->when($this->filterStatus === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($this->filterStatus === 'remote', fn ($q) => $q->where('is_remote', true))
            ->orderBy('name_en');

        return view('livewire.admin.organization.zones', [
            'zones' => $zones->paginate($this->perPage),
            'canCreate' => $this->canGlobally('zones.create'),
            'canEdit' => $this->canGlobally('zones.edit'),
            'canDelete' => $this->canGlobally('zones.delete'),
        ])->title(__('nav.zones'));
    }
}
