<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->unsignedInteger('attempt_number')->default(1);
            $table->dropUnique(['order_id']);
            $table->unique(['order_id', 'attempt_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('payments')->select('order_id')->groupBy('order_id')->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('Cannot roll back payment attempts while payment history exists.');
        }
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['order_id', 'attempt_number']);
            $table->unique('order_id');
            $table->dropColumn('attempt_number');
        });
    }
};
