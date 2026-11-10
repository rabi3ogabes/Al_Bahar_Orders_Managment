<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\CatalogImport;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Support\DomainException;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Bulk product import/export (CSV or XLSX). Each row is validated and applied in its own savepoint so one bad
 * row never blocks the rest; a per-row error report is stored on the import record.
 *
 * Columns: sku*, name_en*, name_ar*, price*, sale_price, status, category_slug, brand_slug, barcode,
 *          short_description_en, short_description_ar, description_en, description_ar, weight, length, width, height,
 *          is_featured, is_new_arrival, is_bestseller, requires_assembly, is_oversized, tags (comma separated)
 */
class CatalogImportService
{
    public const COLUMNS = ['sku', 'name_en', 'name_ar', 'price', 'sale_price', 'status', 'category_slug', 'brand_slug', 'barcode',
        'short_description_en', 'short_description_ar', 'description_en', 'description_ar', 'weight', 'length', 'width', 'height',
        'is_featured', 'is_new_arrival', 'is_bestseller', 'requires_assembly', 'is_oversized', 'tags'];

    public function __construct(private readonly ProductService $products) {}

    public function process(CatalogImport $import): CatalogImport
    {
        $import->update(['status' => 'processing']);
        $path = Storage::disk($import->disk)->path($import->path);
        $reader = str_ends_with(strtolower($import->original_name), '.xlsx') ? new XlsxReader : new CsvReader;
        $errors = [];
        $created = $updated = $failed = $total = 0;
        $categories = Category::pluck('id', 'slug');
        $brands = Brand::pluck('id', 'slug');

        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                $header = null;
                foreach ($sheet->getRowIterator() as $i => $row) {
                    $cells = array_map(fn ($c) => is_string($c) ? trim($c) : $c, $row->toArray());
                    if ($header === null) {
                        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $cells);
                        $missing = array_diff(['sku', 'name_en', 'name_ar', 'price'], $header);
                        if ($missing) {
                            throw new DomainException('import_missing_columns', 'Missing required columns: '.implode(', ', $missing));
                        }

                        continue;
                    }
                    if (array_filter($cells, fn ($c) => $c !== null && $c !== '') === []) {
                        continue;
                    }
                    $total++;
                    $data = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), null));
                    $data = array_merge(array_fill_keys(self::COLUMNS, null), array_intersect_key($data, array_flip(self::COLUMNS)));
                    $rowErrors = $this->validateRow($data, $categories, $brands);
                    if ($rowErrors) {
                        $failed++;
                        foreach ($rowErrors as $field => $msgs) {
                            $errors[] = ['row' => $i, 'sku' => $data['sku'] ?? null, 'field' => $field, 'message' => $msgs[0]];
                        }

                        continue;
                    }
                    try {
                        DB::transaction(function () use ($data, $categories, $brands, &$created, &$updated) {
                            $payload = $this->payload($data, $categories, $brands);
                            if ($existing = Product::where('sku', $data['sku'])->first()) {
                                $this->products->update($existing, $payload, 'import');
                                $updated++;
                            } else {
                                $this->products->create($payload);
                                $created++;
                            }
                        });
                    } catch (\Throwable $e) {
                        if (! $e instanceof DomainException) {
                            report($e); // surfaced to logs/monitoring; the admin sees a generic row error
                        }
                        $failed++;
                        $errors[] = ['row' => $i, 'sku' => $data['sku'] ?? null, 'field' => null,
                            'message' => $e instanceof DomainException ? $e->getMessage() : 'Unexpected error'];
                    }
                }
                break; // first sheet only
            }
            $reader->close();
            $status = 'completed';
        } catch (DomainException $e) {
            $errors[] = ['row' => 1, 'sku' => null, 'field' => null, 'message' => $e->getMessage()];
            $status = 'failed';
        }

        $import->update(['status' => $status, 'total_rows' => $total, 'created_rows' => $created, 'updated_rows' => $updated,
            'failed_rows' => $failed, 'errors' => array_slice($errors, 0, 1000), 'completed_at' => now()]);
        app(AuditLogger::class)->log('catalog.import_processed', $import, [], ['created' => $created, 'updated' => $updated, 'failed' => $failed]);

        return $import;
    }

    /** Write an XLSX export of the catalog to the given absolute path. */
    public function export(string $absolutePath, ?callable $filter = null): int
    {
        $writer = new XlsxWriter;
        $writer->openToFile($absolutePath);
        $writer->addRow(Row::fromValues(self::COLUMNS));
        $count = 0;
        $q = Product::with(['translations', 'category', 'brand']);
        if ($filter) {
            $filter($q);
        }
        $q->chunkById(500, function ($chunk) use ($writer, &$count) {
            foreach ($chunk as $p) {
                $en = $p->translation('en');
                $ar = $p->translation('ar');
                $writer->addRow(Row::fromValues([$p->sku, $en?->getAttribute('name'), $ar?->getAttribute('name'), $p->price, $p->sale_price, $p->status, $p->category?->slug,
                    $p->brand?->slug, $p->barcode, $en?->getAttribute('short_description'), $ar?->getAttribute('short_description'), $en?->getAttribute('description'), $ar?->getAttribute('description'),
                    $p->weight, $p->length, $p->width, $p->height, (int) $p->is_featured, (int) $p->is_new_arrival, (int) $p->is_bestseller,
                    (int) $p->requires_assembly, (int) $p->is_oversized, implode(',', $p->tags ?? [])]));
                $count++;
            }
        });
        $writer->close();

        return $count;
    }

    private function validateRow(array $d, $categories, $brands): array
    {
        $v = Validator::make($d, [
            'sku' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-_.]+$/'],
            'name_en' => ['required', 'string', 'max:255'],
            'name_ar' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'sale_price' => ['nullable', 'numeric', 'min:0', 'lte:price'],
            'status' => ['nullable', 'in:draft,active,inactive,archived'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'length' => ['nullable', 'numeric', 'min:0'], 'width' => ['nullable', 'numeric', 'min:0'], 'height' => ['nullable', 'numeric', 'min:0'],
        ]);
        $v->after(function ($v) use ($d, $categories, $brands) {
            if (! empty($d['category_slug']) && ! isset($categories[$d['category_slug']])) {
                $v->errors()->add('category_slug', 'Unknown category slug.');
            }
            if (! empty($d['brand_slug']) && ! isset($brands[$d['brand_slug']])) {
                $v->errors()->add('brand_slug', 'Unknown brand slug.');
            }
        });

        return $v->fails() ? $v->errors()->toArray() : [];
    }

    private function payload(array $d, $categories, $brands): array
    {
        $bool = fn ($v) => in_array(strtolower((string) $v), ['1', 'true', 'yes', 'y'], true);
        $num = fn ($v) => ($v === null || $v === '') ? null : (string) $v;

        return [
            'sku' => $d['sku'],
            'barcode' => $d['barcode'] ?: null,
            'price' => Money::of((string) $d['price'])->toDecimal(),
            'sale_price' => $d['sale_price'] !== null && $d['sale_price'] !== '' ? Money::of((string) $d['sale_price'])->toDecimal() : null,
            'status' => $d['status'] ?: 'draft',
            'category_id' => $categories[$d['category_slug'] ?? ''] ?? null,
            'brand_id' => $brands[$d['brand_slug'] ?? ''] ?? null,
            'weight' => $num($d['weight'] ?? null), 'length' => $num($d['length'] ?? null), 'width' => $num($d['width'] ?? null), 'height' => $num($d['height'] ?? null),
            'is_featured' => $bool($d['is_featured'] ?? 0), 'is_new_arrival' => $bool($d['is_new_arrival'] ?? 0), 'is_bestseller' => $bool($d['is_bestseller'] ?? 0),
            'requires_assembly' => $bool($d['requires_assembly'] ?? 0), 'is_oversized' => $bool($d['is_oversized'] ?? 0),
            'tags' => ! empty($d['tags']) ? array_values(array_filter(array_map('trim', explode(',', $d['tags'])))) : null,
            'translations' => [
                'en' => ['name' => $d['name_en'], 'short_description' => $d['short_description_en'] ?? null, 'description' => $d['description_en'] ?? null],
                'ar' => ['name' => $d['name_ar'], 'short_description' => $d['short_description_ar'] ?? null, 'description' => $d['description_ar'] ?? null],
            ],
        ];
    }
}
