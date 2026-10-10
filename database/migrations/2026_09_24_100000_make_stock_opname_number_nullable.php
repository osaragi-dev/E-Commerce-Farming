<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_opname_sessions', function (Blueprint $table): void {
            $table->string('opname_number')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('stock_opname_sessions', function (Blueprint $table): void {
            $table->string('opname_number')->nullable(false)->change();
        });
    }
};
