<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipt_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('purchase_order_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_received', 12, 3);
            $table->decimal('accepted_quantity', 12, 3);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['goods_receipt_id', 'purchase_order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
    }
};
