<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_status_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->constrained();
            $table->string('event');
            $table->string('from_status');
            $table->string('to_status');
            $table->foreignUuid('changed_by_user_id')->nullable()->constrained('users');
            $table->timestamp('created_at');
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_status_logs');
    }
};
