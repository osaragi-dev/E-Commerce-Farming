<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repacks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('repack_number')->nullable();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('from_product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignUuid('to_product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('from_quantity', 18, 3);
            $table->decimal('to_quantity', 18, 3);
            $table->string('from_unit');
            $table->string('to_unit');
            $table->text('notes')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->string('record_status')->default('saved')->index();
            $table->string('previous_record_status')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement(
            "CREATE UNIQUE INDEX repacks_repack_number_saved_unique
            ON repacks (repack_number)
            WHERE deleted_at IS NULL AND record_status = 'saved'",
        );
        Schema::create('repack_sequences', function (Blueprint $table) {
            $table->string('date', 8)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repacks');
        Schema::dropIfExists('repack_sequences');
    }
};
