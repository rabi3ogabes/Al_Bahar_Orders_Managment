<?php

namespace App\Livewire\Admin\Catalog;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Product attributes (colour, size, material…) and their values, including colour swatches. */
class AttributeIndex extends Component
{
    use InteractsWithAdmin;

    #[Url(as: 'attribute', except: null)]
    public ?int $selected = null;

    public bool $showEditor = false;

    public ?int $editingId = null;

    public array $form = [];

    public array $valueForm = [];

    public ?int $editingValueId = null;

    public function mount(): void
    {
        abort_unless($this->can('catalog.view'), 403);
        $this->form = $this->blankAttribute();
        $this->valueForm = $this->blankValue();
        $this->selected ??= ProductAttribute::orderBy('sort_order')->orderBy('name_en')->value('id');
    }

    private function blankAttribute(): array
    {
        return ['code' => '', 'name_en' => '', 'name_ar' => '', 'type' => 'select', 'is_filterable' => true, 'is_variant_axis' => true, 'sort_order' => 0];
    }

    private function blankValue(): array
    {
        return ['code' => '', 'value_en' => '', 'value_ar' => '', 'color_hex' => '', 'sort_order' => null];
    }

    public function select(int $id): void
    {
        $this->selected = $id;
        $this->cancelValue();
    }

    public function create(): void
    {
        abort_unless($this->canGlobally('catalog.create'), 403);
        $this->resetValidation();
        $this->editingId = null;
        $this->form = $this->blankAttribute();
        $this->showEditor = true;
    }

    public function edit(int $id): void
    {
        abort_unless($this->canGlobally('catalog.edit'), 403);
        $a = ProductAttribute::findOrFail($id);
        $this->resetValidation();
        $this->editingId = $a->id;
        $this->form = $a->only(array_keys($this->blankAttribute()));
        $this->showEditor = true;
    }

    public function save(AuditLogger $audit): void
    {
        abort_unless($this->canGlobally($this->editingId ? 'catalog.edit' : 'catalog.create'), 403);
        $data = $this->validate([
            'form.code' => ['required', 'string', 'max:48', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('product_attributes', 'code')->ignore($this->editingId)],
            'form.name_en' => 'required|string|max:120',
            'form.name_ar' => 'required|string|max:120',
            'form.type' => 'required|in:select,color,text',
            'form.is_filterable' => 'boolean',
            'form.is_variant_axis' => 'boolean',
            'form.sort_order' => 'required|integer|min:0|max:100000',
        ], [], ['form.code' => __('catalog_admin.code')])['form'];
        $a = $this->editingId ? ProductAttribute::findOrFail($this->editingId) : new ProductAttribute;
        $a->fill($data);
        $dirty = $a->getDirty();
        $a->save();
        $audit->log('catalog.attribute_saved', $a, [], $dirty);
        $this->selected = $a->id;
        $this->showEditor = false;
        $this->toast(__('admin.saved'));
    }

    public function deleteAttribute(int $id, AuditLogger $audit): void
    {
        abort_unless($this->canGlobally('catalog.delete'), 403);
        $a = ProductAttribute::findOrFail($id);
        if ($this->valuesInUse($a->values()->pluck('id')->all())) {
            $this->toast(__('catalog_admin.attribute_in_use'), 'error');

            return;
        }
        $snapshot = $a->only(['id', 'code', 'name_en']);
        $a->delete();
        $audit->log('catalog.attribute_deleted', 'ProductAttribute', $snapshot);
        $this->selected = ProductAttribute::orderBy('sort_order')->value('id');
        $this->toast(__('catalog_admin.attribute_deleted'));
    }

    public function editValue(int $id): void
    {
        $v = ProductAttributeValue::where('product_attribute_id', $this->selected)->findOrFail($id);
        $this->resetValidation();
        $this->editingValueId = $v->id;
        $this->valueForm = $v->only(['code', 'value_en', 'value_ar', 'color_hex', 'sort_order']);
        $this->valueForm['color_hex'] = (string) $v->color_hex;
    }

    public function cancelValue(): void
    {
        $this->editingValueId = null;
        $this->valueForm = $this->blankValue();
        $this->resetValidation();
    }

    public function updatedValueFormValueEn(string $v): void
    {
        if (! $this->editingValueId && $this->valueForm['code'] === '') {
            $this->valueForm['code'] = Str::slug($v, '_');
        }
    }

    public function saveValue(AuditLogger $audit): void
    {
        abort_unless($this->canGlobally('catalog.edit'), 403);
        $attribute = ProductAttribute::findOrFail($this->selected);
        $data = $this->validate([
            'valueForm.code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_\-]+$/',
                Rule::unique('product_attribute_values', 'code')->where('product_attribute_id', $attribute->id)->ignore($this->editingValueId)],
            'valueForm.value_en' => 'required|string|max:120',
            'valueForm.value_ar' => 'required|string|max:120',
            'valueForm.color_hex' => [$attribute->type === 'color' ? 'required' : 'nullable', 'regex:/^#([0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/'],
            'valueForm.sort_order' => 'nullable|integer|min:0|max:100000',
        ], [], ['valueForm.code' => __('catalog_admin.code'), 'valueForm.color_hex' => __('catalog_admin.swatch')])['valueForm'];
        $data['color_hex'] = $data['color_hex'] ? strtolower($data['color_hex']) : null;
        $data['sort_order'] ??= (int) $attribute->values()->max('sort_order') + 1;
        $v = $this->editingValueId ? $attribute->values()->findOrFail($this->editingValueId) : new ProductAttributeValue(['product_attribute_id' => $attribute->id]);
        $v->fill($data)->save();
        $audit->log('catalog.attribute_saved', $attribute, [], ['value' => $data]);
        $this->cancelValue();
        $this->toast(__('admin.saved'));
    }

    public function moveValue(int $id, int $direction): void
    {
        abort_unless($this->canGlobally('catalog.edit'), 403);
        $values = ProductAttributeValue::where('product_attribute_id', $this->selected)->orderBy('sort_order')->orderBy('id')->get()->values();
        $i = $values->search(fn ($v) => $v->id === $id);
        $j = $i + ($direction < 0 ? -1 : 1);
        if ($i === false || ! isset($values[$j])) {
            return;
        }
        $ordered = $values->all();
        [$ordered[$i], $ordered[$j]] = [$ordered[$j], $ordered[$i]];
        DB::transaction(function () use ($ordered) {
            foreach ($ordered as $pos => $v) {
                $v->update(['sort_order' => $pos]);
            }
        });
    }

    public function deleteValue(int $id, AuditLogger $audit): void
    {
        abort_unless($this->canGlobally('catalog.delete'), 403);
        $v = ProductAttributeValue::where('product_attribute_id', $this->selected)->findOrFail($id);
        if ($this->valuesInUse([$v->id])) {
            $this->toast(__('catalog_admin.value_in_use'), 'error');

            return;
        }
        $v->delete();
        $audit->log('catalog.attribute_value_deleted', 'ProductAttributeValue', $v->only(['id', 'code', 'value_en', 'product_attribute_id']));
        $this->toast(__('catalog_admin.value_deleted'));
    }

    private function valuesInUse(array $ids): bool
    {
        return $ids && DB::table('product_variant_values')->whereIn('product_attribute_value_id', $ids)->exists();
    }

    public function render()
    {
        $attributes = ProductAttribute::withCount('values')->orderBy('sort_order')->orderBy('name_en')->get();
        $current = $this->selected ? $attributes->firstWhere('id', $this->selected) : null;
        $values = $current ? $current->values()->get() : collect();
        $usage = $values->isNotEmpty()
            ? DB::table('product_variant_values')->whereIn('product_attribute_value_id', $values->pluck('id'))->selectRaw('product_attribute_value_id, count(*) as c')->groupBy('product_attribute_value_id')->pluck('c', 'product_attribute_value_id')
            : collect();

        return view('livewire.admin.catalog.attributes', [
            'attributes' => $attributes,
            'current' => $current,
            'values' => $values,
            'usage' => $usage,
            'canCreate' => $this->canGlobally('catalog.create'),
            'canEdit' => $this->canGlobally('catalog.edit'),
            'canDelete' => $this->canGlobally('catalog.delete'),
        ])->title(__('nav.attributes'));
    }
}
