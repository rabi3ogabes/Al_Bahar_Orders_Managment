<?php

namespace App\Livewire\Admin\Auth;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Services\TwoFactorService;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.guest')]
class TwoFactorChallenge extends Component
{
    public string $code = '';

    public function mount()
    {
        if (! auth()->check() || ! auth()->user()->hasTwoFactorEnabled()) {
            return redirect()->route('admin.login');
        }
    }

    public function verify(TwoFactorService $twoFactor, AuditLogger $audit)
    {
        $this->validate(['code' => 'required|string|max:32']);
        $user = auth()->user();
        $key = '2fa:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => __('errors.too_many_requests')]);
        }
        if (! $twoFactor->verify($user, $this->code)) {
            RateLimiter::hit($key, 300);
            $audit->log('auth.two_factor_failed', $user, actor: $user);
            throw ValidationException::withMessages(['code' => __('errors.two_factor_invalid')]);
        }
        RateLimiter::clear($key);
        session()->regenerate();
        session(['two_factor_passed' => true]);
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => request()->ip()])->save();
        $audit->log('auth.login', $user, [], [], ['channel' => 'admin_web', 'two_factor' => true], actor: $user);

        return $this->redirectIntended(route('admin.dashboard'), navigate: false);
    }

    public function render()
    {
        return view('livewire.admin.auth.two-factor')->title(__('admin.two_factor'));
    }
}
