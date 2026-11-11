<?php

namespace App\Http\Controllers\Api\V1\Cms;

use App\Domain\Cms\Services\ThemeService;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;

/** @tags Appearance */
class ThemeController extends ApiController
{
    /** The look to show right now (colours, artwork, seasonal banner). Public and cached for a minute. */
    public function show(ThemeService $themes): JsonResponse
    {
        return response()->json(['data' => $themes->payload()])->header('Cache-Control', 'public, max-age=60');
    }
}
