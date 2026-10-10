<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('goods_receipt_items', function (Blueprint $table): void {
            $table->decimal('rejected_quantity', 12, 3)->default(0)->after('accepted_quantity');
            $table->string('condition')->nullable()->after('rejected_quantity');
            $table->text('rejection_reason')->nullable()->after('condition');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('goods_receipt_items', function (Blueprint $table): void {
            $table->dropColumn(['rejected_quantity', 'condition', 'rejection_reason']);
        });
    }
};
