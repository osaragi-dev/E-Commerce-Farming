<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movement_items', function (Blueprint $table): void {
            $table->unsignedInteger('quantity_shipped')->nullable()->after('quantity');
            $table->unsignedInteger('quantity_received')->nullable()->after('quantity_shipped');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movement_items', function (Blueprint $table): void {
            $table->dropColumn(['quantity_shipped', 'quantity_received']);
        });
    }
};
