<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductPhotoRequest;
use App\Domain\Catalog\Models\ProductPhotoResult;
use App\Domain\Catalog\Services\ProductAi;
use App\Domain\Catalog\Services\ProductPhotoStudio;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** @tags Catalog photo studio */
class PhotoStudioController extends ApiController
{
    public function __construct(private readonly ProductPhotoStudio $studio) {}

    /** Description from the title (EN + AR). Returns text only; the caller decides whether to save it. */
    public function describe(Request $request, ProductAi $ai): JsonResponse
    {
        $d = $request->validate(['title_en' => 'nullable|string|max:255', 'title_ar' => 'nullable|string|max:255', 'category_id' => 'nullable|uuid', 'material' => 'nullable|string|max:255']);
        $category = ! empty($d['category_id']) ? Category::find($d['category_id'])?->trans('name', 'en') : null;

        return response()->json(['data' => $ai->describe($d['title_en'] ?? null, $d['title_ar'] ?? null, $category, $d['material'] ?? null)]);
    }

    public function aiStatus(ProductAi $ai): JsonResponse
    {
        return response()->json(['data' => ['description_ready' => $ai->configured()]]);
    }

    public function requestPhoto(Request $request, Product $product): JsonResponse
    {
        $d = $request->validate(['image_id' => 'nullable|uuid', 'kind' => 'required|in:'.implode(',', ProductPhotoRequest::KINDS), 'count' => 'nullable|integer|min:1|max:6', 'notes' => 'nullable|string|max:1000']);
        $source = ! empty($d['image_id']) ? ProductImage::where('product_id', $product->id)->findOrFail($d['image_id']) : null;
        $r = $this->studio->request($product, $source, $d['kind'], (int) ($d['count'] ?? 1), $d['notes'] ?? null, $this->user());

        return response()->json(['data' => $this->present($r->load('source'))], 201);
    }

    /** The queue (for the studio editor) or one product's requests. */
    public function index(Request $request): JsonResponse
    {
        $d = $request->validate(['status' => 'nullable|in:requested,processing,ready,failed,cancelled', 'product_id' => 'nullable|uuid']);
        $rows = ProductPhotoRequest::with(['source', 'product', 'results'])->when($d['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($d['product_id'] ?? null, fn ($q, $p) => $q->where('product_id', $p))->orderBy('created_at')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn ($r) => $this->present($r))->values()]);
    }

    public function claim(ProductPhotoRequest $photoRequest): JsonResponse
    {
        return response()->json(['data' => $this->present($this->studio->claim($photoRequest)->load(['source', 'product', 'results']))]);
    }

    public function addResults(Request $request, ProductPhotoRequest $photoRequest): JsonResponse
    {
        $request->validate(['images' => 'required|array|min:1|max:6', 'images.*' => 'required|file|max:'.config('platform.media.max_image_kb')]);
        foreach ($request->file('images') as $file) {
            $this->studio->addResult($photoRequest, $file);
        }

        return response()->json(['data' => $this->present($photoRequest->fresh(['source', 'product', 'results']))], 201);
    }

    public function fail(Request $request, ProductPhotoRequest $photoRequest): JsonResponse
    {
        return response()->json(['data' => $this->present($this->studio->fail($photoRequest, $request->validate(['note' => 'nullable|string|max:500'])['note'] ?? null))]);
    }

    public function cancel(ProductPhotoRequest $photoRequest): JsonResponse
    {
        return response()->json(['data' => $this->present($this->studio->cancel($photoRequest))]);
    }

    public function useResult(Request $request, ProductPhotoResult $result): JsonResponse
    {
        $image = $this->studio->use($result, $this->user(), (bool) $request->boolean('as_cover'));

        return response()->json(['data' => ['image_id' => $image->id, 'url' => $image->url(), 'thumb_url' => $image->url(640)]]);
    }

    public function discardResult(ProductPhotoResult $result): JsonResponse
    {
        $this->studio->discard($result);

        return response()->json(['data' => ['discarded' => true]]);
    }

    /** @return array<string, mixed> */
    private function present(ProductPhotoRequest $r): array
    {
        return [
            'id' => $r->id, 'product_id' => $r->product_id, 'product' => $r->relationLoaded('product') ? ['sku' => $r->product->sku, 'name_en' => $r->product->trans('name', 'en'), 'name_ar' => $r->product->trans('name', 'ar')] : null,
            'kind' => $r->kind, 'count' => $r->count, 'notes' => $r->notes, 'status' => $r->status, 'result_note' => $r->result_note,
            'source' => $r->relationLoaded('source') && $r->source ? ['id' => $r->source->id, 'url' => $r->source->url(), 'thumb_url' => $r->source->url(640)] : null,
            'results' => $r->relationLoaded('results') ? $r->results->map(fn ($x) => ['id' => $x->id, 'url' => $x->url(), 'decision' => $x->decision])->values() : [],
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
