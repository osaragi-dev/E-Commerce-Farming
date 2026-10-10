<?php

use App\Enums\RecordStatus;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = Role::findOrCreate('super_admin', 'web');
    $this->actor = User::factory()->create();
    $this->actor->assignRole($role);
    $this->actingAs($this->actor);
});

test('saved identifiers are unique after case and whitespace normalization while drafts may reuse them', function (): void {
    $saved = Product::factory()->create(['slug' => ' prd-001 ']);
    $draft = Product::factory()->create([
        'slug' => 'PRD-001',
        'record_status' => RecordStatus::Draft,
    ]);

    expect($saved->slug)->toBe('prd-001')
        ->and($draft->record_status)->toBe(RecordStatus::Draft);

    expect(fn () => Product::factory()->create(['slug' => '  PRD-001  ']))
        ->toThrow(ValidationException::class);

    expect(fn () => $draft->markAsSaved())
        ->toThrow(ValidationException::class)
        ->and($draft->refresh()->record_status)->toBe(RecordStatus::Draft);
});

test('sector names are unique among saved records and slug follows name updates', function (): void {
    Sector::factory()->create([
        'name' => 'Peternakan',
        'slug' => 'peternakan',
    ]);

    expect(fn () => Sector::factory()->create([
        'name' => 'Peternakan',
        'slug' => 'peternakan-baru',
    ]))->toThrow(ValidationException::class);

    $sector = new Sector;
    $sector->forceFill(['name' => 'Perkebunan Sawit']);
    expect($sector->getAttribute('name'))->toBe('Perkebunan Sawit');
});

test('lifecycle transitions retain actor audit and restore the previous status', function (): void {
    $product = Product::factory()->create(['record_status' => RecordStatus::Draft]);

    expect($product->created_by)->toBe($this->actor->id)
        ->and($product->updated_by)->toBeNull()
        ->and($product->updated_at)->toBeNull();

    $product->markAsSaved();
    $product->delete();
    $deleted = Product::withTrashed()->findOrFail($product->id);

    expect($deleted->record_status)->toBe(RecordStatus::Deleted)
        ->and($deleted->previous_record_status)->toBe(RecordStatus::Saved)
        ->and($deleted->deleted_by)->toBe($this->actor->id);

    $deleted->restore();

    expect($deleted->refresh()->record_status)->toBe(RecordStatus::Saved)
        ->and($deleted->previous_record_status)->toBeNull()
        ->and($deleted->deleted_by)->toBeNull()
        ->and($deleted->updated_by)->toBe($this->actor->id);
});

test('restore collision safely falls back to draft', function (): void {
    $deleted = Product::factory()->create(['slug' => 'restore-1']);
    $deleted->delete();
    Product::factory()->create(['slug' => 'RESTORE-1']);

    $deleted->restore();

    expect($deleted->refresh()->trashed())->toBeFalse()
        ->and($deleted->record_status)->toBe(RecordStatus::Draft);
});
