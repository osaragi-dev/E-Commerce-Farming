<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direct_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('do_number')->nullable();
            $table->date('do_date');
            $table->string('payment_by');
            $table->text('reason');
            $table->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('terms_conditions')->nullable();
            $table->string('transaction_proof');
            $table->string('approval_status')->default('pending')->index();
            $table->unsignedSmallInteger('approval_level')->default(0);
            $table->foreignUuid('approval_setting_id')->nullable()->constrained('approval_settings')->nullOnDelete();
            $table->string('record_status')->default('saved')->index();
            $table->string('previous_record_status')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['record_status', 'do_date']);
        });

        DB::statement(
            "CREATE UNIQUE INDEX direct_orders_number_saved_unique
            ON direct_orders (lower(trim(do_number)))
            WHERE do_number IS NOT NULL AND deleted_at IS NULL AND record_status = 'saved'",
        );

        Schema::create('direct_order_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('direct_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('total_price', 14, 2);
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['direct_order_id', 'sort_order']);
        });

        Schema::create('direct_order_number_sequences', function (Blueprint $table): void {
            $table->date('sequence_date')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_order_number_sequences');
        Schema::dropIfExists('direct_order_items');
        Schema::dropIfExists('direct_orders');
    }
};
