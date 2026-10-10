<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->dateTime('received_at')->change();
            $table->string('receipt_number')->nullable()->change();
            $table->string('delivery_note_number')->nullable()->after('received_at');
            $table->foreignUuid('checked_by')->nullable()->after('delivery_note_number')->constrained('users')->nullOnDelete();
            $table->string('documentation_path')->nullable()->after('checked_by');
            $table->string('record_status')->default('saved')->index();
            $table->string('previous_record_status')->nullable();
            $table->string('approval_status')->default('pending')->index();
            $table->unsignedSmallInteger('approval_level')->default(0);
            $table->foreignUuid('approval_setting_id')->nullable()->constrained('approval_settings')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
        });

        DB::table('goods_receipts')->where('status', 'approved')->update([
            'approval_status' => 'approved',
            'record_status' => 'saved',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropConstrainedForeignId('approval_setting_id');
            $table->dropColumn(['record_status', 'previous_record_status', 'approval_status', 'approval_level', 'documentation_path']);
            $table->dropConstrainedForeignId('checked_by');
            $table->dropColumn('delivery_note_number');
            $table->string('receipt_number')->nullable(false)->change();
            $table->date('received_at')->change();
        });
    }
};
