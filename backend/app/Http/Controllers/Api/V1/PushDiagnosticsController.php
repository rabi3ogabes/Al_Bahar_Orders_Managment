<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Notifications\Services\PushDiagnostics;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A phone app tells the server why push does not work on it (or that it does). Public on purpose: the phone has to be able to say it
 * before anybody has signed in, and a phone that cannot start Firebase has no other way to reach us. So it is strict and small: only
 * these fields, short values, a machine code from a fixed alphabet, a scrubbed message, no token, no key, no person. Rate limited per
 * address and per install. The answer is always 202 and says nothing about what was stored.
 *
 * @tags Push
 */
class PushDiagnosticsController extends Controller
{
    public function store(Request $request, PushDiagnostics $diagnostics): JsonResponse
    {
        $data = $request->validate([
            'app' => ['required', 'string', 'in:'.implode(',', PushDiagnostics::APPS)],
            'platform' => ['required', 'string', 'in:'.implode(',', PushDiagnostics::PLATFORMS)],
            'stage' => ['required', 'string', 'in:'.implode(',', PushDiagnostics::STAGES)],
            'code' => ['required', 'string', 'regex:'.PushDiagnostics::CODE_PATTERN],
            'message' => ['nullable', 'string', 'max:'.PushDiagnostics::MESSAGE_MAX],
            'app_version' => ['nullable', 'string', 'max:32', 'regex:/^[0-9A-Za-z.+_-]+$/'],
            'os_version' => ['nullable', 'string', 'max:64'],
            'install_id' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/'],
        ]);
        $diagnostics->record($data);

        return response()->json(['data' => ['accepted' => true]], 202);
    }
}
