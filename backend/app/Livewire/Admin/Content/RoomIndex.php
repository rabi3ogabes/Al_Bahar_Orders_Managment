<?php

namespace App\Livewire\Admin\Content;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Catalog\Models\Room;
use App\Domain\Catalog\Services\RoomImages;
use App\Domain\Catalog\Services\RoomService;
use App\Livewire\Admin\Catalog\CategoryIndex;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Livewire\Admin\Concerns\StoresCatalogMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Content > Rooms: the rooms of "Shop by room" (the big tiles on the home page and the room filter of the shop). The owner chooses the order, the picture, the
 * titles in both languages, and what belongs to each room: categories (their sub-categories too) and product tags. A room with no product is not shown to
 * customers; the list says so. Every change is permission-checked (cms.*) and audited.
 */
class RoomIndex extends Component
{
    use InteractsWithAdmin, StoresCatalogMedia, WithFileUploads; // StoresCatalogMedia: the upload rule and the picture's URL; the file itself goes through RoomImages

    public bool $showEditor = false;

    public ?string $editingId = null;

    public array $form = [];

    public $image = null;

    public function mount(): void
    {
        abort_unless($this->can('cms.view'), 403);
        $this->form = $this->blank();
        if ($this->can('cms.create')) {
            app(RoomService::class)->ensureHomeSection(); // the home page gets its "Shop by room" section the first time someone who may edit the content looks here
        }
    }

    private function blank(): array
    {
        return ['slug' => '', 'title_en' => '', 'title_ar' => '', 'description_en' => '', 'description_ar' => '', 'tags' => '', 'is_active' => true, 'category_ids' => []];
    }

    public function create(): void
    {
        abort_unless($this->can('cms.create'), 403);
        $this->resetValidation();
        $this->reset('image', 'editingId');
        $this->form = $this->blank();
        $this->showEditor = true;
    }

    public function edit(string $id): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $r = Room::with('categories:id')->findOrFail($id);
        $this->resetValidation();
        $this->reset('image');
        $this->editingId = $r->id;
        $this->form = ['slug' => $r->slug, 'title_en' => $r->title_en, 'title_ar' => $r->title_ar, 'description_en' => (string) $r->description_en, 'description_ar' => (string) $r->description_ar,
            'tags' => implode(', ', $r->tags ?? []), 'is_active' => (bool) $r->is_active, 'category_ids' => $r->categories->pluck('id')->all()];
        $this->showEditor = true;
    }

    public function updatedFormTitleEn(string $value): void
    {
        if (! $this->editingId && $this->form['slug'] === '') {
            $this->form['slug'] = Str::slug($value);
        }
    }

    public function save(AuditLogger $audit, RoomImages $images): void
    {
        abort_unless($this->can($this->editingId ? 'cms.edit' : 'cms.create'), 403);
        $d = $this->validate([
            'form.slug' => ['required', 'alpha_dash', 'max:60', Rule::unique('rooms', 'slug')->ignore($this->editingId)],
            'form.title_en' => 'required|string|max:80', 'form.title_ar' => 'required|string|max:80',
            'form.description_en' => 'nullable|string|max:400', 'form.description_ar' => 'nullable|string|max:400',
            'form.tags' => 'nullable|string|max:600', 'form.is_active' => 'boolean',
            'form.category_ids' => 'array|max:50', 'form.category_ids.*' => ['uuid', 'exists:categories,id'],
            'image' => self::imageRule(4096),
        ], [], ['form.slug' => __('rooms_admin.slug'), 'form.title_en' => __('rooms_admin.title_en'), 'form.title_ar' => __('rooms_admin.title_ar'), 'image' => __('rooms_admin.image')])['form'];
        $tags = collect(explode(',', (string) $d['tags']))->map(fn ($t) => mb_strtolower(mb_substr(trim($t), 0, 40)))->filter()->unique()->take(30)->values()->all();

        $room = $this->editingId ? Room::findOrFail($this->editingId) : new Room(['sort_order' => (int) Room::max('sort_order') + 1]);
        $oldImage = $room->image_path;
        $room->fill(['slug' => $d['slug'], 'title_en' => $d['title_en'], 'title_ar' => $d['title_ar'], 'description_en' => $d['description_en'] ?: null, 'description_ar' => $d['description_ar'] ?: null,
            'tags' => $tags ?: null, 'is_active' => (bool) $d['is_active']]);
        if ($this->image) {
            $room->image_path = $images->store($this->image);
        }
        $old = $room->exists ? array_intersect_key($room->getOriginal(), $room->getDirty()) : [];
        DB::transaction(function () use ($room, $d) {
            $room->save();
            $room->categories()->sync($d['category_ids']);
        });
        RoomService::flush(); // the links to the categories changed too, which the model events do not see
        if ($this->image && $oldImage) {
            $images->delete($oldImage);
        }
        $audit->log($this->editingId ? 'cms.room_updated' : 'cms.room_created', $room, $old, $room->only(['slug', 'title_en', 'is_active', 'tags']), ['categories' => count($d['category_ids'])]);
        $this->reset('image');
        $this->showEditor = false;
        $this->toast(__('admin.saved'));
    }

    public function removeImage(AuditLogger $audit, RoomImages $images): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $room = Room::findOrFail($this->editingId ?? abort(404));
        $old = $room->image_path;
        $room->update(['image_path' => null]);
        $images->delete($old);
        $audit->log('cms.room_updated', $room, ['image_path' => $old], ['image_path' => null]);
    }

    /** The switch on a row acts at once (no Save button). */
    public function toggle(string $id, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $room = Room::findOrFail($id);
        $room->update(['is_active' => ! $room->is_active]);
        $audit->log($room->is_active ? 'cms.room_shown' : 'cms.room_hidden', $room, [], ['slug' => $room->slug]);
        $this->toast($room->is_active ? __('rooms_admin.shown') : __('rooms_admin.hidden'));
    }

    public function move(string $id, int $direction, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $rooms = Room::orderBy('sort_order')->orderBy('title_en')->get()->values();
        $i = $rooms->search(fn (Room $r) => $r->id === $id);
        $j = $i === false ? false : $i + ($direction < 0 ? -1 : 1);
        if ($i === false || ! isset($rooms[$j])) {
            return;
        }
        $ordered = $rooms->all();
        [$ordered[$i], $ordered[$j]] = [$ordered[$j], $ordered[$i]];
        foreach ($ordered as $pos => $r) {
            if ($r->sort_order !== $pos) {
                $r->update(['sort_order' => $pos]);
            }
        }
        $audit->log('cms.rooms_reordered', 'Room', [], ['order' => collect($ordered)->pluck('slug')->all()]);
    }

    public function delete(string $id, AuditLogger $audit, RoomImages $images): void
    {
        abort_unless($this->can('cms.delete'), 403);
        $room = Room::findOrFail($id);
        $snapshot = $room->only(['id', 'slug', 'title_en']);
        $image = $room->image_path;
        $room->delete();
        $images->delete($image);
        $audit->log('cms.room_deleted', 'Room', $snapshot);
        $this->toast(__('rooms_admin.deleted'));
    }

    public function render(RoomService $rooms)
    {
        $list = $rooms->all();

        return view('livewire.admin.content.rooms', [
            'rooms' => $list,
            'counts' => $list->mapWithKeys(fn (Room $r) => [$r->id => $rooms->productCount($r, null)]),
            'categories' => $this->showEditor ? CategoryIndex::treeOptions() : collect(),
            'existingImage' => $this->editingId ? self::mediaUrl(Room::find($this->editingId)?->image_path) : null,
            'canCreate' => $this->can('cms.create'), 'canEdit' => $this->can('cms.edit'), 'canDelete' => $this->can('cms.delete'),
        ])->title(__('nav.rooms'));
    }
}
