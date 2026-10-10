<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

test('postgres concurrent payment recreation creates one new attempt', function (): void {
    if (! getenv('PAYMENT_POSTGRES_CONCURRENCY')) {
        $this->markTestSkipped('Set PAYMENT_POSTGRES_CONCURRENCY=1 to test an isolated PostgreSQL database.');
    }
    $database = 'payment_attempt_test_'.bin2hex(random_bytes(6));
    config(['database.connections.pgsql.database' => 'postgres', 'database.connections.pgsql.url' => null]);
    DB::purge('pgsql');
    $admin = DB::connection('pgsql');
    $admin->statement('CREATE DATABASE '.$database);
    $processes = [];
    try {
        config(['database.connections.payment_test' => [...config('database.connections.pgsql'), 'database' => $database, 'url' => null], 'database.default' => 'payment_test']);
        Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
        $actor = User::factory()->create();
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
        $order = Order::factory()->create(['status' => OrderStatus::ReadyToProcess, 'payment_method' => PaymentMethod::Cash]);
        $previous = Payment::factory()->for($order)->create(['status' => PaymentStatus::Void, 'method' => PaymentMethod::Cash]);
        $code = <<<'PHP'
            require $argv[1].'/vendor/autoload.php';
            $app = require $argv[1].'/bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            config(['database.connections.pgsql.database' => $argv[2], 'database.connections.pgsql.url' => null, 'database.default' => 'pgsql']);
            Illuminate\Support\Facades\DB::purge('pgsql');
            try {
                app(App\Services\OrderPaymentRecreationService::class)->recreate(
                    App\Models\Order::findOrFail($argv[3]), App\Enums\PaymentMethod::Cash,
                    App\Models\User::findOrFail($argv[4]), $argv[5],
                );
                echo 'created';
            } catch (Illuminate\Validation\ValidationException $exception) {
                echo 'stale';
            }
            PHP;
        foreach (range(1, 2) as $worker) {
            $process = new Process([PHP_BINARY, '-r', $code, base_path(), $database, $order->id, $actor->id, $previous->id], base_path(), ['APP_ENV' => 'local', 'DB_CONNECTION' => 'pgsql']);
            $process->start();
            $processes[] = $process;
        }
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $results[] = trim($process->getOutput());
        }
        expect($results)->toEqualCanonicalizing(['created', 'stale']);
        expect($order->fresh()->payment->attempt_number)->toBe(2);
        expect(Order::with('payment')->findOrFail($order->id)->payment->status)->toBe(PaymentStatus::Unpaid);
        expect(Order::whereKey($order->id)->whereHas('payment', fn ($query) => $query->where('status', PaymentStatus::Void))->exists())->toBeFalse();
        expect($order->payments()->count())->toBe(2);
        expect($previous->fresh()->status)->toBe(PaymentStatus::Void);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        DB::purge('payment_test');
        $admin->statement('DROP DATABASE '.$database.' WITH (FORCE)');
    }
});
