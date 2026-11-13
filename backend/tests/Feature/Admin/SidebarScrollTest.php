<?php

use App\Domain\Identity\Models\User;

it('gives the admin menu a remembered scroll position', function () {
    $admin = User::factory()->superAdmin()->create();
    $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()
        ->assertSee('albahar.admin.nav.scroll', false)->assertSee('restore()', false);
});
