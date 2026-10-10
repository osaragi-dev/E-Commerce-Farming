<?php

use App\Enums\RecordStatus;
use App\Filament\Exports\TableExportService;
use App\Filament\Resources\Repacks\Pages\ManageRepacks;
use App\Filament\Resources\Repacks\RepackResource;
use App\Filament\Resources\Repacks\Schemas\RepackForm;
use App\Filament\Resources\StockMutations\StockMutationResource;
use App\Filament\Resources\Stocks\Pages\ListStocks;
use App\Filament\Resources\Stocks\StockResource;
use App\Models\Category;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Repack;
use App\Models\Role;
use App\Models\SystemFreezeState;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\RepackProductCreator;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actor = User::factory()->create();
    $this->actor->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($this->actor);
});

test('convert page renders and old repack links preserve modal parameters', function (): void {
    Livewire::test(ManageRepacks::class)->assertSee('Convert');

    $this->get('/admin/repacks?tableAction=view&tableActionRecord=example')
        ->assertRedirect(url('/admin/convert?tableAction=view&tableActionRecord=example'));

    expect(RepackResource::getUrl('index', panel: 'admin'))->toBe(url('/admin/convert'));
});

test('inventory sidebar places convert immediately after stocks', function (): void {
    Livewire::test(ManageRepacks::class);
    $inventory = collect(Filament::getNavigation())->first(fn ($group): bool => $group->getLabel() === 'Inventory');
    $labels = collect($inventory->getItems())->map(fn ($item): string => $item->getLabel())->values()->all();

    expect(array_slice($labels, 0, 2))->toBe(['Stocks', 'Convert']);
});

function repackResourceData(): array
{
    $warehouse = Warehouse::factory()->create();
    $from = ProductVariant::factory()->create(['initial_stock' => 0, 'stock_available' => 5]);
    $to = ProductVariant::factory()->create(['initial_stock' => 0, 'stock_available' => 0]);
    WarehouseStock::factory()->create(['warehouse_id' => $warehouse->id, 'product_variant_id' => $from->id, 'stock_available' => 5]);

    return ['warehouse_id' => $warehouse->id, 'from_product_variant_id' => $from->id, 'to_product_variant_id' => $to->id, 'from_quantity' => 1, 'to_quantity' => 1000, 'notes' => null];
}

test('convert source out appears in stocks and its CSV export after create', function (): void {
    $data = repackResourceData();
    $data['warehouse_id'] = Warehouse::where('code', 'WH-FARMING')->firstOrFail()->id;
    WarehouseStock::factory()->create([
        'warehouse_id' => $data['warehouse_id'], 'product_variant_id' => $data['from_product_variant_id'], 'stock_available' => 5,
    ]);
    Livewire::test(ManageRepacks::class)->callAction('create', $data)->assertHasNoActionErrors();
    $stock = WarehouseStock::where('warehouse_id', $data['warehouse_id'])->where('product_variant_id', $data['from_product_variant_id'])->firstOrFail();

    Livewire::test(ListStocks::class)->assertTableColumnStateSet('stock_out', '1.000', $stock);
    $response = app(TableExportService::class)->downloadCsv(collect([$stock]), WarehouseStock::class, StockResource::exportDefinition());
    ob_start();
    $response->sendContent();
    $content = (string) ob_get_clean();
    $rows = array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), explode("\n", trim($content)));
    $outIndex = array_search('Out', $rows[0], true);
    expect($outIndex)->not->toBeFalse();
    expect($rows[1][$outIndex])->toBe('1');
});

test('repack create modal posts stock and preserves requested column order', function (): void {
    $data = repackResourceData();
    $page = Livewire::test(ManageRepacks::class)
        ->callAction('create', $data)->assertHasNoActionErrors();
    $record = Repack::firstOrFail();
    expect($record->record_status)->toBe(RecordStatus::Saved)
        ->and($record->stockMutations()->count())->toBe(2)
        ->and($record->created_by)->toBe($this->actor->id);
    expect(array_keys($page->instance()->getTable()->getColumns()))->toBe([
        'row_number', 'repack_number', 'warehouse.name', 'fromProductVariant.inventory_label',
        'from_quantity', 'from_unit', 'toProductVariant.inventory_label', 'to_quantity', 'to_unit',
        'record_status', 'notes', 'created_at', 'createdBy.name', 'updated_at', 'updatedBy.name',
        'deleted_at', 'deletedBy.name',
    ]);
    $page->assertTableActionHidden('edit', $record)->assertTableActionHidden('delete', $record)
        ->assertTableActionDoesNotExist('submit')->assertTableActionExists('view', record: $record);
});

test('repack modal saves draft and edits draft before submission', function (): void {
    $data = repackResourceData();
    Livewire::test(ManageRepacks::class)->callAction('create', $data, ['draft' => true])->assertHasNoActionErrors();
    $draft = Repack::firstOrFail();
    expect($draft->record_status)->toBe(RecordStatus::Draft)->and($draft->repack_number)->toBeNull()->and($draft->stockMutations()->count())->toBe(0);
    Livewire::test(ManageRepacks::class)
        ->callTableAction('edit', $draft, [...$data, 'to_quantity' => 900], ['draft' => true])->assertHasNoTableActionErrors();
    expect($draft->fresh()->to_quantity)->toBe('900.000')->and($draft->fresh()->record_status)->toBe(RecordStatus::Draft);
    Livewire::test(ManageRepacks::class)->assertTableActionDoesNotExist('submit')
        ->callTableAction('edit', $draft, data: [...$data, 'to_quantity' => 900])->assertHasNoTableActionErrors();
    expect($draft->fresh()->record_status)->toBe(RecordStatus::Saved)->and($draft->stockMutations()->count())->toBe(2);
});

test('convert clone creates a fresh saved transaction or a draft from editable copied data', function (bool $draft, bool $cancelled): void {
    $data = repackResourceData();
    $original = Repack::create($data);
    if ($cancelled) {
        $original->cancel($this->actor->id);
    }

    $page = Livewire::test(ManageRepacks::class)->mountTableAction('clone', $original)
        ->assertTableActionDataSet($data);
    expect($page->instance()->getMountedAction()->getModalSubmitAction()->getLabel())->toBe('Create');
    $page->setTableActionData([...$data, 'to_quantity' => 500, 'notes' => 'Cloned convert'])
        ->callMountedTableAction(arguments: ['draft' => $draft])->assertHasNoTableActionErrors();

    $replica = Repack::where('id', '!=', $original->id)->sole();
    expect($replica->to_quantity)->toBe('500.000')->and($replica->notes)->toBe('Cloned convert')
        ->and($replica->cancelled_at)->toBeNull()->and($replica->cancelled_by)->toBeNull()
        ->and($replica->deleted_at)->toBeNull()->and($replica->deleted_by)->toBeNull()
        ->and($replica->created_by)->toBe($this->actor->id)
        ->and($replica->record_status)->toBe($draft ? RecordStatus::Draft : RecordStatus::Saved)
        ->and($replica->stockMutations()->count())->toBe($draft ? 0 : 2);
    if ($draft) {
        expect($replica->repack_number)->toBeNull()->and($replica->applied_at)->toBeNull()->and($replica->stock_out_applied_at)->toBeNull();
    } else {
        expect($replica->repack_number)->not->toBe($original->repack_number)
            ->and($replica->applied_at)->not->toBeNull()->and($replica->stock_out_applied_at)->not->toBeNull();
    }
})->with([[false, false], [true, false], [false, true], [true, true]]);

test('cancel convert action marks status and status filters separate cancelled from saved', function (): void {
    $record = Repack::create(repackResourceData());
    $saved = Repack::create(repackResourceData());

    Livewire::test(ManageRepacks::class)->callTableAction('cancel', $record)->assertHasNoTableActionErrors();

    expect($record->fresh()->cancelled_by)->toBe($this->actor->id);
    Livewire::test(ManageRepacks::class)->assertTableColumnStateSet('record_status', 'Cancelled', $record)
        ->assertTableActionHidden('cancel', $record)->assertTableActionHidden('edit', $record)
        ->assertTableActionHidden('delete', $record)->assertTableActionVisible('clone', $record)
        ->filterTable('record_status', 'cancelled')->assertCanSeeTableRecords([$record])->assertCanNotSeeTableRecords([$saved]);
    Livewire::test(ManageRepacks::class)->filterTable('record_status', 'saved')
        ->assertCanSeeTableRecords([$saved])->assertCanNotSeeTableRecords([$record]);
});

test('convert cancellation and clone require their own permissions and respect frozen state', function (): void {
    $record = Repack::create(repackResourceData());
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::findOrCreate('ViewAny:Repack', 'web'));
    $this->actingAs($user);
    Livewire::test(ManageRepacks::class)->assertTableActionHidden('cancel', $record)->assertTableActionHidden('clone', $record);

    foreach (['Cancel:Repack', 'Create:Repack', 'View:Repack'] as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    Livewire::test(ManageRepacks::class)->assertTableActionVisible('cancel', $record)->assertTableActionVisible('clone', $record);
    SystemFreezeState::current()->update(['is_frozen' => true]);
    Livewire::test(ManageRepacks::class)->assertTableActionHidden('cancel', $record)->assertTableActionHidden('clone', $record);
    expect($record->fresh()->cancelled_at)->toBeNull()->and($record->stockMutations()->count())->toBe(2);
});

test('cancel convert reports insufficient target stock in its confirmation action', function (): void {
    $record = Repack::create(repackResourceData());
    WarehouseStock::where('warehouse_id', $record->warehouse_id)->where('product_variant_id', $record->to_product_variant_id)
        ->update(['stock_available' => 0]);

    Livewire::test(ManageRepacks::class)->callTableAction('cancel', $record)
        ->assertNotified(Notification::make()->title('Cannot cancel Convert')
            ->body('Insufficient available target stock to cancel this Convert.')->danger());

    expect($record->fresh()->cancelled_at)->toBeNull()->and($record->stockMutations()->count())->toBe(2);
});

test('frozen convert hides cancel and clone even for super admin', function (): void {
    $record = Repack::create(repackResourceData());
    SystemFreezeState::current()->update(['is_frozen' => true]);

    Livewire::test(ManageRepacks::class)->assertTableActionHidden('cancel', $record)->assertTableActionHidden('clone', $record);
});

test('convert deleted audit fields appear in table detail and export', function (): void {
    $record = Repack::factory()->create();
    $record->delete();
    Livewire::test(ManageRepacks::class)->filterTable('trashed', true)
        ->assertTableColumnStateSet('deletedBy.name', $this->actor->name, $record)
        ->assertTableColumnExists('deleted_at')->assertTableActionHidden('clone', $record)
        ->mountTableAction('view', $record)->assertSchemaComponentExists('deleted_at')->assertSchemaComponentExists('deletedBy.name');
    $response = app(TableExportService::class)->downloadCsv(collect([$record]), Repack::class, RepackResource::exportDefinition());
    ob_start();
    $response->sendContent();
    $content = (string) ob_get_clean();
    expect($content)->toContain('Deleted At', 'Deleted By', $this->actor->name);
    $record->restore();
    expect($record->fresh()->deleted_at)->toBeNull()->and($record->fresh()->deleted_by)->toBeNull();
});

test('repack source options respect location and unit follows item', function (): void {
    $data = repackResourceData();
    $warehouse = Warehouse::findOrFail($data['warehouse_id']);
    $other = Warehouse::factory()->create();
    expect(RepackForm::sourceOptions($warehouse->id))->toHaveKey($data['from_product_variant_id'])
        ->not->toHaveKey($data['to_product_variant_id'])
        ->and(RepackForm::sourceOptions($other->id))->toBe([])
        ->and(RepackForm::unit($data['from_product_variant_id']))->toBe(ProductVariant::findOrFail($data['from_product_variant_id'])->unit->name);
    $page = Livewire::test(ManageRepacks::class)->mountAction('create')->fillForm($data);
    $page->set('mountedActions.0.data.warehouse_id', $other->id)
        ->assertSchemaStateSet(['from_product_variant_id' => null]);
});

test('repack source quantity shows local availability and rejects excess quantity', function (): void {
    $data = repackResourceData();
    $page = Livewire::test(ManageRepacks::class)->mountAction('create')->fillForm($data)
        ->assertFormFieldExists('from_quantity', fn ($field): bool => $field->getSuffixLabel() === 'Available: 5');
    $page->setActionData([...$data, 'from_quantity' => 5.001])->callMountedAction()
        ->assertHasActionErrors(['from_quantity']);
    expect(Repack::count())->toBe(0);
    $other = Warehouse::factory()->create();
    $page->set('mountedActions.0.data.warehouse_id', $other->id)->assertFormFieldExists('from_quantity', fn ($field): bool => $field->getSuffixLabel() === 'Available: 0');
});

test('product creators can use repack plus without a separate variant permission', function (): void {
    $data = repackResourceData();
    $user = User::factory()->create();
    foreach (['ViewAny:Repack', 'Create:Repack', 'Create:Product'] as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($user);
    $product = Product::factory()->create();
    Livewire::test(ManageRepacks::class)->mountAction('create')->fillForm($data)
        ->mountAction(TestAction::make('createOption')->schemaComponent('to_product_variant_id', 'mountedActionSchema0'))
        ->setActionData([
            'product_id' => $product->id, 'name' => 'Gram',
            'unit_id' => Unit::factory()->create()->id, 'price' => 100, 'price_quantity' => 1, 'low_stock_threshold' => 0,
        ])->callMountedAction()->assertHasNoActionErrors()->assertActionMounted('create');
    expect($product->variants()->firstOrFail()->stock_available)->toBe('0.000');
});

test('repack plus creates an existing product variant with no stock changes', function (): void {
    $warehouse = Warehouse::factory()->create();
    $product = Product::factory()->create();
    $variant = app(RepackProductCreator::class)->create([
        'product_mode' => 'existing', 'product_id' => $product->id, 'name' => 'Gram',
        'unit_id' => Unit::factory()->create()->id, 'price' => 100, 'price_quantity' => 1, 'low_stock_threshold' => 0,
        'initial_stock' => 99, 'stock_available' => 99,
    ], $warehouse->id);
    expect($variant->product_id)->toBe($product->id)->and($variant->stock_available)->toBe('0.000')
        ->and($variant->initial_stock)->toBe('0.000')
        ->and($variant->warehouseStocks()->firstOrFail()->warehouse_id)->toBe($warehouse->id);
});

test('repack inline plus selects its new variant without losing parent form data', function (): void {
    $data = repackResourceData();
    $product = Product::factory()->create();
    $unit = Unit::factory()->create();
    $page = Livewire::test(ManageRepacks::class)->mountAction('create')->fillForm($data)
        ->mountAction(TestAction::make('createOption')->schemaComponent('to_product_variant_id', 'mountedActionSchema0'))
        ->setActionData([
            'product_mode' => 'existing', 'product_id' => $product->id, 'name' => 'Gram',
            'unit_id' => $unit->id, 'price' => 100, 'price_quantity' => 1, 'low_stock_threshold' => 0,
        ])->callMountedAction()->assertHasNoActionErrors()->assertActionMounted('create');
    $variant = $product->variants()->firstOrFail();
    $page->assertActionDataSet([
        'warehouse_id' => $data['warehouse_id'], 'from_product_variant_id' => $data['from_product_variant_id'],
        'to_product_variant_id' => $variant->id, 'to_quantity' => 1000,
    ])->unmountAction();
    expect($variant->fresh()->stock_available)->toBe('0.000')->and(Repack::count())->toBe(0);
});

test('repack inline variant keeps invalid product errors in the nested modal', function (): void {
    $data = repackResourceData();
    $product = Product::factory()->create();
    $product->update(['is_active' => false]);
    Livewire::test(ManageRepacks::class)->mountAction('create')->fillForm($data)
        ->mountAction(TestAction::make('createOption')->schemaComponent('to_product_variant_id', 'mountedActionSchema0'))
        ->setActionData([
            'product_id' => $product->id, 'name' => 'Gram',
            'unit_id' => Unit::factory()->create()->id, 'price' => 100, 'price_quantity' => 1, 'low_stock_threshold' => 0,
        ])->callMountedAction()->assertHasActionErrors(['product_id']);
    expect(Product::where('slug', $product->slug)->count())->toBe(1)->and(Repack::count())->toBe(0);
});

test('repack plus requires an existing product instead of creating a new product', function (): void {
    $warehouse = Warehouse::factory()->create();
    $data = [
        'product_mode' => 'new', 'category_id' => Category::factory()->create()->id, 'product_name' => 'Chicken Pieces',
        'slug' => 'chicken-pieces', 'name' => 'Gram', 'unit_id' => Unit::factory()->create()->id,
        'price' => 100, 'price_quantity' => 1, 'low_stock_threshold' => 0,
    ];
    expect(fn () => app(RepackProductCreator::class)->create($data, $warehouse->id))->toThrow(ValidationException::class);
    expect(Product::where('slug', 'chicken-pieces')->count())->toBe(0);
});

test('repack resource and product creation enforce permissions', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    expect(RepackResource::canViewAny())->toBeFalse()->and(RepackResource::canCreate())->toBeFalse()
        ->and(Gate::allows('export', Repack::class))->toBeFalse();
    $warehouse = Warehouse::factory()->create();
    expect(fn () => app(RepackProductCreator::class)->create([], $warehouse->id))->toThrow(AuthorizationException::class);
    $user->givePermissionTo(Permission::findOrCreate('ViewAny:Repack', 'web'));
    expect(RepackResource::canViewAny())->toBeTrue()->and(RepackResource::canCreate())->toBeFalse();
    $user->givePermissionTo(Permission::findOrCreate('Export:Repack', 'web'));
    expect(Gate::allows('export', Repack::class))->toBeTrue();
});

test('repack table searches products and variants and sorts both item columns', function (): void {
    $first = Repack::factory()->create();
    $second = Repack::factory()->create();
    $first->fromProductVariant->update(['name' => 'Whole Chicken Source']);
    $first->toProductVariant->product->update(['name' => 'Chicken Gram Target']);
    Livewire::test(ManageRepacks::class)->searchTable('Whole Chicken Source')
        ->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
    Livewire::test(ManageRepacks::class)->searchTable('Chicken Gram Target')
        ->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
    Livewire::test(ManageRepacks::class)->sortTable('fromProductVariant.inventory_label')
        ->assertCanSeeTableRecords([$first, $second])->sortTable('toProductVariant.inventory_label')
        ->assertCanSeeTableRecords([$first, $second]);
});

test('repack delete permission does not require update permission', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::findOrCreate('Delete:Repack', 'web'));
    $this->actingAs($user);
    $draft = Repack::factory()->create();
    expect(RepackResource::canEdit($draft))->toBeFalse()->and(RepackResource::canDelete($draft))->toBeTrue();
});

test('saved repacks expose view and activity links with linked stock history', function (): void {
    $record = Repack::create(repackResourceData());
    Livewire::test(ManageRepacks::class)->mountTableAction('view', $record)
        ->assertSchemaComponentExists('stockMutations')
        ->assertSchemaStateSet(['repack_number' => $record->repack_number]);
    $mutation = $record->stockMutations()->firstOrFail();
    expect(StockMutationResource::activityUrl($mutation))->toContain('tableAction=view')->toContain('tableActionRecord='.$record->id);
    $paths = array_keys(RepackResource::exportDefinition()->columns);
    expect($paths)->toContain('repack_number', 'notes', 'from_quantity', 'to_quantity')
        ->not->toContain('id', 'warehouse_id', 'from_product_variant_id', 'to_product_variant_id', 'applied_at');
});

test('bulk delete skips saved repacks even for super admin', function (): void {
    $saved = Repack::create(repackResourceData());
    $draft = Repack::factory()->create();
    Livewire::test(ManageRepacks::class)->callTableBulkAction('delete', [$saved, $draft]);
    expect($saved->fresh()->deleted_at)->toBeNull()->and($draft->fresh()->trashed())->toBeTrue();
});

test('repack CSV export neutralizes formulas and omits primary keys', function (): void {
    $record = Repack::create([...repackResourceData(), 'notes' => '=SUM(1,1)']);
    $response = app(TableExportService::class)->downloadCsv(
        collect([$record]), Repack::class, RepackResource::exportDefinition(),
    );
    ob_start();
    $response->sendContent();
    $content = (string) ob_get_clean();
    expect($content)->toContain("'=SUM")->toContain('Convert Number')
        ->toContain($record->fromProductVariant->inventory_label, $record->toProductVariant->inventory_label)
        ->not->toContain($record->id, $record->warehouse_id, $record->from_product_variant_id, $record->to_product_variant_id);
});

test('repack exports selected records and page records in every format', function (string $method, string $action, bool $bulk): void {
    $records = Repack::factory()->count(2)->create();
    $service = Mockery::mock(TableExportService::class);
    $service->shouldReceive($method)->once()
        ->withArgs(fn (Collection $exported, string $model): bool => $model === Repack::class
            && $exported->count() === ($bulk ? 1 : 2))
        ->andReturn(response()->streamDownload(static fn () => print 'export', 'repacks.export'));
    app()->instance(TableExportService::class, $service);
    $page = Livewire::test(ManageRepacks::class);
    if ($bulk) {
        $page->callTableBulkAction($action, $records->take(1));
    } else {
        $page->callTableAction($action);
    }
    $page->assertHasNoActionErrors()->assertFileDownloaded('repacks.export');
})->with([
    ['downloadExcel', 'exportExcel', false], ['downloadCsv', 'exportCsv', false], ['downloadPdf', 'exportPdf', false],
    ['downloadExcel', 'exportSelectedExcel', true], ['downloadCsv', 'exportSelectedCsv', true], ['downloadPdf', 'exportSelectedPdf', true],
]);
