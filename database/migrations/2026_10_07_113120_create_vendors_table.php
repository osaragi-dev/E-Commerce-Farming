<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('vendor_code');
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('payment_terms')->nullable();
            $table->unsignedInteger('lead_time_days')->default(0);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->string('approval_status')->default('pending')->index();
            $table->unsignedSmallInteger('approval_level')->default(0);
            $table->foreignUuid('approval_setting_id')->nullable()->constrained('approval_settings')->nullOnDelete();
            $table->string('record_status')->default('saved')->index();
            $table->string('previous_record_status')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement(
            "CREATE UNIQUE INDEX vendors_code_saved_unique
            ON vendors (lower(trim(vendor_code)))
            WHERE deleted_at IS NULL AND record_status = 'saved'",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
