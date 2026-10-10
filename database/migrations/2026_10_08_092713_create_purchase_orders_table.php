<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('po_number')->nullable();
            $table->date('po_date');
            $table->date('expected_delivery_date')->nullable();
            $table->foreignUuid('purchase_request_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('vendor_id')->constrained()->restrictOnDelete();
            $table->string('payment_by');
            $table->string('payment_basis');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->text('terms_and_conditions')->nullable();
            $table->text('notes')->nullable();
            $table->string('quotation_file_path')->nullable();
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
            $table->index(['record_status', 'po_date']);
        });

        DB::statement(
            "CREATE UNIQUE INDEX purchase_orders_number_saved_unique
            ON purchase_orders (lower(trim(po_number)))
            WHERE po_number IS NOT NULL AND deleted_at IS NULL AND record_status = 'saved'",
        );

        Schema::create('purchase_order_number_sequences', function (Blueprint $table): void {
            $table->date('sequence_date')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_number_sequences');
        Schema::dropIfExists('purchase_orders');
    }
};
