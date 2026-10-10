<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->timestamp('activated_at')->nullable()->after('is_active')->index();
        });

        DB::table('products')
            ->where('is_active', true)
            ->update(['activated_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('activated_at');
        });
    }
};
