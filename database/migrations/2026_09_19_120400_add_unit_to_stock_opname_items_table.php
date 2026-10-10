<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_opname_items', function (Blueprint $table): void {
            $table->string('unit')->nullable()->after('variance_qty');
        });
    }

    public function down(): void
    {
        Schema::table('stock_opname_items', function (Blueprint $table): void {
            $table->dropColumn('unit');
        });
    }
};
