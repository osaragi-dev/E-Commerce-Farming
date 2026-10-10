<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TCS revision (25 Aug 2026): Customer perlu fitur Draft/Saved,
 * Clone, dan Created/Updated By yang sama kayak Sectors/Categories/
 * Products — jadi perlu kolom Lifecycle standar juga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('record_status')->default('saved')->after('is_active');
            $table->string('previous_record_status')->nullable()->after('record_status');
            $table->foreignUuid('created_by')->nullable()->after('previous_record_status')->constrained('users');
            $table->foreignUuid('updated_by')->nullable()->after('created_by')->constrained('users');
            $table->foreignUuid('deleted_by')->nullable()->after('updated_by')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropForeign(['deleted_by']);
            $table->dropColumn(['record_status', 'previous_record_status', 'created_by', 'updated_by', 'deleted_by']);
        });
    }
};
