<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->unsignedInteger('stock_in_transit')->default(0)->after('stock_reserved');
        });

        Schema::table('warehouse_stocks', function (Blueprint $table): void {
            $table->unsignedInteger('stock_in_transit')->default(0)->after('stock_reserved');
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_stocks', function (Blueprint $table): void {
            $table->dropColumn('stock_in_transit');
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn('stock_in_transit');
        });
    }
};
