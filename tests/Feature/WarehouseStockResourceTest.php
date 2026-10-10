<?php

use App\Filament\Exports\TableExportDefinition;
use App\Filament\Exports\TableExportService;
use App\Filament\Resources\Stocks\Pages\ListItemStocks;
use App\Filament\Resources\Stocks\Pages\ListStocks;
use App\Filament\Resources\Stocks\Pages\StockWarehouses;
use App\Filament\Resources\Stocks\StockResource;
use App\Models\ItemWarehouseStock;
use App\Models\Permission;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Policies\WarehouseStockPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = Role::findOrCreate('super_admin', 'web');
    $actor = User::factory()->sso()->create();
    $actor->assignRole($role);
    $this->actingAs($actor);
});

test('warehouse stocks appear in inventory with separated stock columns', function (): void {
    $warehouse = Warehouse::query()->where('code', 'WH-FARMING')->firstOrFail();
    $variant = ProductVariant::factory()->create();

    WarehouseStock::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 8,
        'stock_reserved' => 2,
        'stock_in_transit' => 1,
        'stock_out' => 3,
    ]);

    Livewire::test(ListStocks::class)
        ->assertCanSeeTableRecords(WarehouseStock::query()->get())
        ->assertTableColumnExists('warehouse.name')
        ->assertTableColumnExists('productVariant.name')
        ->assertTableColumnExists('stock_available')
        ->assertTableColumnExists('stock_reserved')
        ->assertTableColumnExists('stock_out');

    $this->get('/admin/warehouse-stocks')->assertNotFound();
    $this->get(StockResource::getUrl('index'))->assertOk();
});

test('warehouse stock total includes available reserved and stock out', function (): void {
    $stock = WarehouseStock::factory()->create([
        'stock_available' => 8,
        'stock_reserved' => 2,
        'stock_in_transit' => 1,
        'stock_out' => 3,
    ]);

    expect($stock->total_stock)->toBe('14.000')
        ->and(StockResource::canCreate())->toBeFalse()
        ->and(StockResource::canDelete($stock))->toBeFalse();

    $policy = new WarehouseStockPolicy;
    expect($policy->create(auth()->user()))->toBeFalse()
        ->and($policy->update(auth()->user(), $stock))->toBeFalse()
        ->and($policy->delete(auth()->user(), $stock))->toBeFalse()
        ->and($policy->deleteAny(auth()->user()))->toBeFalse()
        ->and($policy->restore(auth()->user(), $stock))->toBeFalse()
        ->and($policy->restoreAny(auth()->user()))->toBeFalse()
        ->and($policy->replicate(auth()->user(), $stock))->toBeFalse()
        ->and($policy->reorder(auth()->user()))->toBeFalse();
});

test('warehouse stock rows rise to the top after a stock update', function (): void {
    $warehouse = Warehouse::query()->where('code', 'WH-FARMING')->firstOrFail();
    $older = WarehouseStock::factory()->create([
        'warehouse_id' => $warehouse->id,
        'stock_available' => 10,
        'updated_at' => now()->subMinute(),
    ]);
    $newer = WarehouseStock::factory()->create([
        'warehouse_id' => $warehouse->id,
        'stock_available' => 4,
        'updated_at' => now(),
    ]);

    Livewire::test(ListStocks::class)
        ->assertCanSeeTableRecords([$newer, $older]);
});

test('stock viewers cannot invoke exports without export stock permission', function (): void {
    $user = User::factory()->sso()->create();
    $user->givePermissionTo(Permission::findOrCreate('ViewAny:Stock', 'web'));
    $user->givePermissionTo(Permission::findOrCreate('Export:WarehouseStock', 'web'));
    $this->actingAs($user);
    $service = Mockery::mock(TableExportService::class);
    $service->shouldNotReceive('downloadCsv');
    app()->instance(TableExportService::class, $service);
    $stock = WarehouseStock::factory()->create([
        'warehouse_id' => Warehouse::query()->where('code', 'WH-FARMING')->firstOrFail()->id,
    ]);

    Livewire::test(ListStocks::class)
        ->assertTableActionHidden('exportCsv')
        ->assertTableBulkActionHidden('exportSelectedCsv')
        ->call('mountTableAction', 'exportCsv')
        ->assertSet('mountedActions', []);
    Livewire::test(ListStocks::class)
        ->call('mountTableBulkAction', 'exportSelectedCsv', [$stock->id])
        ->assertSet('mountedActions', []);
});

test('export stock permission alone does not grant stock access', function (): void {
    $user = User::factory()->sso()->create();
    $user->givePermissionTo(Permission::findOrCreate('Export:Stock', 'web'));
    $this->actingAs($user);

    $this->get(StockResource::getUrl('index'))->assertForbidden();
    Livewire::test(ListStocks::class)->assertForbidden();
    expect((new WarehouseStockPolicy)->export($user))->toBeFalse();
});

test('stock page and selected exports use the new permission in every format', function (string $method, string $action, bool $bulk): void {
    $user = User::factory()->sso()->create();
    $user->givePermissionTo([
        Permission::findOrCreate('ViewAny:Stock', 'web'),
        Permission::findOrCreate('Export:Stock', 'web'),
    ]);
    $this->actingAs($user);
    $records = WarehouseStock::factory()->count(2)->create([
        'warehouse_id' => Warehouse::query()->where('code', 'WH-FARMING')->firstOrFail()->id,
    ]);
    $expected = $bulk ? $records->take(1) : $records;
    $service = Mockery::mock(TableExportService::class);
    $service->shouldReceive($method)->once()
        ->withArgs(fn (Collection $exported, string $model, TableExportDefinition $definition): bool => $exported->pluck('id')->sort()->values()->all() === $expected->pluck('id')->sort()->values()->all()
            && $model === WarehouseStock::class
            && ! array_key_exists('id', $definition->columns))
        ->andReturn(response()->streamDownload(static fn () => print 'stocks', 'stocks.export'));
    app()->instance(TableExportService::class, $service);
    $component = Livewire::test(ListStocks::class);
    if ($bulk) {
        $component->callTableBulkAction($action, $expected);
    } else {
        $component->callTableAction($action);
    }
    $component->assertHasNoActionErrors()->assertFileDownloaded('stocks.export');
})->with([
    ['downloadExcel', 'exportExcel', false],
    ['downloadCsv', 'exportCsv', false],
    ['downloadPdf', 'exportPdf', false],
    ['downloadExcel', 'exportSelectedExcel', true],
    ['downloadCsv', 'exportSelectedCsv', true],
    ['downloadPdf', 'exportSelectedPdf', true],
]);

test('item and warehouse stock views use export stock permission', function (string $page): void {
    $user = User::factory()->sso()->create();
    $user->givePermissionTo(Permission::findOrCreate('ViewAny:Stock', 'web'));
    $this->actingAs($user);

    Livewire::test($page)
        ->assertTableActionHidden('exportCsv')
        ->assertTableBulkActionHidden('exportSelectedCsv');

    $user->givePermissionTo(Permission::findOrCreate('Export:Stock', 'web'));
    Livewire::test($page)
        ->assertTableActionVisible('exportCsv')
        ->assertTableBulkActionVisible('exportSelectedCsv');
})->with([[ListItemStocks::class], [StockWarehouses::class]]);

test('stock exports produce valid files in every format', function (string $method, string $signature): void {
    $stock = WarehouseStock::factory()->create();
    $response = app(TableExportService::class)->{$method}(
        collect([$stock]), WarehouseStock::class, StockResource::exportDefinition(),
    );
    ob_start();
    try {
        $response->sendContent();
        $content = ob_get_contents();
    } finally {
        ob_end_clean();
    }
    expect($content)->toStartWith($signature);
})->with([
    ['downloadExcel', 'PK'],
    ['downloadCsv', "\xEF\xBB\xBF"],
    ['downloadPdf', '%PDF'],
]);

test('item stock exports retain the warehouse stock model and stock permission', function (bool $bulk): void {
    $user = User::factory()->sso()->create();
    $user->givePermissionTo([
        Permission::findOrCreate('ViewAny:Stock', 'web'),
        Permission::findOrCreate('Export:Stock', 'web'),
    ]);
    $this->actingAs($user);
    $stock = ItemWarehouseStock::factory()->create(['stock_available' => 8]);
    $service = Mockery::mock(TableExportService::class);
    $service->shouldReceive('downloadCsv')->once()
        ->withArgs(fn (Collection $records, string $model, TableExportDefinition $definition): bool => $records->pluck('id')->all() === [$stock->id]
            && $model === ItemWarehouseStock::class
            && array_key_exists('item.name', $definition->columns)
            && array_key_exists('stock_available', $definition->columns))
        ->andReturn(response()->streamDownload(static fn () => print 'items', 'item-stocks.csv'));
    app()->instance(TableExportService::class, $service);

    $component = Livewire::test(ListItemStocks::class)->assertCanSeeTableRecords([$stock]);
    if ($bulk) {
        $component->callTableBulkAction('exportSelectedCsv', [$stock]);
    } else {
        $component->callTableAction('exportCsv');
    }
    $component->assertHasNoActionErrors()->assertFileDownloaded('item-stocks.csv');
})->with(['page' => [false], 'selected' => [true]]);
