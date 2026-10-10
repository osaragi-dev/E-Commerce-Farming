<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code');
            $table->string('sku');
            $table->string('name');
            $table->string('item_type');
            $table->foreignUuid('unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignUuid('initial_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->decimal('initial_stock', 15, 3)->default(0);
            $table->decimal('min_stock', 15, 3)->default(0);
            $table->decimal('avg_purchase_price', 15, 2)->default(0);
            $table->boolean('purchasable')->default(true);
            $table->string('photo_path')->nullable();
            $table->unsignedInteger('shelf_life_days')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->string('record_status')->default('saved')->index();
            $table->string('previous_record_status')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        foreach (['code', 'sku'] as $attribute) {
            DB::statement(
                "CREATE UNIQUE INDEX items_{$attribute}_saved_unique
                ON items (lower(trim({$attribute})))
                WHERE deleted_at IS NULL AND record_status = 'saved'",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
