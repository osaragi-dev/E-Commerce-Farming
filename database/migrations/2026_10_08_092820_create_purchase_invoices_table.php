<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('invoice_number')->nullable();
            $table->foreignUuid('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('vendor_id')->constrained()->restrictOnDelete();
            $table->date('invoice_date');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->string('vendor_invoice_file_path')->nullable();
            $table->text('notes')->nullable();
            $table->string('record_status')->default('saved')->index();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['purchase_order_id', 'invoice_date']);
        });

        DB::statement(
            "CREATE UNIQUE INDEX purchase_invoices_number_saved_unique
            ON purchase_invoices (lower(trim(invoice_number)))
            WHERE invoice_number IS NOT NULL AND deleted_at IS NULL AND record_status = 'saved'",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoices');
    }
};
