<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_galleries', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('description');
            $table->boolean('is_active')->default(true)->index()->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('company_galleries', function (Blueprint $table) {
            $table->dropColumn(['sort_order', 'is_active']);
        });
    }
};
