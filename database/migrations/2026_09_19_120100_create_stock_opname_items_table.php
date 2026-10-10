<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_opname_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_opname_session_id')
                ->constrained('stock_opname_sessions')
                ->cascadeOnDelete();
            $table->foreignUuid('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('system_qty');
            $table->unsignedInteger('physical_qty')->nullable();
            $table->integer('variance_qty')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['stock_opname_session_id', 'product_variant_id']);
            $table->index(['product_variant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_items');
    }
};
