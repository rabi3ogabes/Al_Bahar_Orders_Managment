<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Search\Services\GlobalSearch;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /admin/search?q= : the answer of the dashboard's global search (Ctrl/Cmd+K) as JSON, for the signed-in staff member only.
 * What each group contains is decided by {@see GlobalSearch}: every group by its own permission and the person's branches. The query is
 * never stored or logged, and the answer is private to the person and never cached. Rate-limited by the `admin-search` limiter.
 */
class SearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearch $search): JsonResponse
    {
        $q = $request->query('q');

        return response()->json($search->run($request->user(), is_string($q) ? $q : ''))
            ->header('Cache-Control', 'no-store, private');
    }
}
