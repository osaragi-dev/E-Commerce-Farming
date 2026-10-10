<?php

use App\Filament\Exports\TableExportDefinition;
use App\Filament\Exports\TableExportService;
use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = Role::findOrCreate('super_admin', 'web');
    $this->actor = User::factory()->create();
    $this->actor->assignRole($role);
    $this->actingAs($this->actor);
    $this->definition = new TableExportDefinition(
        title: 'Products',
        columns: [
            'id' => 'Internal ID',
            'slug' => 'Slug',
            'name' => 'Name',
        ],
    );
});

function streamedContent(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

test('definition excludes internal fields and CSV neutralizes spreadsheet formulas', function (): void {
    $product = Product::factory()->make([
        'slug' => '=HYPERLINK("https://example.test")',
        'name' => "\t+SUM(1,1)",
    ]);

    $response = app(TableExportService::class)->downloadCsv(
        collect([$product]),
        Product::class,
        $this->definition,
    );
    $content = streamedContent($response);

    expect($this->definition->columns)->not->toHaveKey('id')
        ->and($content)->toContain('Slug,Name')
        ->and($content)->toContain("'=HYPERLINK")
        ->and($content)->toContain("'\t+SUM")
        ->and($content)->not->toContain('Internal ID');
});

test('table exports support inbox array rows and neutralize spreadsheet formulas', function (): void {
    $definition = new TableExportDefinition(
        title: 'Request Approval',
        columns: ['notes' => 'Notes'],
    );
    $response = app(TableExportService::class)->downloadCsv(
        collect([['notes' => '=HYPERLINK("https://example.test")']]),
        'request-approval',
        $definition,
    );

    $content = streamedContent($response);

    expect($content)->toContain('Notes')
        ->and($content)->toContain("'=HYPERLINK")
        ->and($content)->not->toContain('request-approval');
});

test('inbox array rows export to every supported format', function (string $method, string $signature): void {
    $response = app(TableExportService::class)->{$method}(
        collect([['notes' => 'Review this request']]),
        'request-approval',
        new TableExportDefinition('Request Approval', ['notes' => 'Notes']),
    );

    expect(streamedContent($response))->toStartWith($signature);
})->with([
    ['downloadExcel', 'PK'],
    ['downloadCsv', "\xEF\xBB\xBF"],
    ['downloadPdf', '%PDF'],
]);

test('definition can follow visible table column order', function (): void {
    $definition = new TableExportDefinition(
        title: 'Products',
        columns: [
            'name' => 'Name',
            'category.name' => 'Category',
            'created_at' => 'Created At',
            'description' => 'Description',
        ],
        relationships: ['category', 'createdBy'],
        dateOnly: ['created_at'],
    );

    $visibleDefinition = $definition->onlyColumns(['created_at', 'name', 'unknown']);

    expect($visibleDefinition->columns)->toBe([
        'created_at' => 'Created At',
        'name' => 'Name',
    ])->and($visibleDefinition->relationships)->toBe(['category', 'createdBy'])
        ->and($visibleDefinition->monetary)->toBe([])
        ->and($visibleDefinition->dateOnly)->toBe(['created_at']);
});

test('XLSX CSV and PDF downloads have valid signatures', function (string $method, string $signature): void {
    $records = Product::factory()->count(2)->create();
    $response = app(TableExportService::class)->{$method}(
        $records,
        Product::class,
        $this->definition,
    );

    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and(streamedContent($response))->toStartWith($signature);
})->with([
    ['downloadExcel', 'PK'],
    ['downloadCsv', "\xEF\xBB\xBF"],
    ['downloadPdf', '%PDF'],
]);

test('PDF export rejects more than two thousand records', function (): void {
    $prototype = Product::factory()->make();
    $records = Collection::times(2001, fn (): Product => clone $prototype);

    expect(fn () => app(TableExportService::class)->downloadPdf(
        $records,
        Product::class,
        $this->definition,
    ))->toThrow(ValidationException::class, '2,000');
});

test('page export receives only the active page and pagination all receives every filtered row', function (): void {
    Product::factory()->count(12)->create(['is_active' => true]);

    $seenCounts = [];
    $response = response()->streamDownload(static fn () => print 'ok', 'products.csv');
    $service = Mockery::mock(TableExportService::class);
    $service->shouldReceive('downloadCsv')
        ->twice()
        ->withArgs(function (Collection $records) use (&$seenCounts): bool {
            $seenCounts[] = $records->count();

            return true;
        })
        ->andReturn($response);
    app()->instance(TableExportService::class, $service);

    Livewire::test(ManageProducts::class)
        ->set('tableRecordsPerPage', 10)
        ->callTableAction('exportCsv')
        ->assertHasNoActionErrors();

    Livewire::test(ManageProducts::class)
        ->set('tableRecordsPerPage', 'all')
        ->callTableAction('exportCsv')
        ->assertHasNoActionErrors();

    expect($seenCounts)->toBe([10, 12]);
});

test('selected export receives only selected rows', function (): void {
    $records = Product::factory()->count(3)->create();
    $response = response()->streamDownload(static fn () => print 'selected', 'products.xlsx');
    $service = Mockery::mock(TableExportService::class);
    $service->shouldReceive('downloadExcel')
        ->once()
        ->withArgs(fn (Collection $selected): bool => collect($selected->modelKeys())->sort()->values()->all()
            === collect($records->take(2)->modelKeys())->sort()->values()->all())
        ->andReturn($response);
    app()->instance(TableExportService::class, $service);

    Livewire::test(ManageProducts::class)
        ->callTableBulkAction('exportSelectedExcel', $records->take(2))
        ->assertHasNoActionErrors();
});

test('page and selected exports follow visible column manager columns', function (
    string $serviceMethod,
    string $actionName,
    bool $isBulkAction,
): void {
    $records = Product::factory()->count(2)->create();
    $response = response()->streamDownload(static fn () => print 'export', 'products.export');
    $service = Mockery::mock(TableExportService::class);
    $service->shouldReceive($serviceMethod)
        ->once()
        ->withArgs(fn (
            Collection $exportedRecords,
            string $model,
            TableExportDefinition $definition,
        ): bool => $exportedRecords->isNotEmpty()
            && $model === Product::class
            && array_keys($definition->columns) === ['slug', 'record_status'])
        ->andReturn($response);
    app()->instance(TableExportService::class, $service);

    $component = Livewire::test(ManageProducts::class)
        ->toggleAllTableColumns(false);

    if ($isBulkAction) {
        $component->callTableBulkAction($actionName, $records);
    } else {
        $component->callTableAction($actionName);
    }

    $component->assertHasNoActionErrors();
})->with([
    'page XLSX' => ['downloadExcel', 'exportExcel', false],
    'page CSV' => ['downloadCsv', 'exportCsv', false],
    'page PDF' => ['downloadPdf', 'exportPdf', false],
    'selected XLSX' => ['downloadExcel', 'exportSelectedExcel', true],
    'selected CSV' => ['downloadCsv', 'exportSelectedCsv', true],
    'selected PDF' => ['downloadPdf', 'exportSelectedPdf', true],
]);

test('CSV headers match the default visible column manager columns', function (): void {
    $product = Product::factory()->create();

    $definition = ProductResource::exportDefinition()->onlyColumns([
        'slug',
        'name',
        'is_active',
        'record_status',
    ]);
    $content = streamedContent(app(TableExportService::class)->downloadCsv(
        collect([$product]),
        Product::class,
        $definition,
    ));

    expect($content)->toContain('#,Slug,Name,Active,"Record Status"')
        ->not->toContain('Description')
        ->not->toContain('Deleted By');
});

test('Users export includes one or more role names in every format', function (string $method, string $signature): void {
    $adminRole = Role::findOrCreate('admin', 'web');
    $staffRole = Role::findOrCreate('staff', 'web');
    $user = User::factory()->create(['name' => 'Export User']);
    $user->assignRole([$adminRole, $staffRole]);

    $definition = UserResource::exportDefinition()->onlyColumns(['name', 'roles.name']);
    $service = app(TableExportService::class);
    $content = streamedContent($service->{$method}(
        collect([$user]),
        User::class,
        $definition,
    ));

    expect($definition->columns)->toBe([
        'name' => 'Name',
        'roles.name' => 'Role',
    ])
        ->and($service->value($user->load('roles'), 'roles.name'))->toBe('admin, staff')
        ->and($service->pdfValue($user->load('roles'), 'roles.name', $definition))->toBe('admin, staff')
        ->and($content)->toStartWith($signature);

    if ($method === 'downloadCsv') {
        expect($content)
            ->toContain('#,Name,Role')
            ->toContain('"Export User","admin, staff"');
    }
})->with([
    ['downloadExcel', 'PK'],
    ['downloadCsv', "\xEF\xBB\xBF"],
    ['downloadPdf', '%PDF'],
]);

test('Users page and selected exports retain the Role column', function (string $actionName, bool $isBulkAction): void {
    $records = User::factory()->count(2)->create();
    $response = response()->streamDownload(static fn () => print 'users', 'users.csv');
    $service = Mockery::mock(TableExportService::class);
    $service->shouldReceive('downloadCsv')
        ->once()
        ->withArgs(fn (
            Collection $exportedRecords,
            string $model,
            TableExportDefinition $definition,
        ): bool => $exportedRecords->isNotEmpty()
            && $model === User::class
            && array_key_exists('roles.name', $definition->columns))
        ->andReturn($response);
    app()->instance(TableExportService::class, $service);

    $component = Livewire::test(ListUsers::class);

    if ($isBulkAction) {
        $component->callTableBulkAction($actionName, $records);
    } else {
        $component->callTableAction($actionName);
    }

    $component->assertHasNoActionErrors();
})->with([
    'page export' => ['exportCsv', false],
    'selected export' => ['exportSelectedCsv', true],
]);

test('filter search and sort are honored by current page export', function (): void {
    Product::factory()->create(['slug' => 'match-b', 'name' => 'Needle', 'is_active' => true]);
    Product::factory()->create(['slug' => 'match-a', 'name' => 'Needle', 'is_active' => true]);
    Product::factory()->create(['slug' => 'other', 'name' => 'Haystack', 'is_active' => false]);

    $response = response()->streamDownload(static fn () => print 'filtered', 'products.csv');
    $service = Mockery::mock(TableExportService::class);
    $service->shouldReceive('downloadCsv')
        ->once()
        ->withArgs(fn (Collection $records): bool => $records->pluck('slug')->all() === ['match-a', 'match-b'])
        ->andReturn($response);
    app()->instance(TableExportService::class, $service);

    Livewire::test(ManageProducts::class)
        ->searchTable('Needle')
        ->sortTable('slug')
        ->callTableAction('exportCsv')
        ->assertHasNoActionErrors();
});
