<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Services\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SessionController extends Controller
{
    public function logout(Request $request, AuditLogger $audit): RedirectResponse
    {
        if ($user = $request->user()) {
            $audit->log('auth.logout', $user, [], [], ['channel' => 'admin_web']);
        }
        Auth::guard('web')->logout();
        $locale = $request->session()->get('locale');
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('locale', $locale);

        return redirect()->route('admin.login');
    }

    public function locale(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, config('platform.supported_locales'), true), 404);
        $request->session()->put('locale', $locale);
        $request->user()?->forceFill(['locale' => $locale])->save();

        return redirect()->back(fallback: route('admin.dashboard'));
    }
}
