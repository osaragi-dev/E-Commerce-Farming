<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->foreignUuid('product_variant_id')->nullable()->change();
            $table->unsignedInteger('quantity')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->foreignUuid('product_variant_id')->nullable(false)->change();
            $table->unsignedInteger('quantity')->nullable(false)->change();
        });
    }
};
