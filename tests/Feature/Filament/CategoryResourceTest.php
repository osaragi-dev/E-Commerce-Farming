<?php

use App\Enums\RecordStatus;
use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $role = Role::findOrCreate('super_admin', 'web');
    $actor = User::factory()->create();
    $actor->assignRole($role);
    $this->actingAs($actor);
});

test('categories use the baseline lifecycle', function (): void {
    $record = Category::factory()->create();

    expect($record->id)->toBeString()
        ->and($record->record_status)->toBe(RecordStatus::Saved)
        ->and($record->markAsDraft())->toBeTrue()
        ->and($record->fresh()->record_status)->toBe(RecordStatus::Draft);
});

test('category form and table do not expose the retired featured field', function (): void {
    $component = Livewire::test(ManageCategories::class)
        ->mountAction('create')
        ->assertFormFieldDoesNotExist('is_featured');

    expect($component->instance()->getTable()->getColumn('is_featured'))->toBeNull();
});
