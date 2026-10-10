<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Unit now lives on `products` (added in
        // 2026_08_13_000007_add_unit_to_products_table). Every variant of a
        // product uses the same unit per Bab 8's catalog, so keeping unit_id
        // on both tables risked the two disagreeing with each other.
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->foreignUuid('unit_id')->after('product_id')->nullable()->constrained('units')->restrictOnDelete();
        });
    }
};
