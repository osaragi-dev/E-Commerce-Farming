<?php

use App\Enums\ApprovalModule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('module_code')->index();
            $table->boolean('is_active')->default(true);
            $table->string('record_status')->default('saved')->index();
            $table->string('previous_record_status')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('approval_setting_levels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('approval_setting_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->unsignedSmallInteger('level');
            $table->timestamps();
            $table->unique(['approval_setting_id', 'level']);
        });

        foreach (ApprovalModule::cases() as $module) {
            DB::table('approval_settings')->insert([
                'id' => (string) Str::orderedUuid(),
                'module_code' => $module->value,
                'is_active' => true,
                'record_status' => 'saved',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_setting_levels');
        Schema::dropIfExists('approval_settings');
    }
};
