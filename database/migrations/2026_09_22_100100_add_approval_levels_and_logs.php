<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->unsignedSmallInteger('approval_level')->default(0)->after('approval_status');
        });

        Schema::table('stock_opname_sessions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('approval_level')->default(0)->after('approval_status');
        });

        Schema::create('approval_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('approvable_type');
            $table->uuid('approvable_id');
            $table->string('module_code');
            $table->unsignedSmallInteger('level');
            $table->string('action');
            $table->foreignUuid('acted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['approvable_type', 'approvable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_logs');
        Schema::table('stock_opname_sessions', function (Blueprint $table): void {
            $table->dropColumn('approval_level');
        });
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropColumn('approval_level');
        });
    }
};
