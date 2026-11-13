<?php

namespace App\Http\Controllers\Api\V1\Cms;

use App\Domain\Cms\Services\ThemeService;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** @tags Appearance */
class ThemeController extends ApiController
{
    /** The look to show right now (colours, artwork, seasonal banner) plus the design (layout and tokens). Public and cached for a minute. */
    public function show(Request $request, ThemeService $themes): JsonResponse
    {
        // ?design=<key> shows a base design as if it were live (read-only, for the admin "Preview store" button).
        $design = $request->query('design');
        $preview = is_string($design) && $design !== '' ? $themes->previewPayload($design) : null;

        return response()->json(['data' => $preview ?? $themes->payload()])->header('Cache-Control', 'public, max-age=60');
    }
}
