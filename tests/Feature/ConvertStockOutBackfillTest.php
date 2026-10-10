<?php

use App\Enums\StockMutationSource;
use App\Models\ProductVariant;
use App\Models\Repack;
use App\Models\StockMutation;
use App\Models\SystemFreezeState;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function historicalConvert(): Repack
{
    $warehouse = Warehouse::factory()->create();
    $source = ProductVariant::factory()->create(['initial_stock' => 0, 'stock_available' => 5, 'stock_out' => '2.500']);
    $target = ProductVariant::factory()->create(['initial_stock' => 0, 'stock_available' => 0, 'stock_out' => 3]);
    $stock = WarehouseStock::factory()->create([
        'warehouse_id' => $warehouse->id, 'product_variant_id' => $source->id, 'stock_available' => 5, 'stock_out' => '2.500',
    ]);
    $record = Repack::create([
        'warehouse_id' => $warehouse->id, 'from_product_variant_id' => $source->id, 'to_product_variant_id' => $target->id,
        'from_quantity' => '1.250', 'to_quantity' => '2.750',
    ]);
    DB::table('repacks')->where('id', $record->id)->update(['stock_out_applied_at' => null]);
    DB::table('product_variants')->where('id', $source->id)->update(['stock_out' => '2.500']);
    DB::table('warehouse_stocks')->where('id', $stock->id)->update(['stock_out' => '2.500']);

    return $record->fresh();
}

test('historical convert backfill adds out once without replaying stock or audit mutations', function (): void {
    $record = historicalConvert();
    $number = $record->repack_number;
    $mutations = $record->stockMutations()->orderBy('id')->get()->toArray();
    $draft = Repack::factory()->create();
    $record->fromProductVariant->delete();
    SystemFreezeState::current()->update(['is_frozen' => true]);

    $this->artisan('convert:backfill-stock-out')
        ->expectsOutput('Applied Out to 1 Convert transaction(s); 0 failed.')->assertSuccessful();
    $this->artisan('convert:backfill-stock-out')
        ->expectsOutput('Applied Out to 0 Convert transaction(s); 0 failed.')->assertSuccessful();

    $source = $record->fromProductVariant->fresh();
    $stock = WarehouseStock::where('warehouse_id', $record->warehouse_id)->where('product_variant_id', $source->id)->firstOrFail();
    expect($source->stock_out)->toBe('3.750')
        ->and($stock->stock_out)->toBe('3.750')
        ->and($source->stock_available)->toBe('3.750')
        ->and($stock->stock_available)->toBe('3.750')
        ->and($record->toProductVariant->stock_available)->toBe('2.750')
        ->and($record->toProductVariant->stock_out)->toBe('3.000')
        ->and($record->fresh()->repack_number)->toBe($number)
        ->and($record->fresh()->stock_out_applied_at)->not->toBeNull()
        ->and($record->stockMutations()->orderBy('id')->get()->toArray())->toBe($mutations)
        ->and($draft->fresh()->stock_out_applied_at)->toBeNull();
});

test('historical convert missing source stock reports failure and continues other records', function (): void {
    $broken = historicalConvert();
    $valid = historicalConvert();
    WarehouseStock::where('warehouse_id', $broken->warehouse_id)->where('product_variant_id', $broken->from_product_variant_id)->delete();

    $this->artisan('convert:backfill-stock-out')
        ->expectsOutput('Convert '.$broken->repack_number.': Convert source variant or warehouse stock was not found.')
        ->expectsOutput('Applied Out to 1 Convert transaction(s); 1 failed.')->assertFailed();

    expect($broken->fresh()->stock_out_applied_at)->toBeNull()
        ->and($broken->fromProductVariant->stock_out)->toBe('2.500')
        ->and($valid->fromProductVariant->stock_out)->toBe('3.750')
        ->and($valid->fresh()->stock_out_applied_at)->not->toBeNull()
        ->and(StockMutation::where('source', StockMutationSource::Repack)->count())->toBe(4);
});

test('historical convert interruption rolls back out and can safely resume', function (): void {
    $record = historicalConvert();
    DB::statement("CREATE TRIGGER reject_historical_out BEFORE UPDATE OF stock_out_applied_at ON repacks BEGIN SELECT RAISE(ABORT, 'Backfill interrupted'); END");

    try {
        expect(fn () => Artisan::call('convert:backfill-stock-out'))->toThrow(QueryException::class, 'Backfill interrupted');
    } finally {
        DB::statement('DROP TRIGGER reject_historical_out');
    }

    expect($record->fresh()->stock_out_applied_at)->toBeNull()
        ->and($record->fromProductVariant->stock_out)->toBe('2.500')
        ->and(WarehouseStock::where('warehouse_id', $record->warehouse_id)->where('product_variant_id', $record->from_product_variant_id)->firstOrFail()->stock_out)->toBe('2.500');

    $this->artisan('convert:backfill-stock-out')->assertSuccessful();

    expect($record->fromProductVariant->fresh()->stock_out)->toBe('3.750')
        ->and($record->fresh()->stock_out_applied_at)->not->toBeNull()
        ->and($record->stockMutations()->count())->toBe(2);
});
