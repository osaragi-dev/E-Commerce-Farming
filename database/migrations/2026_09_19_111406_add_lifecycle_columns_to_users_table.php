<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('record_status')->default('saved')->index()->after('is_active');
            $table->string('previous_record_status')->nullable()->after('record_status');
            $table->foreignUuid('deleted_by')->nullable()->after('updated_by')->constrained('users')->nullOnDelete();
        });

        DB::table('users')
            ->whereNull('deleted_at')
            ->update([
                'record_status' => 'saved',
                'previous_record_status' => null,
            ]);

        DB::table('users')
            ->whereNotNull('deleted_at')
            ->update([
                'record_status' => 'deleted',
                'previous_record_status' => 'saved',
            ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['deleted_by']);
            $table->dropColumn(['record_status', 'previous_record_status', 'deleted_by']);
        });
    }
};
