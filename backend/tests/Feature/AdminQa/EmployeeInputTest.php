<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Livewire\Admin\Organization\EmployeeEditor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->superAdmin()->create();
    $this->actingAs($this->admin);
});

it('rejects a new employee whose e-mail differs from an existing one only by letter case', function () {
    User::factory()->staff()->create(['email' => 'taken@albahar.qa']);

    Livewire::test(EmployeeEditor::class)
        ->set('form', ['name' => 'Dup', 'email' => 'Taken@AlBahar.qa', 'phone' => '', 'job_title' => '', 'locale' => 'en', 'password' => 'Abcdefgh1234'])
        ->call('create')
        ->assertHasErrors(['form.email']);
});

it('rejects an edited employee e-mail that differs from another account only by letter case', function () {
    User::factory()->staff()->create(['email' => 'taken@albahar.qa']);
    $other = User::factory()->staff()->create(['email' => 'other@albahar.qa']);

    Livewire::test(EmployeeEditor::class, ['employee' => $other])
        ->set('form.email', 'TAKEN@albahar.qa')
        ->call('saveProfile')
        ->assertHasErrors(['form.email']);
});

it('rejects a phone number that is a duplicate once spaces are removed', function () {
    User::factory()->staff()->create(['phone' => '+97455123456']);

    Livewire::test(EmployeeEditor::class)
        ->set('form', ['name' => 'Dup', 'email' => 'new@albahar.qa', 'phone' => '+974 5512 3456', 'job_title' => '', 'locale' => 'en', 'password' => 'Abcdefgh1234'])
        ->call('create')
        ->assertHasErrors(['form.phone']);
});

it('treats zone and supplier codes case-insensitively when checking for duplicates', function () {
    App\Domain\Branches\Models\DeliveryZone::factory()->create(['code' => 'WESTBAY']);
    App\Domain\Inventory\Models\Supplier::factory()->create(['code' => 'ACME']);

    Livewire::test(App\Livewire\Admin\Organization\ZoneIndex::class)->call('create')
        ->set('form.code', 'westbay')->set('form.name_en', 'West Bay')->set('form.name_ar', 'الخليج')
        ->call('save')->assertHasErrors(['form.code']);
    Livewire::test(App\Livewire\Admin\Inventory\SupplierIndex::class)->call('create')
        ->set('form.code', 'acme')->set('form.name', 'Acme')->set('form.currency', 'QAR')
        ->call('save')->assertHasErrors(['form.code']);
});

it('accepts a NUL byte in a submitted string without a server error (it is stripped before it reaches PostgreSQL)', function () {
    $this->postJson('/api/v1/auth/login', ['email' => "a\0b@example.com", 'password' => "x\0y"])->assertUnauthorized();
    $this->actingAs($this->admin)->get('/admin/products?search='.urlencode("ab\0cd"))->assertOk();
});
