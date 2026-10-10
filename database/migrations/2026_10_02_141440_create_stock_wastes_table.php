<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_wastes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('waste_number')->unique();
            $table->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('pic_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('waste_date')->index();
            $table->string('source')->default('item');
            $table->decimal('quantity', 18, 3);
            $table->string('waste_type');
            $table->text('reason')->nullable();
            $table->string('photo_path')->nullable();
            $table->text('notes')->nullable();
            $table->string('record_status')->default('saved')->index();
            $table->string('previous_record_status')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approval_status')->default('pending')->index();
            $table->unsignedInteger('approval_level')->default(0);
            $table->foreignUuid('approval_setting_id')->nullable()->constrained('approval_settings')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['warehouse_id', 'waste_date']);
            $table->index(['product_variant_id', 'waste_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_wastes');
    }
};
