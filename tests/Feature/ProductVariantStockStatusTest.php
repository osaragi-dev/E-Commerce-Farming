<?php

use App\Filament\Resources\StockMutations\Pages\ListStockMutations;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

test('stock status accessor resolves the correct stock label without being treated as a relationship', function (): void {
    $available = ProductVariant::factory()->create([
        'stock_available' => 25,
        'low_stock_threshold' => 10,
    ]);
    $lowStock = ProductVariant::factory()->create([
        'stock_available' => 7,
        'low_stock_threshold' => 10,
    ]);
    $outOfStock = ProductVariant::factory()->create([
        'stock_available' => 0,
        'low_stock_threshold' => 10,
    ]);

    expect($available->stockStatus)->toBe('Available')
        ->and($available->is_low_stock)->toBeFalse()
        ->and($lowStock->stockStatus)->toBe('Low Stock')
        ->and($lowStock->is_low_stock)->toBeTrue()
        ->and($outOfStock->stockStatus)->toBe('Out of Stock')
        ->and($outOfStock->is_low_stock)->toBeFalse();
});

test('stock mutation variant filter handles a soft deleted parent product', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $actor = User::factory()->create();
    $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($actor);

    $variant = ProductVariant::factory()->create(['name' => 'Grade A']);
    $deletedProductVariant = ProductVariant::factory()->create(['name' => 'Grade B']);
    $deletedProductVariant->product->delete();

    $page = Livewire::test(ListStockMutations::class)->assertOk();
    $options = $page->instance()->getTable()->getFilter('product_variant_id')->getOptions();

    expect($options[$variant->id])->toBe("{$variant->product->name} - Grade A")
        ->and($options[$deletedProductVariant->id])->toBe('Unknown Product - Grade B');
});
