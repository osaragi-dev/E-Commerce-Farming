<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_stocks', function (Blueprint $table): void {
            $table->string('stock_alert_status')->nullable()->after('stock_available');
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_stocks', function (Blueprint $table): void {
            $table->dropColumn('stock_alert_status');
        });
    }
};
