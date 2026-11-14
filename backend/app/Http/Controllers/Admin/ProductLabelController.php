<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Pricing\Services\PriceResolver;
use App\Http\Controllers\Controller;
use App\Support\Code128;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The A4 sheet of barcode labels (product name, price and a Code 128 barcode) for the products picked in the products list.
 * A normal page the browser prints (File > Print, A4, 100%, no margins): nothing is stored and no printer driver is needed.
 */
class ProductLabelController extends Controller
{
    /** Most products one sheet job takes (keeps the link short and the page light). */
    public const MAX_PRODUCTS = 100;

    /** Most copies of each label. */
    public const MAX_COPIES = 20;

    /**
     * Label sizes on an A4 sheet (millimetres). They are the common sticker-sheet sizes (65, 24 and 10 labels per sheet), so the
     * grid lines up with ready-made label paper as well as with plain paper.
     */
    public const LAYOUTS = [
        'small' => ['cols' => 5, 'rows' => 13, 'w' => 38.1, 'h' => 21.2],
        'medium' => ['cols' => 3, 'rows' => 8, 'w' => 63.5, 'h' => 33.9],
        'large' => ['cols' => 2, 'rows' => 5, 'w' => 99.1, 'h' => 57.0],
    ];

    /** Narrowest bar a phone or scanner reads reliably, in millimetres. */
    private const MIN_MODULE_MM = 0.19;

    public function sheet(Request $request, PriceResolver $prices): View
    {
        $opts = $request->validate([
            'ids' => ['required', 'string', 'max:4000'],
            'copies' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_COPIES],
            'layout' => ['nullable', 'in:'.implode(',', array_keys(self::LAYOUTS))],
            'lang' => ['nullable', 'in:auto,en,ar,both'],
            'price' => ['nullable', 'boolean'],
        ]);
        $ids = collect(explode(',', $opts['ids']))->map(fn ($id) => strtolower(trim($id)))->filter()->unique()->values();
        abort_if($ids->isEmpty() || $ids->contains(fn ($id) => ! Str::isUuid($id)), 404);
        abort_if($ids->count() > self::MAX_PRODUCTS, 422, __('catalog_admin.labels_too_many', ['max' => self::MAX_PRODUCTS]));

        $layoutKey = $opts['layout'] ?? 'medium';
        $layout = self::LAYOUTS[$layoutKey];
        $copies = (int) ($opts['copies'] ?? 1);
        $lang = $opts['lang'] ?? 'auto';
        $showPrice = $request->boolean('price', true);

        $products = Product::query()->whereIn('id', $ids)->with(['translations', 'variants' => fn ($v) => $v->where('status', 'active')->with('attributeValues.attribute')])->get()
            ->sortBy(fn (Product $p) => $ids->search($p->id))->values();
        abort_if($products->isEmpty(), 404);

        $innerWidthMm = $layout['w'] - 2 * 2.0; // the label's side padding
        $labels = [];
        foreach ($products as $product) {
            foreach ($product->variants as $variant) {
                $labels[] = $this->label($product, $variant, $prices, $lang, $showPrice, $innerWidthMm);
            }
        }
        $problems = [
            'no_barcode' => collect($labels)->filter(fn ($l) => $l['svg'] === null)->pluck('name')->unique()->values()->all(),
            'cramped' => collect($labels)->filter(fn ($l) => $l['cramped'])->pluck('name')->unique()->values()->all(),
        ];
        $sheeted = collect($labels)->flatMap(fn ($l) => array_fill(0, $copies, $l))->values();
        $perPage = $layout['cols'] * $layout['rows'];

        return view('documents.product-labels', [
            'pages' => $sheeted->chunk($perPage)->map->values()->all(),
            'layout' => $layout + ['key' => $layoutKey, 'per_page' => $perPage,
                'left' => round((210 - $layout['cols'] * $layout['w']) / 2, 2), 'top' => round((297 - $layout['rows'] * $layout['h']) / 2, 2)],
            'options' => ['ids' => $ids->implode(','), 'copies' => $copies, 'layout' => $layoutKey, 'lang' => $lang, 'price' => $showPrice],
            'count' => $sheeted->count(),
            'problems' => $problems,
            'locale' => app()->getLocale(),
        ]);
    }

    /** @return array{name: string, name_other: ?string, variant: ?string, price: ?string, code: string, svg: ?string, cramped: bool} */
    private function label(Product $product, ProductVariant $variant, PriceResolver $prices, string $lang, bool $showPrice, float $innerWidthMm): array
    {
        $variant->setRelation('product', $product);
        $current = app()->getLocale();
        $primaryLocale = in_array($lang, ['en', 'ar'], true) ? $lang : $current;
        $other = $primaryLocale === 'ar' ? 'en' : 'ar';
        $single = $product->variants->count() === 1;
        // The code the label carries is one the app and the shelf scanners resolve to exactly this variant: its own barcode, else (for a
        // product with one variant) the product's barcode, else its SKU.
        $code = (string) ($variant->barcode ?: ($single ? $product->barcode : null) ?: $variant->sku);
        $svg = Code128::supports($code) ? Code128::svg($code, 40) : null;
        $modules = $svg === null ? 0 : strlen(Code128::modules($code)) + 20;

        return [
            'name' => (string) $product->trans('name', $primaryLocale),
            'name_other' => $lang === 'both' ? (string) $product->trans('name', $other) : null,
            'variant' => $single && $variant->attributeValues->isEmpty() ? null : ($variant->displayName($primaryLocale) ?: null),
            'price' => $showPrice ? $prices->price($variant, null)->final()->format($primaryLocale) : null,
            'code' => $code,
            'svg' => $svg,
            'cramped' => $svg !== null && $innerWidthMm / $modules < self::MIN_MODULE_MM,
        ];
    }
}
