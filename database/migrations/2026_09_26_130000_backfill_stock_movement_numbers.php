<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('stock_movements')
            ->where('status', '!=', 'draft')
            ->get(['id', 'movement_number', 'created_at'])
            ->each(function (object $movement): void {
                if (is_string($movement->movement_number)
                    && preg_match('/^SM-\d{8}-[A-Z0-9]{12}$/', $movement->movement_number) === 1) {
                    return;
                }

                $date = Carbon::parse($movement->created_at ?? now())->format('Ymd');
                do {
                    $suffix = Str::upper(Str::substr(
                        str_replace('-', '', (string) Str::uuid7()),
                        -12,
                    ));
                    $number = "SM-{$date}-{$suffix}";
                } while (DB::table('stock_movements')->where('movement_number', $number)->exists());

                DB::table('stock_movements')
                    ->where('id', $movement->id)
                    ->update(['movement_number' => $number]);
            });
    }

    public function down(): void
    {
        // Existing movement numbers are valid business identifiers and must not be removed.
    }
};
