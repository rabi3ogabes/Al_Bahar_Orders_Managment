<?php

namespace App\Livewire\Admin\Catalog;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Catalog\Models\CatalogImport;
use App\Domain\Catalog\Services\CatalogImportService;
use App\Domain\Identity\Models\User;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\BusinessTime;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/** Bulk catalog import (CSV/XLSX, row-level error report) and full catalog export. */
class ImportIndex extends Component
{
    use InteractsWithAdmin, WithFileUploads, WithPagination;

    public $file = null;

    #[Url(as: 'import', except: null)]
    public ?string $selected = null;

    public function mount(): void
    {
        abort_unless($this->can('catalog.create'), 403);
    }

    public function import(CatalogImportService $imports): void
    {
        abort_unless($this->canGlobally('catalog.create') && $this->canGlobally('catalog.edit'), 403);
        $this->validate(['file' => 'required|file|mimes:csv,txt,xlsx|max:20480'], [], ['file' => __('catalog_admin.import_file')]);
        $path = $this->file->store('imports', 'local');
        $import = CatalogImport::create(['user_id' => auth()->id(), 'disk' => 'local', 'path' => $path, 'original_name' => $this->file->getClientOriginalName()]);
        $import = $imports->process($import);
        $this->reset('file');
        $this->selected = $import->id;
        $this->resetPage();
        $this->toast(__('catalog_admin.import_done', ['created' => $import->created_rows, 'updated' => $import->updated_rows, 'failed' => $import->failed_rows]),
            $import->status === 'failed' ? 'error' : ($import->failed_rows ? 'warning' : 'success'));
    }

    public function template()
    {
        $example = ['OSLO-3S-OAK', 'Oslo three-seater sofa', 'كنبة أوسلو ثلاثية', '4250.00', '3990.00', 'draft', 'sofas', '', '6291234567890',
            'Soft linen, solid oak legs', 'كتان ناعم وأرجل من خشب البلوط', '', '', '68', '220', '95', '85', '1', '1', '0', '1', '1', 'linen,oak'];

        return response()->streamDownload(function () use ($example) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, CatalogImportService::COLUMNS);
            fputcsv($out, $example);
            fclose($out);
        }, 'albahar-catalog-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function export(CatalogImportService $imports, AuditLogger $audit)
    {
        abort_unless($this->canGlobally('catalog.export'), 403);
        $tmp = tempnam(sys_get_temp_dir(), 'catalog').'.xlsx';
        $count = $imports->export($tmp);
        $audit->log('catalog.exported', 'Product', [], [], ['rows' => $count]);

        return response()->download($tmp, 'albahar-catalog-'.BusinessTime::now()->format('Ymd-His').'.xlsx')->deleteFileAfterSend();
    }

    public function errorReport(string $id)
    {
        $import = CatalogImport::findOrFail($id);

        return response()->streamDownload(function () use ($import) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['row', 'sku', 'field', 'message']);
            foreach ($import->errors ?? [] as $e) {
                fputcsv($out, [$e['row'] ?? '', $e['sku'] ?? '', $e['field'] ?? '', $e['message'] ?? '']);
            }
            fclose($out);
        }, 'import-errors-'.substr($import->id, 0, 8).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render()
    {
        $imports = CatalogImport::query()->latest()->paginate(10);
        $users = User::whereIn('id', $imports->pluck('user_id')->filter()->unique())->pluck('name', 'id');
        $current = $this->selected ? CatalogImport::find($this->selected) : null;

        return view('livewire.admin.catalog.imports', [
            'imports' => $imports,
            'users' => $users,
            'current' => $current,
            'columns' => CatalogImportService::COLUMNS,
            'canImport' => $this->canGlobally('catalog.create') && $this->canGlobally('catalog.edit'),
            'canExport' => $this->canGlobally('catalog.export'),
        ])->title(__('nav.imports'));
    }
}
