<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->unsignedInteger('stock_available')->default(0)->after('price');
            $table->unsignedInteger('stock_reserved')->default(0)->after('stock_available');
            $table->unsignedInteger('stock_out')->default(0)->after('stock_reserved');
        });

        DB::statement('UPDATE product_variants SET stock_available = stock');

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn('stock');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->unsignedInteger('stock')->default(0)->after('price');
        });

        DB::statement('UPDATE product_variants SET stock = stock_available');

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn(['stock_available', 'stock_reserved', 'stock_out']);
        });
    }
};
