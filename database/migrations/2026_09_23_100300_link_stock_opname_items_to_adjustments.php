<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_opname_items', function (Blueprint $table): void {
            $table->foreignUuid('stock_adjustment_id')
                ->nullable()
                ->unique()
                ->after('notes')
                ->constrained('stock_adjustments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_opname_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('stock_adjustment_id');
        });
    }
};
