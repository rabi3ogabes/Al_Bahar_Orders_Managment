<?php

namespace App\Livewire\Admin\Auth;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Operations\Services\WallDisplaySession;
use App\Support\Phone;
use App\Support\StaffLanding;
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
    /** E-mail address or mobile number: a branch employee may have a number and no address. */
    #[Validate('required|string|max:191')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function login(AuditLogger $audit)
    {
        $this->validate();
        $identifier = trim($this->email);
        // One bucket per person however the number is typed ("+974 5555 1234", "55551234", "0097455551234", Arabic digits): the same identity as
        // the API limiter. A second bucket per address of the caller stops one machine guessing across many accounts.
        $identity = str_contains($identifier, '@') ? mb_strtolower($identifier) : (Phone::normalize($identifier) ?? mb_strtolower($identifier));
        $keys = ['admin-login:'.$identity.'|'.request()->ip() => 5, 'admin-login-ip:'.request()->ip() => 20];
        foreach ($keys as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages(['email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)])]);
            }
        }

        $user = User::where('type', 'staff')->when(
            str_contains($identifier, '@'),
            fn ($q) => $q->whereRaw('lower(email) = ?', [Str::lower($identifier)]),
            fn ($q) => $q->where('phone', Phone::normalize($identifier) ?? '__invalid__'),
        )->first();
        // Always one password check, even for an unknown identifier (against a well-formed hash nobody knows the password of), so the answer
        // takes as long either way.
        $valid = Hash::check($this->password, $user?->password ?: '$2y$12$'.str_repeat('a', 53));
        if (! $user || ! $user->password || ! $valid || ! $user->isActive()) {
            foreach ($keys as $key => $max) {
                RateLimiter::hit($key, 300);
            }
            $audit->log('auth.login_failed', 'User', [], [], ['channel' => 'admin_web', 'user_id' => $user?->id]);
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }
        RateLimiter::clear('admin-login:'.$identity.'|'.request()->ip());

        // A wall display screen signs in once and stays signed in for the shift, so it is always remembered (it signs out by its own button or after its idle time),
        // and its shift starts now.
        $wall = app(AccessControl::class)->isWallDisplay($user);
        if ($wall) {
            app(WallDisplaySession::class)->forget($user);
        }
        Auth::guard('web')->login($user, $this->remember || $wall);
        session()->regenerate();
        session(['locale' => $user->locale, 'session_stamp' => (string) $user->session_stamp]);

        if ($user->hasTwoFactorEnabled()) {
            session(['two_factor_passed' => false]);

            return $this->redirectRoute('admin.two-factor.challenge', navigate: false);
        }

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => request()->ip()])->save();
        $audit->log('auth.login', $user, [], [], ['channel' => 'admin_web'], actor: $user);

        return $this->redirectIntended(StaffLanding::url($user), navigate: false);
    }

    public function render()
    {
        return view('livewire.admin.auth.login')->title(__('admin.sign_in'));
    }
}
