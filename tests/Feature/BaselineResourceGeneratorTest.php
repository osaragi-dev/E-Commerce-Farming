<?php

use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->outputRoot = storage_path('framework/testing/baseline-generator-'.str()->uuid());
    File::ensureDirectoryExists($this->outputRoot);
});

afterEach(function (): void {
    File::deleteDirectory($this->outputRoot);
});

test('generator creates every baseline artifact without running migrations', function (): void {
    $this->artisan('make:baseline-resource', [
        'name' => 'CustomerProfile',
        '--base-path' => $this->outputRoot,
        '--no-interaction' => true,
    ])->assertSuccessful();

    $expected = [
        'app/Models/CustomerProfile.php',
        'database/factories/CustomerProfileFactory.php',
        'app/Filament/Resources/CustomerProfiles/CustomerProfileResource.php',
        'app/Filament/Resources/CustomerProfiles/Schemas/CustomerProfileForm.php',
        'app/Filament/Resources/CustomerProfiles/Schemas/CustomerProfileInfolist.php',
        'app/Filament/Resources/CustomerProfiles/Tables/CustomerProfilesTable.php',
        'app/Filament/Resources/CustomerProfiles/Pages/ManageCustomerProfiles.php',
        'tests/Feature/Filament/CustomerProfileResourceTest.php',
    ];

    foreach ($expected as $path) {
        expect(File::exists("{$this->outputRoot}/{$path}"))->toBeTrue();
    }

    $migrations = File::glob("{$this->outputRoot}/database/migrations/*_create_customer_profiles_table.php");

    expect($migrations)->toHaveCount(1)
        ->and(File::get("{$this->outputRoot}/app/Models/CustomerProfile.php"))
        ->toContain('namespace App\\Models;', 'class CustomerProfile extends Model', 'HasRecordLifecycle')
        ->not->toContain('LogsDomainActivity')
        ->and(File::get("{$this->outputRoot}/app/Filament/Resources/CustomerProfiles/CustomerProfileResource.php"))
        ->toContain('class CustomerProfileResource extends Resource implements HasTableExport');

    $loader = collect(ClassLoader::getRegisteredLoaders())->first();
    expect($loader)->toBeInstanceOf(ClassLoader::class);
    $loader->addPsr4('App\\', "{$this->outputRoot}/app/", true);
    $loader->addPsr4('Database\\Factories\\', "{$this->outputRoot}/database/factories/", true);

    expect(class_exists('App\\Filament\\Resources\\CustomerProfiles\\CustomerProfileResource'))
        ->toBeTrue();
});

test('generator refuses partial overwrite atomically', function (): void {
    $existing = "{$this->outputRoot}/app/Models/InvoiceLine.php";
    File::ensureDirectoryExists(dirname($existing));
    File::put($existing, 'keep me');

    $this->artisan('make:baseline-resource', [
        'name' => 'InvoiceLine',
        '--base-path' => $this->outputRoot,
        '--no-interaction' => true,
    ])->assertFailed();

    expect(File::get($existing))->toBe('keep me')
        ->and(File::exists("{$this->outputRoot}/database/factories/InvoiceLineFactory.php"))->toBeFalse();
});

test('generator rejects unsafe names', function (): void {
    $this->artisan('make:baseline-resource', [
        'name' => '../BadResource',
        '--base-path' => $this->outputRoot,
        '--no-interaction' => true,
    ])->assertFailed();

    expect(File::allFiles($this->outputRoot))->toBeEmpty();
});
