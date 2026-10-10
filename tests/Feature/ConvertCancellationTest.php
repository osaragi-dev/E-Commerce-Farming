<?php

use App\Enums\RecordStatus;
use App\Enums\StockMutationSource;
use App\Models\ProductVariant;
use App\Models\Repack;
use App\Models\StockMutation;
use App\Models\SystemFreezeState;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function cancellableConvert(string $fromQuantity = '1.250', string $toQuantity = '2.750'): Repack
{
    $warehouse = Warehouse::factory()->create();
    $from = ProductVariant::factory()->create(['initial_stock' => 0, 'stock_available' => 5, 'stock_out' => 2]);
    $to = ProductVariant::factory()->create(['initial_stock' => 0, 'stock_available' => 0, 'stock_out' => 3]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $warehouse->id, 'product_variant_id' => $from->id, 'stock_available' => 5, 'stock_out' => 2,
    ]);

    return Repack::create([
        'warehouse_id' => $warehouse->id, 'from_product_variant_id' => $from->id, 'to_product_variant_id' => $to->id,
        'from_quantity' => $fromQuantity, 'to_quantity' => $toQuantity,
    ]);
}

test('cancel convert restores source and out and records two reversal mutations once', function (string $fromQuantity, string $toQuantity): void {
    $record = cancellableConvert($fromQuantity, $toQuantity);
    $actor = User::factory()->create();
    $originalMutations = $record->stockMutations()->orderBy('id')->get()->toArray();

    $record->cancel($actor->id);
    $record->cancel($actor->id);

    $sourceStock = WarehouseStock::where('warehouse_id', $record->warehouse_id)->where('product_variant_id', $record->from_product_variant_id)->firstOrFail();
    $targetStock = WarehouseStock::where('warehouse_id', $record->warehouse_id)->where('product_variant_id', $record->to_product_variant_id)->firstOrFail();
    expect($record->fromProductVariant->stock_available)->toBe('5.000')
        ->and($record->fromProductVariant->stock_out)->toBe('2.000')
        ->and($sourceStock->stock_available)->toBe('5.000')->and($sourceStock->stock_out)->toBe('2.000')
        ->and($record->toProductVariant->stock_available)->toBe('0.000')
        ->and($record->toProductVariant->stock_out)->toBe('3.000')
        ->and($targetStock->stock_available)->toBe('0.000')
        ->and($record->display_status)->toBe('Cancelled')
        ->and($record->record_status)->toBe(RecordStatus::Saved)
        ->and($record->cancelled_by)->toBe($actor->id)
        ->and($record->trashed())->toBeFalse()
        ->and($record->stockMutations()->count())->toBe(4)
        ->and($record->stockMutations()->where('source', StockMutationSource::Repack)->orderBy('id')->get()->toArray())->toBe($originalMutations)
        ->and($record->stockMutations()->where('source', StockMutationSource::CancelRepack)->where('product_variant_id', $record->from_product_variant_id)->sole()->quantity_change)->toBe($fromQuantity)
        ->and($record->stockMutations()->where('source', StockMutationSource::CancelRepack)->where('product_variant_id', $record->to_product_variant_id)->sole()->quantity_change)->toBe('-'.$toQuantity);
    expect(fn () => $record->markAsSaved())->toThrow(ValidationException::class);
    expect(fn () => $record->delete())->toThrow(ValidationException::class);
})->with([['1.000', '1.000'], ['1.250', '2.750']]);

test('cancel convert rejects insufficient target stock without returning source stock', function (): void {
    $record = cancellableConvert();
    DB::table('warehouse_stocks')->where('warehouse_id', $record->warehouse_id)->where('product_variant_id', $record->to_product_variant_id)->update(['stock_available' => 0]);

    expect(fn () => $record->cancel())->toThrow(ValidationException::class, 'Insufficient available target stock');

    expect($record->fresh()->cancelled_at)->toBeNull()
        ->and($record->fromProductVariant->stock_available)->toBe('3.750')
        ->and($record->fromProductVariant->stock_out)->toBe('3.250')
        ->and($record->stockMutations()->count())->toBe(2);
});

test('cancel convert rejects missing stock and frozen system', function (string $failure): void {
    $record = cancellableConvert();
    if ($failure === 'missing stock') {
        WarehouseStock::where('warehouse_id', $record->warehouse_id)->where('product_variant_id', $record->from_product_variant_id)->delete();
    } else {
        SystemFreezeState::current()->update(['is_frozen' => true]);
    }

    expect(fn () => $record->cancel())->toThrow(ValidationException::class);

    expect($record->fresh()->cancelled_at)->toBeNull()
        ->and($record->fromProductVariant->stock_out)->toBe('3.250')
        ->and($record->stockMutations()->count())->toBe(2);
})->with(['missing stock', 'frozen']);

test('cancel convert failure rolls back both reversal mutations and stock updates', function (): void {
    $record = cancellableConvert();
    $dispatcher = StockMutation::getEventDispatcher();
    StockMutation::setEventDispatcher(clone $dispatcher);
    StockMutation::creating(function (StockMutation $mutation) use ($record): void {
        if ($mutation->source === StockMutationSource::CancelRepack && $mutation->product_variant_id === $record->from_product_variant_id) {
            throw new RuntimeException('Reversal failed');
        }
    });
    try {
        expect(fn () => $record->cancel())->toThrow(RuntimeException::class, 'Reversal failed');
    } finally {
        StockMutation::setEventDispatcher($dispatcher);
    }

    expect($record->fresh()->cancelled_at)->toBeNull()
        ->and($record->fromProductVariant->stock_available)->toBe('3.750')
        ->and($record->fromProductVariant->stock_out)->toBe('3.250')
        ->and($record->toProductVariant->stock_available)->toBe('2.750')
        ->and($record->stockMutations()->count())->toBe(2);
});

test('cancel historical convert reconciles out and backfill does not replay it', function (): void {
    $record = cancellableConvert();
    DB::table('repacks')->where('id', $record->id)->update(['stock_out_applied_at' => null]);
    DB::table('product_variants')->where('id', $record->from_product_variant_id)->update(['stock_out' => 2]);
    DB::table('warehouse_stocks')->where('warehouse_id', $record->warehouse_id)->where('product_variant_id', $record->from_product_variant_id)->update(['stock_out' => 2]);

    $record->cancel();
    $this->artisan('convert:backfill-stock-out')->assertSuccessful();

    expect($record->fromProductVariant->stock_out)->toBe('2.000')
        ->and($record->fresh()->stock_out_applied_at)->not->toBeNull()
        ->and($record->stockMutations()->count())->toBe(4);
});

test('draft and deleted convert cannot be cancelled', function (bool $deleted): void {
    $draft = Repack::factory()->create();
    if ($deleted) {
        $draft->delete();
    }
    expect(fn () => $draft->cancel())->toThrow(ValidationException::class);
    expect($draft->fresh()->cancelled_at)->toBeNull()->and($draft->stockMutations()->count())->toBe(0);
})->with([false, true]);
