<?php

namespace App\Livewire\Admin\Organization;

use App\Domain\AppUpdates\Models\AppRelease;
use App\Domain\AppUpdates\Services\AppUpdateService;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** App updates: per app and platform, the newest build, the store link and "force everyone to update". */
class AppUpdatesPage extends Component
{
    use InteractsWithAdmin;

    /** release id => form */
    public array $forms = [];

    #[Locked]
    public ?string $confirmForce = null;

    public function mount(): void
    {
        abort_unless($this->canGlobally('settings.view'), 403);
        $this->load();
    }

    private function load(): void
    {
        $this->forms = AppRelease::all()->mapWithKeys(fn (AppRelease $r) => [$r->id => [
            'latest_version' => (string) $r->latest_version, 'latest_build' => (string) $r->latest_build, 'min_build' => (string) $r->min_build,
            'store_url' => (string) $r->store_url, 'message_en' => (string) $r->message_en, 'message_ar' => (string) $r->message_ar,
        ]])->all();
    }

    public function save(string $id, AppUpdateService $svc): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        $r = AppRelease::findOrFail($id);
        $f = "forms.$id";
        $this->validate([
            "$f.latest_version" => 'nullable|string|max:24', "$f.latest_build" => 'required|integer|min:0|max:100000000', "$f.min_build" => 'required|integer|min:0|max:100000000',
            "$f.store_url" => 'nullable|url:https|max:500', "$f.message_en" => 'nullable|string|max:400', "$f.message_ar" => 'nullable|string|max:400',
        ], [], ["$f.latest_build" => __('app_updates_admin.latest_build'), "$f.min_build" => __('app_updates_admin.min_build'), "$f.store_url" => __('app_updates_admin.store_url')]);
        $m = $this->forms[$id];
        $this->attempt(fn () => $svc->save($r, ['latest_version' => $m['latest_version'], 'latest_build' => (int) $m['latest_build'], 'min_build' => (int) $m['min_build'],
            'store_url' => $m['store_url'], 'message_en' => $m['message_en'], 'message_ar' => $m['message_ar']], auth()->user()), __('app_updates_admin.saved'));
        $this->load();
    }

    public function askForce(string $id): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        $this->save($id, app(AppUpdateService::class)); // keep what was typed, then confirm
        $this->confirmForce = AppRelease::findOrFail($id)->id;
    }

    public function force(AppUpdateService $svc): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        $r = AppRelease::findOrFail($this->confirmForce);
        $this->attempt(fn () => $svc->forceNow($r, auth()->user()), __('app_updates_admin.forced'));
        $this->confirmForce = null;
        $this->load();
    }

    public function stop(string $id, AppUpdateService $svc): void
    {
        abort_unless($this->canGlobally('settings.edit'), 403);
        $this->attempt(fn () => $svc->stopForcing(AppRelease::findOrFail($id), auth()->user()), __('app_updates_admin.stopped'));
        $this->load();
    }

    public function render()
    {
        return view('livewire.admin.organization.app-updates', [
            'releases' => AppRelease::orderByRaw("CASE app WHEN 'customer' THEN 0 WHEN 'driver' THEN 1 ELSE 2 END")->orderBy('platform')->get()->groupBy('app'),
            'forcing' => $this->confirmForce ? AppRelease::find($this->confirmForce) : null,
            'canEdit' => $this->canGlobally('settings.edit'),
        ])->title(__('app_updates_admin.title'));
    }
}
