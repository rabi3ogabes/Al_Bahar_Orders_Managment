<?php

namespace App\Livewire\Admin;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\TokenService;
use App\Domain\Identity\Services\TwoFactorService;
use App\Support\StaffLanding;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

class Profile extends Component
{
    public string $name = '';

    public string $locale = 'en';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public ?string $pendingSecret = null;

    public string $confirmCode = '';

    public array $recoveryCodes = [];

    public function mount(): void
    {
        $this->name = auth()->user()->name;
        $this->locale = auth()->user()->locale;
    }

    public function saveProfile(AuditLogger $audit): void
    {
        $data = $this->validate(['name' => 'required|string|min:2|max:120', 'locale' => 'required|in:en,ar']);
        $user = auth()->user();
        $user->fill($data)->save();
        $audit->logChanges('staff.profile_updated', $user);
        session(['locale' => $data['locale']]);
        $this->redirectRoute('admin.profile', navigate: true);
    }

    /** Failed "current password" checks (change password, turn two-factor off) share one budget per person, like the two-factor code. */
    private function currentPasswordWrong(User $user): bool
    {
        $key = 'profile-current-password:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('current_password', __('errors.too_many_requests'));

            return true;
        }
        if (! Hash::check($this->current_password, (string) $user->password)) {
            RateLimiter::hit($key, 300);
            $this->addError('current_password', __('auth.password'));

            return true;
        }
        RateLimiter::clear($key);

        return false;
    }

    public function changePassword(AuditLogger $audit, TokenService $tokens)
    {
        $this->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(12)->mixedCase()->numbers()->uncompromised(0)],
        ]);
        $user = auth()->user();
        if ($this->currentPasswordWrong($user)) {
            return;
        }
        $wasForced = $user->must_change_password;
        $user->forceFill(['password' => $this->password, 'must_change_password' => false])->save();
        // Whoever else knew the old password (a temporary one an administrator typed, a stolen session) is out: every app session and refresh
        // token, every other browser session (new stamp, see EnsureStaff) and the remember-me cookie. This browser stays signed in.
        $tokens->revokeAll($user, 'password_changed');
        session(['session_stamp' => $tokens->forgetWebSessions($user, session()->getId())]);
        $audit->log('staff.password_changed', $user);
        $this->reset('current_password', 'password', 'password_confirmation');
        if ($wasForced) {
            // The first sign-in is done: on to the dashboard (or the first page of their own menu).
            session()->flash('toast', ['type' => 'success', 'message' => __('admin.password_updated')]);

            return $this->redirect(StaffLanding::url($user), navigate: true);
        }
        $this->dispatch('toast', type: 'success', message: __('admin.password_updated'));
    }

    public function startTwoFactor(TwoFactorService $tfa): void
    {
        $this->pendingSecret = $tfa->generateSecret();
    }

    public function confirmTwoFactor(TwoFactorService $tfa, AuditLogger $audit): void
    {
        $this->validate(['confirmCode' => 'required|digits:6']);
        $user = auth()->user();
        if (! $this->pendingSecret || ! $tfa->verify($user, $this->confirmCode, $this->pendingSecret)) {
            $this->addError('confirmCode', __('errors.two_factor_invalid'));

            return;
        }
        $user->forceFill(['two_factor_secret' => $this->pendingSecret, 'two_factor_confirmed_at' => now()])->save();
        $this->recoveryCodes = $tfa->generateRecoveryCodes($user);
        session(['two_factor_passed' => true]);
        $this->pendingSecret = null;
        $this->confirmCode = '';
        $audit->log('staff.two_factor_enabled', $user);
        $this->dispatch('toast', type: 'success', message: __('admin.two_factor_enabled'));
    }

    public function disableTwoFactor(AuditLogger $audit, TwoFactorService $tfa): void
    {
        if ($tfa->required()) {
            $this->dispatch('toast', type: 'error', message: __('admin.two_factor_mandatory'));

            return;
        }
        $this->validate(['current_password' => 'required|string']);
        $user = auth()->user();
        if ($this->currentPasswordWrong($user)) {
            return;
        }
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $audit->log('staff.two_factor_disabled', $user);
        $this->reset('current_password', 'recoveryCodes');
        $this->dispatch('toast', type: 'warning', message: __('admin.two_factor_disabled'));
    }

    public function render(TwoFactorService $tfa)
    {
        $qr = null;
        if ($this->pendingSecret) {
            $qr = (new QRCode(new QROptions(['outputType' => 'svg', 'outputBase64' => false, 'svgUseFillAttributes' => true, 'eccLevel' => 'M'])))
                ->render($tfa->otpauthUri(auth()->user(), $this->pendingSecret));
        }

        return view('livewire.admin.profile', ['qr' => $qr, 'user' => auth()->user()->load('branches', 'roles'), 'teamRequired' => $tfa->required()])->title(__('admin.profile'));
    }
}
