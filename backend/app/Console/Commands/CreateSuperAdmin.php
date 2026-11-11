<?php

namespace App\Console\Commands;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Enums\UserType;
use App\Domain\Identity\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/** Bootstrap the first Super Admin on a fresh environment (never via seeders in production). */
class CreateSuperAdmin extends Command
{
    protected $signature = 'platform:create-admin {--email=} {--name=} {--password= : Omit to be prompted securely}';

    protected $description = 'Create (or promote) a Super Admin staff account';

    public function handle(AuditLogger $audit): int
    {
        $email = $this->option('email') ?: $this->ask('Email');
        $name = $this->option('name') ?: $this->ask('Full name');
        $password = $this->option('password') ?: $this->secret('Password (min 12 chars, mixed case, number)');

        $v = Validator::make(compact('email', 'name', 'password'), [
            'email' => 'required|email', 'name' => 'required|string|min:2',
            'password' => ['required', Password::min(12)->mixedCase()->numbers()],
        ]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $e) {
                $this->error($e);
            }

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => strtolower($email)]);
        $user->fill(['name' => $name, 'type' => UserType::Staff, 'status' => 'active', 'password' => $password, 'job_title' => 'Super Admin']);
        $user->forceFill(['is_super_admin' => true])->save();
        $audit->log('staff.super_admin_created', $user, [], ['email' => $user->email], ['via' => 'console']);

        $this->info("Super Admin ready: {$user->email}");

        return self::SUCCESS;
    }
}
