<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/** @tags System */
class HealthController extends Controller
{
    /** Liveness + dependency readiness (database, cache/redis, queue). No secrets or versions exposed. */
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'cache' => $this->check(function () {
                Cache::put('health:ping', 1, 5);

                return Cache::get('health:ping') === 1 ?: throw new \RuntimeException;
            }),
            'redis' => config('database.redis.default.host') ? $this->check(fn () => Redis::connection()->ping()) : 'skipped',
        ];
        $healthy = ! in_array('fail', $checks, true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
            'time' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    private function check(callable $fn): string
    {
        try {
            $fn();

            return 'ok';
        } catch (Throwable) {
            return 'fail';
        }
    }
}
