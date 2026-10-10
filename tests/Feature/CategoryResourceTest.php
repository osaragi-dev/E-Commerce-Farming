<?php

use App\Enums\RecordStatus;
use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $role = Role::findOrCreate('super_admin', 'web');
    $actor = User::factory()->create();
    $actor->assignRole($role);
    $this->actingAs($actor);
});

test('category form only offers saved active sectors', function (): void {
    $activeSector = Sector::factory()->create(['name' => 'Active Sector', 'is_active' => true]);
    Sector::factory()->create(['name' => 'Inactive Sector', 'is_active' => false]);
    Sector::factory()->create([
        'name' => 'Draft Sector',
        'is_active' => true,
        'record_status' => RecordStatus::Draft,
    ]);

    $page = Livewire::test(ManageCategories::class)->mountAction('create');
    $schema = $page->instance()->getSchema($page->instance()->getMountedActionSchemaName());

    expect($schema->getComponent('sector_id')->getOptions())
        ->toBe([$activeSector->id => 'Active Sector']);
});

test('category form does not expose the retired featured field', function (): void {
    Livewire::test(ManageCategories::class)
        ->mountAction('create')
        ->assertFormFieldDoesNotExist('is_featured');
});
