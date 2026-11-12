<?php

namespace App\Livewire\Admin\Content;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Cms\Models\BrandTheme;
use App\Domain\Cms\Services\ThemeService;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Livewire\Admin\Concerns\StoresCatalogMedia;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Appearance: brand colours, artwork and Qatar event looks for the store and the apps, switched by hand or by date. */
class ThemeIndex extends Component
{
    use InteractsWithAdmin, StoresCatalogMedia, WithFileUploads;

    public bool $showEditor = false;

    #[Locked]
    public ?string $editingId = null;

    public array $form = [];

    public $heroImage = null;

    public $bannerImage = null;

    public $logoImage = null;

    public $splashImage = null;

    public function mount(): void
    {
        abort_unless($this->can('cms.view'), 403);
    }

    public function create(): void
    {
        abort_unless($this->can('cms.create'), 403);
        $this->editingId = null;
        $base = BrandTheme::where('is_default', true)->first();
        $this->form = ['name_en' => '', 'name_ar' => '', 'colors' => $base?->colors ?? ['primary' => '#8A1538', 'primary_soft' => '#A3294E', 'accent' => '#B3254F', 'background' => '#FCF9FA', 'surface' => '#FFFFFF', 'ink' => '#2A1A20'],
            'banner_title_en' => '', 'banner_title_ar' => '', 'banner_subtitle_en' => '', 'banner_subtitle_ar' => '', 'schedule' => 'none', 'rs_m' => '12', 'rs_d' => '1', 'rs_t' => '00:00', 're_m' => '12', 're_d' => '2', 're_t' => '23:59',
            'starts_at' => '', 'ends_at' => '', 'priority' => 100, 'is_enabled' => true];
        $this->reset('heroImage', 'bannerImage', 'logoImage', 'splashImage');
        $this->resetValidation();
        $this->showEditor = true;
    }

    public function edit(string $id): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $t = BrandTheme::findOrFail($id);
        $local = fn ($v) => $v ? BusinessTime::local($v)->format('Y-m-d\TH:i') : '';
        $this->editingId = $t->id;
        $this->form = [
            'name_en' => $t->name_en, 'name_ar' => $t->name_ar, 'colors' => $t->colors,
            'banner_title_en' => $t->banner_title_en ?? '', 'banner_title_ar' => $t->banner_title_ar ?? '', 'banner_subtitle_en' => $t->banner_subtitle_en ?? '', 'banner_subtitle_ar' => $t->banner_subtitle_ar ?? '',
            'schedule' => $t->recur_start ? 'yearly' : (($t->starts_at || $t->ends_at) ? 'once' : 'none'),
            'rs_m' => $t->recur_start ? (string) (int) substr($t->recur_start, 0, 2) : '12', 'rs_d' => $t->recur_start ? (string) (int) substr($t->recur_start, 3, 2) : '1', 'rs_t' => $t->recur_start_time ?: '00:00',
            're_m' => $t->recur_end ? (string) (int) substr($t->recur_end, 0, 2) : '12', 're_d' => $t->recur_end ? (string) (int) substr($t->recur_end, 3, 2) : '2', 're_t' => $t->recur_end_time ?: '23:59',
            'starts_at' => $local($t->starts_at), 'ends_at' => $local($t->ends_at),
            'priority' => $t->priority, 'is_enabled' => $t->is_enabled,
        ];
        $this->reset('heroImage', 'bannerImage', 'logoImage', 'splashImage');
        $this->resetValidation();
        $this->showEditor = true;
    }

    public function save(AuditLogger $audit, ThemeService $themes): void
    {
        abort_unless($this->can($this->editingId ? 'cms.edit' : 'cms.create'), 403);
        $hex = ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'];
        $rules = [
            'form.name_en' => 'required|string|max:120', 'form.name_ar' => 'required|string|max:120',
            'form.banner_title_en' => 'nullable|string|max:120', 'form.banner_title_ar' => 'nullable|string|max:120',
            'form.banner_subtitle_en' => 'nullable|string|max:160', 'form.banner_subtitle_ar' => 'nullable|string|max:160',
            'form.schedule' => 'required|in:none,yearly,once', 'form.priority' => 'required|integer|min:1|max:1000', 'form.is_enabled' => 'boolean',
            'form.rs_m' => 'required|integer|between:1,12', 'form.rs_d' => 'required|integer|between:1,31', 'form.rs_t' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'form.re_m' => 'required|integer|between:1,12', 'form.re_d' => 'required|integer|between:1,31', 'form.re_t' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'form.starts_at' => 'nullable|date|required_if:form.schedule,once', 'form.ends_at' => 'nullable|date|after:form.starts_at|required_if:form.schedule,once',
            'heroImage' => self::imageRule(6144), 'bannerImage' => self::imageRule(6144), 'logoImage' => self::imageRule(2048), 'splashImage' => self::imageRule(6144),
        ];
        foreach (BrandTheme::COLOR_KEYS as $k) {
            $rules["form.colors.$k"] = $hex;
        }
        $data = $this->validate($rules, [], ['form.colors.primary' => __('theme_admin.color_primary')])['form'];
        // A yearly day must exist (no 31 February); 29 February is accepted (it simply never matches in other years).
        if ($data['schedule'] === 'yearly') {
            foreach (['rs' => 'rs_d', 're' => 're_d'] as $p => $dk) {
                if ((int) $data[$dk] > cal_days_in_month(CAL_GREGORIAN, (int) $data[$p.'_m'], 2024)) {
                    $this->addError('form.'.$dk, __('theme_admin.bad_day'));

                    return;
                }
            }
        }
        $md = fn (string $m, string $d) => sprintf('%02d-%02d', (int) $m, (int) $d);
        $t = $this->editingId ? BrandTheme::findOrFail($this->editingId) : new BrandTheme(['key' => Str::slug($data['name_en']).'-'.Str::lower(Str::random(4)), 'kind' => 'custom']);
        $instant = fn ($v) => $v ? CarbonImmutable::parse($v, BusinessTime::zone())->utc() : null;
        $t->fill([
            'name_en' => $data['name_en'], 'name_ar' => $data['name_ar'], 'colors' => collect(BrandTheme::COLOR_KEYS)->mapWithKeys(fn ($k) => [$k => strtoupper($data['colors'][$k])])->all(),
            'banner_title_en' => $data['banner_title_en'] ?: null, 'banner_title_ar' => $data['banner_title_ar'] ?: null,
            'banner_subtitle_en' => $data['banner_subtitle_en'] ?: null, 'banner_subtitle_ar' => $data['banner_subtitle_ar'] ?: null,
            'recur_start' => $data['schedule'] === 'yearly' ? $md($data['rs_m'], $data['rs_d']) : null, 'recur_end' => $data['schedule'] === 'yearly' ? $md($data['re_m'], $data['re_d']) : null,
            'recur_start_time' => $data['schedule'] === 'yearly' ? $data['rs_t'] : '00:00', 'recur_end_time' => $data['schedule'] === 'yearly' ? $data['re_t'] : '23:59',
            'starts_at' => $data['schedule'] === 'once' ? $instant($data['starts_at']) : null, 'ends_at' => $data['schedule'] === 'once' ? $instant($data['ends_at']) : null,
            'priority' => $data['priority'], 'is_enabled' => (bool) $data['is_enabled'],
        ]);
        foreach (['hero' => 'heroImage', 'banner' => 'bannerImage', 'logo' => 'logoImage', 'splash' => 'splashImage'] as $col => $prop) {
            if ($this->{$prop}) {
                $this->deleteMedia($t->{$col.'_image_path'} ?? $t->{$col.'_path'});
                $field = $col === 'logo' ? 'logo_path' : $col.'_image_path';
                $t->{$field} = $this->storeMedia($this->{$prop}, 'themes');
            }
        }
        $isNew = ! $t->exists;
        $t->save();
        $themes->forget();
        $audit->log($isNew ? 'cms.theme_created' : 'cms.theme_updated', $t, [], ['key' => $t->key, 'name' => $t->name_en, 'colors' => $t->colors]);
        $this->showEditor = false;
        $this->toast(__('admin.saved'));
    }

    /** Adds one of Qatar's ready-made event looks that is not in the list (never added, or deleted). It starts switched off. */
    public function addPreset(string $key, ThemeService $themes, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.create'), 403);
        if ($t = $themes->addPreset($key)) {
            $audit->log('cms.theme_created', $t, [], ['key' => $t->key, 'preset' => true]);
            $this->toast(__('theme_admin.preset_added', ['name' => $t->name_en]));
        }
    }

    public function activate(string $id, ThemeService $themes, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $t = BrandTheme::findOrFail($id);
        $themes->force($t);
        $audit->log('cms.theme_activated', $t, [], ['key' => $t->key]);
        $this->toast(__('theme_admin.activated', ['name' => $t->name_en]));
    }

    public function backToSchedule(ThemeService $themes, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $themes->force(null);
        $audit->log('cms.theme_schedule_resumed', 'BrandTheme', []);
        $this->toast(__('theme_admin.schedule_resumed'));
    }

    public function makeDefault(string $id, ThemeService $themes, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $t = BrandTheme::findOrFail($id);
        $themes->makeDefault($t);
        $audit->log('cms.theme_default_set', $t, [], ['key' => $t->key]);
        $this->toast(__('admin.saved'));
    }

    public function toggle(string $id, ThemeService $themes): void
    {
        abort_unless($this->can('cms.edit'), 403);
        $t = BrandTheme::findOrFail($id);
        abort_if($t->is_default, 422);
        $t->update(['is_enabled' => ! $t->is_enabled]);
        $themes->forget();
    }

    public function delete(string $id, ThemeService $themes, AuditLogger $audit): void
    {
        abort_unless($this->can('cms.delete'), 403);
        $t = BrandTheme::findOrFail($id);
        abort_if($t->is_default || $t->is_forced || $t->kind === 'base', 422);
        foreach (['hero_image_path', 'banner_image_path', 'logo_path', 'splash_image_path'] as $f) {
            $this->deleteMedia($t->{$f});
        }
        $audit->log('cms.theme_deleted', 'BrandTheme', ['id' => $id, 'key' => $t->key]);
        $t->delete();
        $themes->forget();
        $this->toast(__('admin.deleted'));
    }

    public function render(ThemeService $themes)
    {
        $active = $themes->active();
        $forced = BrandTheme::where('is_forced', true)->exists();

        $now = CarbonImmutable::now();
        $themeList = BrandTheme::orderByDesc('is_default')->orderBy('kind')->orderByDesc('priority')->get();
        $windows = $themeList->mapWithKeys(fn (BrandTheme $t) => [$t->id => $themes->window($t, $now)])->all();
        $upcoming = $themeList->filter(fn ($t) => $t->is_enabled && ! $t->is_default && ($windows[$t->id] ?? null))->sortBy(fn ($t) => $windows[$t->id]['live'] ? 0 : $windows[$t->id]['start']->timestamp)->take(5)->values();

        return view('livewire.admin.content.themes', [
            'windows' => $windows, 'upcoming' => $upcoming, 'availablePresets' => $themes->availablePresets(),
            'themes' => BrandTheme::orderByDesc('is_default')->orderBy('kind')->orderByDesc('priority')->get(),
            'active' => $active, 'forced' => $forced, 'service' => $themes, 'now' => CarbonImmutable::now(),
            'current' => $this->editingId ? BrandTheme::find($this->editingId) : null,
            'canEdit' => $this->can('cms.edit'), 'canCreate' => $this->can('cms.create'), 'canDelete' => $this->can('cms.delete'),
        ])->title(__('nav.appearance'));
    }
}
