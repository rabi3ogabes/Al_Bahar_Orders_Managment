<?php

namespace App\Console\Commands;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use Illuminate\Console\Command;

/** For a locked-out administrator (lost phone, no recovery codes): switch two-factor off for one staff account from the server. */
class TurnOffStaffTwoFactor extends Command
{
    protected $signature = 'staff:two-factor-off {email : The staff member\'s email address}';

    protected $description = 'Turn two-factor sign-in off for one staff account (they can enrol again from their profile)';

    public function handle(AuditLogger $audit): int
    {
        $user = User::where('type', 'staff')->where('email', strtolower(trim((string) $this->argument('email'))))->first();
        if ($user === null) {
            $this->error('No staff account has that email address.');

            return self::FAILURE;
        }
        if (! $user->hasTwoFactorEnabled()) {
            $this->info("Two-factor is already off for {$user->email}.");

            return self::SUCCESS;
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $audit->log('employee.two_factor_turned_off', $user, [], [], ['via' => 'console']);
        $this->info("Two-factor turned off for {$user->email}.");

        return self::SUCCESS;
    }
}
