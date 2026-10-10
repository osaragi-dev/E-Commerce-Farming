<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_display_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedSmallInteger('new_badge_days')->default(14);
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('product_display_settings')->insert([
            'id' => (string) Str::orderedUuid(),
            'new_badge_days' => 14,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_display_settings');
    }
};
