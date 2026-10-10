<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_opname_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_opname_session_id')
                ->constrained('stock_opname_sessions')
                ->cascadeOnDelete();
            $table->string('action');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->foreignUuid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at');

            $table->index(['stock_opname_session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_logs');
    }
};
