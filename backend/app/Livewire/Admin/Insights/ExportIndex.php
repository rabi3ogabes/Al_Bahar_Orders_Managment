<?php

namespace App\Livewire\Admin\Insights;

use App\Domain\Reports\Models\DataExport;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use Illuminate\Support\Facades\URL;
use Livewire\Component;
use Livewire\WithPagination;

/** The viewer's own exports with live status and short-lived signed download links. */
class ExportIndex extends Component
{
    use InteractsWithAdmin, WithPagination;

    public function mount(): void
    {
        abort_unless($this->can('reports.export'), 403);
    }

    public function render()
    {
        $exports = DataExport::where('user_id', auth()->id())->latest()->paginate(20);
        $links = $exports->getCollection()->mapWithKeys(fn ($e) => [$e->id => $e->status === 'completed' && $e->expires_at?->isFuture()
            ? URL::temporarySignedRoute('admin.exports.download', now()->addMinutes(15)->min($e->expires_at), ['export' => $e->id]) : null]);

        return view('livewire.admin.insights.exports', [
            'exports' => $exports,
            'links' => $links,
            'pending' => $exports->getCollection()->contains(fn ($e) => in_array($e->status, ['queued', 'processing'], true)),
        ])->title(__('nav.exports'));
    }
}
