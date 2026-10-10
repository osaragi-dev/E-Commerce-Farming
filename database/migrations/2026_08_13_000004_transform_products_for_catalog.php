<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Demo seed data from the boilerplate example (ProductSeeder) is not
        // real business data, so it's safe to clear before reshaping the table.
        DB::table('products')->delete();

        DB::statement('DROP INDEX IF EXISTS products_code_saved_unique');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['code', 'price']);
            $table->foreignUuid('category_id')->after('id')->constrained('categories')->restrictOnDelete();
            $table->string('slug')->after('name');
        });

        DB::statement(
            "CREATE UNIQUE INDEX products_slug_saved_unique
            ON products (slug)
            WHERE deleted_at IS NULL AND record_status = 'saved'",
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_slug_saved_unique');

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn('slug');
            $table->string('code')->after('id');
            $table->decimal('price', 15, 2)->default(0);
        });

        DB::statement(
            "CREATE UNIQUE INDEX products_code_saved_unique
            ON products (code)
            WHERE deleted_at IS NULL AND record_status = 'saved'",
        );
    }
};
