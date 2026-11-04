<?php

namespace Database\Factories;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Enums\UserType;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'type' => UserType::Customer,
            'status' => UserStatus::Active,
            'name' => fake()->name(),
            'phone' => '+9745'.fake()->unique()->numerify('#######'),
            'email' => fake()->unique()->safeEmail(),
            'phone_verified_at' => now(),
            'password' => 'Password123',
            'locale' => 'en',
            'referral_code' => 'AB'.Str::upper(Str::random(6)),
        ];
    }

    public function staff(): static
    {
        return $this->state(fn () => ['type' => UserType::Staff, 'job_title' => 'Staff']);
    }

    public function superAdmin(): static
    {
        return $this->staff()->afterMaking(fn (User $u) => $u->forceFill(['is_super_admin' => true]));
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Suspended]);
    }
}
