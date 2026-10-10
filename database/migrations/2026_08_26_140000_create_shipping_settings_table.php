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
        Schema::create('shipping_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->decimal('flat_shipping_cost', 15, 2)->default(15000);
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Singleton, sama seperti company_profiles — seed 1 baris supaya
        // ShippingSetting::current() selalu punya record untuk diedit.
        DB::table('shipping_settings')->insert([
            'id' => (string) Str::orderedUuid(),
            'flat_shipping_cost' => 15000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_settings');
    }
};
