<?php

use App\Models\ProductVariant;
use App\Models\ProductVariantPrice;
use Illuminate\Validation\ValidationException;

test('variant uses a promotional price only during its effective period', function (): void {
    $this->travelTo('2026-09-15 12:00:00');

    $variant = ProductVariant::factory()->create(['price' => 25000]);
    ProductVariantPrice::factory()->create([
        'product_variant_id' => $variant->id,
        'price' => 20000,
        'effective_from' => '2026-09-10',
        'effective_until' => '2026-09-20',
    ]);

    expect($variant->currentPrice())->toBe('20000.00')
        ->and($variant->has_discount)->toBeTrue()
        ->and($variant->currentPrice(now()->subDays(10)))->toBe('25000.00')
        ->and($variant->currentPrice(now()->addDays(10)))->toBe('25000.00');

    $this->travelTo('2026-09-25 12:00:00');

    expect($variant->fresh()->has_discount)->toBeFalse();
});

test('variant price periods cannot overlap', function (): void {
    $variant = ProductVariant::factory()->create();

    ProductVariantPrice::factory()->create([
        'product_variant_id' => $variant->id,
        'effective_from' => '2026-09-10',
        'effective_until' => '2026-09-20',
    ]);

    expect(fn (): ProductVariantPrice => ProductVariantPrice::create([
        'product_variant_id' => $variant->id,
        'price' => 20000,
        'effective_from' => '2026-09-20',
        'effective_until' => '2026-09-25',
    ]))->toThrow(ValidationException::class);
});
