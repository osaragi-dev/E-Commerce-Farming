<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('gender');
        });
    }

    /**
     * Restore the column structure only. Values removed by up() cannot be recovered.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('gender')->nullable()->after('address');
        });
    }
};
