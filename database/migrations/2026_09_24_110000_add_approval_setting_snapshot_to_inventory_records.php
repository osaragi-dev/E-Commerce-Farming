<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['stock_adjustments', 'stock_movements', 'stock_opname_sessions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignUuid('approval_setting_id')
                    ->nullable()
                    ->after('approval_status')
                    ->constrained('approval_settings')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['stock_adjustments', 'stock_movements', 'stock_opname_sessions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('approval_setting_id');
            });
        }
    }
};
