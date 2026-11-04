<?php

namespace App\Livewire\Admin\Auth;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.guest')]
class Login extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function login(AuditLogger $audit)
    {
        $this->validate();
        $key = 'admin-login:'.Str::lower($this->email).'|'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)])]);
        }

        $user = User::whereRaw('lower(email) = ?', [Str::lower($this->email)])->where('type', 'staff')->first();
        if (! $user || ! $user->password || ! Hash::check($this->password, $user->password) || ! $user->isActive()) {
            RateLimiter::hit($key, 300);
            $audit->log('auth.login_failed', 'User', [], [], ['channel' => 'admin_web', 'user_id' => $user?->id]);
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }
        RateLimiter::clear($key);

        Auth::guard('web')->login($user, $this->remember);
        session()->regenerate();
        session(['locale' => $user->locale]);

        if ($user->hasTwoFactorEnabled()) {
            session(['two_factor_passed' => false]);

            return $this->redirectRoute('admin.two-factor.challenge', navigate: false);
        }

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => request()->ip()])->save();
        $audit->log('auth.login', $user, [], [], ['channel' => 'admin_web'], actor: $user);

        return $this->redirectIntended(route('admin.dashboard'), navigate: false);
    }

    public function render()
    {
        return view('livewire.admin.auth.login')->title(__('admin.sign_in'));
    }
}
