<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\AppUpdates\Models\AppRelease;
use App\Domain\AppUpdates\Services\AppUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** @tags App updates */
class AppUpdateController extends ApiController
{
    /** Public: the store apps ask at start-up and when they come back to the foreground. */
    public function show(Request $request, AppUpdateService $updates): JsonResponse
    {
        $d = $request->validate(['app' => 'required|in:'.implode(',', AppRelease::APPS), 'platform' => 'required|in:'.implode(',', AppRelease::PLATFORMS), 'build' => 'required|integer|min:0|max:100000000']);

        return response()->json(['data' => $updates->evaluate($d['app'], $d['platform'], (int) $d['build'])])->header('Cache-Control', 'no-store');
    }
}
