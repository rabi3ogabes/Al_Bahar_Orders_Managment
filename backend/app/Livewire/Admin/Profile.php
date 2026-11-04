<?php

namespace App\Livewire\Admin;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Services\TwoFactorService;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Hash;
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

    public function changePassword(AuditLogger $audit): void
    {
        $this->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->uncompromised(0)],
        ]);
        $user = auth()->user();
        if (! Hash::check($this->current_password, $user->password)) {
            $this->addError('current_password', __('auth.password'));

            return;
        }
        $user->forceFill(['password' => $this->password])->save();
        $user->tokens()->delete();
        $audit->log('staff.password_changed', $user);
        $this->reset('current_password', 'password', 'password_confirmation');
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

    public function disableTwoFactor(AuditLogger $audit): void
    {
        $this->validate(['current_password' => 'required|string']);
        $user = auth()->user();
        if (! Hash::check($this->current_password, $user->password)) {
            $this->addError('current_password', __('auth.password'));

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

        return view('livewire.admin.profile', ['qr' => $qr, 'user' => auth()->user()->load('branches', 'roles')])->title(__('admin.profile'));
    }
}
