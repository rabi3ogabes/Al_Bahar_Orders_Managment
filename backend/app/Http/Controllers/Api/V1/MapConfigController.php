<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\MapConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public map configuration for the web store and the mobile apps: tile URLs, credit line, default view. Cached five
 * minutes at every layer (the server drops its copy when a map setting is saved). See docs/guides/MAPS.md.
 *
 * @tags Maps
 */
class MapConfigController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $response = response()->json(['data' => MapConfig::payload()])->setPublic()->setMaxAge(300);
        $response->setEtag(md5((string) $response->getContent()));
        $response->isNotModified($request); // turns the response into an empty 304 when the client already holds this version

        return $response;
    }
}
