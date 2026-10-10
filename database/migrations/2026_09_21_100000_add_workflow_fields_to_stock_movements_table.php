<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->string('movement_number')->nullable()->unique();
            $table->string('approval_status')->default('pending')->index();
            $table->string('status')->default('requested')->index();
            $table->dateTime('movement_date')->nullable()->index();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('updated_at')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropForeign(['requested_by']);
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropUnique(['movement_number']);
            $table->dropColumn([
                'movement_number',
                'approval_status',
                'status',
                'movement_date',
                'requested_by',
                'shipped_at',
                'received_at',
                'notes',
                'created_by',
                'updated_at',
                'updated_by',
            ]);
        });
    }
};
