<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movement_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_movement_id')->constrained('stock_movements')->cascadeOnDelete();
            $table->string('action');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->foreignUuid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['stock_movement_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movement_logs');
    }
};
