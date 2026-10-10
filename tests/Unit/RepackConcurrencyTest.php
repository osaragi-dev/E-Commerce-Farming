<?php

use App\Enums\RecordStatus;
use App\Models\ProductVariant;
use App\Models\Repack;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

test('postgres repack locks prevent overselling duplicate posting and duplicate daily numbers', function (): void {
    if (! getenv('REPACK_POSTGRES_CONCURRENCY')) {
        $this->markTestSkipped('Set REPACK_POSTGRES_CONCURRENCY=1 to run against an isolated PostgreSQL database.');
    }
    $database = 'repack_test_'.bin2hex(random_bytes(6));
    config(['database.connections.pgsql.database' => 'postgres', 'database.connections.pgsql.url' => null]);
    DB::purge('pgsql');
    $admin = DB::connection('pgsql');
    $admin->statement('CREATE DATABASE '.$database);
    $processes = [];
    try {
        config(['database.connections.repack_test' => [...config('database.connections.pgsql'), 'database' => $database, 'url' => null], 'database.default' => 'repack_test']);
        Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
        $warehouse = Warehouse::factory()->create();
        $otherWarehouse = Warehouse::factory()->create();
        $from = ProductVariant::factory()->create(['initial_stock' => 0, 'stock_available' => 4]);
        $to = ProductVariant::factory()->create(['initial_stock' => 0, 'stock_available' => 0]);
        foreach ([$warehouse, $otherWarehouse] as $location) {
            WarehouseStock::factory()->create(['warehouse_id' => $location->id, 'product_variant_id' => $from->id, 'stock_available' => 2]);
        }
        $code = <<<'PHP'
            require $argv[1].'/vendor/autoload.php';
            $app = require $argv[1].'/bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            config(['database.connections.pgsql.database' => $argv[2], 'database.connections.pgsql.url' => null, 'database.default' => 'pgsql']);
            Illuminate\Support\Facades\DB::purge('pgsql');
            try {
                if ($argv[3] === 'submit') {
                    App\Models\Repack::findOrFail($argv[4])->markAsSaved();
                } elseif ($argv[3] === 'cancel') {
                    App\Models\Repack::findOrFail($argv[4])->cancel();
                } elseif ($argv[3] === 'backfill') {
                    exit(Illuminate\Support\Facades\Artisan::call('convert:backfill-stock-out'));
                } else {
                    App\Models\Repack::create(json_decode($argv[4], true, flags: JSON_THROW_ON_ERROR));
                }
            } catch (Illuminate\Validation\ValidationException $exception) {
                exit(2);
            }
            PHP;
        $data = ['warehouse_id' => $warehouse->id, 'from_product_variant_id' => $from->id, 'to_product_variant_id' => $to->id, 'from_quantity' => 1, 'to_quantity' => 1000];
        for ($index = 0; $index < 6; $index++) {
            $payload = [...$data, 'warehouse_id' => $index % 2 ? $warehouse->id : $otherWarehouse->id];
            $process = new Process([PHP_BINARY, '-r', $code, base_path(), $database, 'create', json_encode($payload, JSON_THROW_ON_ERROR)], base_path());
            $process->setTimeout(30)->start();
            $processes[] = $process;
        }
        $codes = array_map(fn (Process $process): int => $process->wait(), $processes);
        expect(count(array_filter($codes, fn (int $code): bool => $code === 0)))->toBe(4)
            ->and(count(array_filter($codes, fn (int $code): bool => $code === 2)))->toBe(2)
            ->and(Repack::count())->toBe(4)
            ->and(Repack::pluck('repack_number')->unique()->count())->toBe(4)
            ->and($from->fresh()->stock_available)->toBe('0.000')
            ->and($from->fresh()->stock_out)->toBe('4.000')
            ->and((string) WarehouseStock::where('product_variant_id', $from->id)->sum('stock_out'))->toBe('4.000')
            ->and($to->fresh()->stock_available)->toBe('4000.000');

        $draft = Repack::create([...$data, 'from_product_variant_id' => $to->id, 'to_product_variant_id' => $from->id, 'record_status' => RecordStatus::Draft]);
        $processes = [];
        for ($index = 0; $index < 2; $index++) {
            $process = new Process([PHP_BINARY, '-r', $code, base_path(), $database, 'submit', $draft->id], base_path());
            $process->setTimeout(30)->start();
            $processes[] = $process;
        }
        foreach ($processes as $process) {
            expect($process->wait())->toBe(0);
        }
        expect($draft->stockMutations()->count())->toBe(2)
            ->and(Repack::count())->toBe(5)
            ->and($to->fresh()->stock_out)->toBe('1.000')
            ->and($from->fresh()->stock_out)->toBe('4.000');

        DB::table('repacks')->update(['stock_out_applied_at' => null]);
        DB::table('product_variants')->whereIn('id', [$from->id, $to->id])->update(['stock_out' => 0]);
        DB::table('warehouse_stocks')->whereIn('product_variant_id', [$from->id, $to->id])->update(['stock_out' => 0]);
        $processes = [];
        for ($index = 0; $index < 2; $index++) {
            $process = new Process([PHP_BINARY, '-r', $code, base_path(), $database, 'backfill'], base_path());
            $process->setTimeout(30)->start();
            $processes[] = $process;
        }
        foreach ($processes as $process) {
            expect($process->wait())->toBe(0);
        }
        expect($from->fresh()->stock_out)->toBe('4.000')
            ->and($to->fresh()->stock_out)->toBe('1.000')
            ->and(Repack::whereNotNull('stock_out_applied_at')->count())->toBe(5)
            ->and($draft->stockMutations()->count())->toBe(2);

        DB::table('repacks')->where('id', $draft->id)->update(['stock_out_applied_at' => null]);
        DB::table('product_variants')->where('id', $to->id)->update(['stock_out' => 0]);
        DB::table('warehouse_stocks')->where('warehouse_id', $warehouse->id)->where('product_variant_id', $to->id)->update(['stock_out' => 0]);
        $processes = [];
        foreach (['cancel', 'cancel', 'backfill'] as $operation) {
            $process = new Process([PHP_BINARY, '-r', $code, base_path(), $database, $operation, $draft->id], base_path());
            $process->setTimeout(30)->start();
            $processes[] = $process;
        }
        foreach ($processes as $process) {
            expect($process->wait())->toBe(0);
        }
        expect($draft->fresh()->display_status)->toBe('Cancelled')
            ->and($draft->stockMutations()->count())->toBe(4)
            ->and($to->fresh()->stock_out)->toBe('0.000')
            ->and($from->fresh()->stock_out)->toBe('4.000')
            ->and($to->fresh()->stock_available)->toBe('4000.000')
            ->and($from->fresh()->stock_available)->toBe('0.000');
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        DB::purge('repack_test');
        $admin->statement('DROP DATABASE '.$database);
    }
});
