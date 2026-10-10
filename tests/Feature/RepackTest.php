<?php

use App\Enums\RecordStatus;
use App\Enums\StockMutationSource;
use App\Models\ProductVariant;
use App\Models\Repack;
use App\Models\StockMutation;
use App\Models\SystemFreezeState;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function repackFixtures(): array
{
    $warehouse = Warehouse::factory()->create();
    $from = ProductVariant::factory()->create(['initial_stock' => 0, 'stock_available' => 5]);
    $to = ProductVariant::factory()->create(['initial_stock' => 0, 'stock_available' => 0]);
    WarehouseStock::factory()->create(['warehouse_id' => $warehouse->id, 'product_variant_id' => $from->id, 'stock_available' => 5]);

    return [$warehouse, $from, $to, [
        'warehouse_id' => $warehouse->id,
        'from_product_variant_id' => $from->id,
        'to_product_variant_id' => $to->id,
        'from_quantity' => '1',
        'to_quantity' => '1000',
    ]];
}

test('repack consumes source and adds target with two linked mutations', function (): void {
    [$warehouse, $from, $to, $data] = repackFixtures();
    $repack = Repack::create($data);
    expect($from->fresh()->stock_available)->toBe('4.000')
        ->and($from->fresh()->stock_out)->toBe('1.000')
        ->and($from->warehouseStocks()->where('warehouse_id', $warehouse->id)->firstOrFail()->stock_out)->toBe('1.000')
        ->and($to->fresh()->stock_out)->toBe('0.000')
        ->and($to->fresh()->stock_available)->toBe('1000.000')
        ->and(Repack::availableAt($to->fresh(), $warehouse))->toBe('1000.000')
        ->and($repack->stockMutations()->count())->toBe(2)
        ->and($repack->stockMutations()->pluck('source')->unique()->all())->toBe([StockMutationSource::Repack])
        ->and($repack->from_unit)->toBe($from->unit->name)
        ->and($repack->applied_at)->not->toBeNull()
        ->and($repack->stock_out_applied_at)->not->toBeNull();
    $repack->fresh()->markAsSaved();
    expect($repack->stockMutations()->count())->toBe(2)
        ->and($from->fresh()->stock_out)->toBe('1.000');
});

test('convert source out accumulates quantities while target out remains unchanged', function (string $fromQuantity, string $toQuantity, string $available, string $out): void {
    [$warehouse, $from, $to, $data] = repackFixtures();
    DB::table('product_variants')->where('id', $from->id)->update(['stock_out' => '2.500']);
    DB::table('product_variants')->where('id', $to->id)->update(['stock_out' => '3.000']);
    $sourceStock = $from->warehouseStocks()->where('warehouse_id', $warehouse->id)->firstOrFail();
    $sourceStock->update(['stock_out' => '2.500']);

    $record = Repack::create([...$data, 'from_quantity' => $fromQuantity, 'to_quantity' => $toQuantity]);

    expect($from->fresh()->stock_available)->toBe($available)
        ->and($from->fresh()->stock_out)->toBe($out)
        ->and($sourceStock->fresh()->stock_available)->toBe($available)
        ->and($sourceStock->fresh()->stock_out)->toBe($out)
        ->and($sourceStock->fresh()->total_stock)->toBe('7.500')
        ->and($to->fresh()->stock_available)->toBe($toQuantity)
        ->and($to->fresh()->stock_out)->toBe('3.000')
        ->and($to->warehouseStocks()->where('warehouse_id', $warehouse->id)->firstOrFail()->stock_out)->toBe('0.000')
        ->and($record->stockMutations()->where('product_variant_id', $from->id)->sole()->quantity_change)->toBe('-'.$fromQuantity)
        ->and($record->stockMutations()->where('product_variant_id', $to->id)->sole()->quantity_change)->toBe($toQuantity);
})->with([
    ['1.000', '1.000', '4.000', '3.500'],
    ['1.250', '2.750', '3.750', '3.750'],
]);

test('failure to mark convert out rolls back both counters mutations and available stock', function (): void {
    [$warehouse, $from, $to, $data] = repackFixtures();
    DB::statement("CREATE TRIGGER reject_convert_out BEFORE UPDATE OF stock_out_applied_at ON repacks BEGIN SELECT RAISE(ABORT, 'Out marker failed'); END");

    try {
        expect(fn () => Repack::create($data))->toThrow(QueryException::class, 'Out marker failed');
    } finally {
        DB::statement('DROP TRIGGER reject_convert_out');
    }

    expect($from->fresh()->stock_available)->toBe('5.000')
        ->and($from->fresh()->stock_out)->toBe('0.000')
        ->and($to->fresh()->stock_available)->toBe('0.000')
        ->and($from->warehouseStocks()->where('warehouse_id', $warehouse->id)->firstOrFail()->stock_out)->toBe('0.000')
        ->and(Repack::count())->toBe(0)
        ->and(StockMutation::where('source', StockMutationSource::Repack)->count())->toBe(0)
        ->and(DB::table('repack_sequences')->count())->toBe(0);
});

test('draft saves without stock or number and checks availability on submit', function (): void {
    [$warehouse, $from, $to, $data] = repackFixtures();
    $draft = Repack::create([...$data, 'from_quantity' => 6, 'record_status' => RecordStatus::Draft]);
    expect($draft->repack_number)->toBeNull()->and($draft->stockMutations()->count())->toBe(0)
        ->and($from->fresh()->stock_available)->toBe('5.000')
        ->and($from->fresh()->stock_out)->toBe('0.000')
        ->and($draft->stock_out_applied_at)->toBeNull()
        ->and(DB::table('repack_sequences')->count())->toBe(0);
    expect(fn () => $draft->markAsSaved())->toThrow(ValidationException::class);
    expect($draft->fresh()->record_status)->toBe(RecordStatus::Draft);
    expect($from->fresh()->stock_out)->toBe('0.000');
    $draft = $draft->fresh();
    $draft->from_quantity = 1;
    $draft->markAsSaved();
    expect($draft->stockMutations()->count())->toBe(2)->and($to->fresh()->stock_available)->toBe('1000.000')
        ->and($from->fresh()->stock_out)->toBe('1.000')
        ->and($draft->fresh()->stock_out_applied_at)->not->toBeNull();
});

test('saved repacks reject changes deletion and force deletion', function (): void {
    [, , , $data] = repackFixtures();
    $record = Repack::create($data);
    expect(fn () => $record->update(['notes' => 'Changed']))->toThrow(ValidationException::class);
    expect(fn () => $record->fresh()->markAsDraft())->toThrow(ValidationException::class);
    expect(fn () => $record->fresh()->delete())->toThrow(ValidationException::class);
    expect(fn () => $record->fresh()->forceDelete())->toThrow(ValidationException::class);
    expect($record->fresh()->notes)->toBeNull()->and($record->stockMutations()->count())->toBe(2);
});

test('deleted drafts restore without changing stock', function (): void {
    $record = Repack::factory()->create();
    $record->delete();
    expect($record->fresh()->record_status)->toBe(RecordStatus::Deleted);
    $record->restore();
    expect($record->fresh()->record_status)->toBe(RecordStatus::Draft)
        ->and($record->fresh()->deleted_at)->toBeNull()->and($record->stockMutations()->count())->toBe(0);
});

test('invalid repack quantities and equal items do not save', function (string $field, mixed $value): void {
    [, , , $data] = repackFixtures();
    expect(fn () => Repack::create([...$data, $field => $value]))->toThrow(ValidationException::class);
    expect(Repack::count())->toBe(0)->and(StockMutation::where('source', StockMutationSource::Repack)->count())->toBe(0);
})->with([
    ['from_quantity', 0], ['to_quantity', -1], ['from_quantity', '0.0001'], ['to_quantity', 'abc'],
]);

test('same source and target are rejected', function (): void {
    [, $from, , $data] = repackFixtures();
    expect(fn () => Repack::create([...$data, 'to_product_variant_id' => $from->id]))->toThrow(ValidationException::class);
    expect(Repack::count())->toBe(0);
});

test('inactive or deleted items and locations are rejected', function (string $kind): void {
    [$warehouse, $from, $to, $data] = repackFixtures();
    match ($kind) {
        'location' => $warehouse->update(['is_active' => false]),
        'source' => $from->update(['is_active' => false]),
        'target' => $to->delete(),
        'product' => $to->product->markAsDraft(),
    };
    expect(fn () => Repack::create($data))->toThrow(ValidationException::class);
    expect(Repack::count())->toBe(0);
})->with(['location', 'source', 'target', 'product']);

test('failure of target mutation rolls back source document and counter', function (): void {
    [, $from, $to, $data] = repackFixtures();
    $dispatcher = StockMutation::getEventDispatcher();
    StockMutation::setEventDispatcher(clone $dispatcher);
    StockMutation::creating(function (StockMutation $mutation) use ($to): void {
        if ($mutation->source === StockMutationSource::Repack && $mutation->product_variant_id === $to->id) {
            throw new RuntimeException('Target failed');
        }
    });
    try {
        expect(fn () => Repack::create($data))->toThrow(RuntimeException::class, 'Target failed');
    } finally {
        StockMutation::setEventDispatcher($dispatcher);
    }
    expect($from->fresh()->stock_available)->toBe('5.000')->and($to->fresh()->stock_available)->toBe('0.000')
        ->and($from->fresh()->stock_out)->toBe('0.000')
        ->and(Repack::count())->toBe(0)->and(DB::table('repack_sequences')->count())->toBe(0)
        ->and(StockMutation::where('source', StockMutationSource::Repack)->count())->toBe(0);
});

test('repack numbers reset daily and expand past 999', function (): void {
    [, , , $data] = repackFixtures();
    $this->travelTo(now('Asia/Jakarta')->setDate(2026, 10, 2)->startOfDay());
    DB::table('repack_sequences')->insert(['date' => '20261002', 'last_number' => 999]);
    $record = Repack::create($data);
    expect($record->repack_number)->toBe('RPK-20261002-1000');
    $this->travelTo(now('Asia/Jakarta')->addDay());
    expect(Repack::create($data)->repack_number)->toBe('RPK-20261003-001');
});

test('repack cannot consume another locations global stock', function (): void {
    [, , , $data] = repackFixtures();
    $other = Warehouse::factory()->create();
    expect(fn () => Repack::create([...$data, 'warehouse_id' => $other->id]))->toThrow(ValidationException::class);
    expect(Repack::count())->toBe(0);
});

test('new farming target stock does not duplicate stock held elsewhere', function (): void {
    $farming = Warehouse::query()->where('code', 'WH-FARMING')->firstOrFail();
    $other = Warehouse::factory()->create();
    $from = ProductVariant::factory()->create(['initial_stock' => 0, 'stock_available' => 1]);
    $to = ProductVariant::factory()->create(['initial_stock' => 0, 'stock_available' => 50]);
    WarehouseStock::factory()->create(['warehouse_id' => $farming->id, 'product_variant_id' => $from->id, 'stock_available' => 1]);
    WarehouseStock::factory()->create(['warehouse_id' => $other->id, 'product_variant_id' => $to->id, 'stock_available' => 50]);
    Repack::create([
        'warehouse_id' => $farming->id, 'from_product_variant_id' => $from->id, 'to_product_variant_id' => $to->id,
        'from_quantity' => 1, 'to_quantity' => 1000,
    ]);
    expect(Repack::availableAt($to->fresh(), $farming))->toBe('1000.000')
        ->and(Repack::availableAt($to->fresh(), $other))->toBe('50.000')
        ->and($to->fresh()->stock_available)->toBe('1050.000');
});

test('freeze blocks creating submitting deleting and restoring repacks', function (): void {
    [, , , $data] = repackFixtures();
    $draft = Repack::create([...$data, 'record_status' => RecordStatus::Draft]);
    $deleted = Repack::factory()->create();
    $deleted->delete();
    SystemFreezeState::current()->update(['is_frozen' => true]);
    expect(fn () => Repack::create($data))->toThrow(ValidationException::class)
        ->and(fn () => $draft->markAsSaved())->toThrow(ValidationException::class)
        ->and(fn () => $draft->fresh()->delete())->toThrow(ValidationException::class)
        ->and(fn () => $deleted->restore())->toThrow(ValidationException::class);
    expect($draft->stockMutations()->count())->toBe(0);
});
