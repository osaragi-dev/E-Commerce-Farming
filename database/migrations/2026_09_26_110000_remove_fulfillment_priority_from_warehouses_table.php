<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table): void {
            $table->dropIndex('warehouses_fulfillment_priority_index');
            $table->dropColumn('fulfillment_priority');
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table): void {
            $table->unsignedInteger('fulfillment_priority')->default(100)->after('is_pos')->index();
        });
    }
};
