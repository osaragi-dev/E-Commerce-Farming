<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_sequences', function (Blueprint $table): void {
            $table->unsignedInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
        });
        Schema::create('invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('generation_token')->unique();
            $table->string('invoice_number')->unique();
            $table->unsignedInteger('sequence_year');
            $table->unsignedInteger('sequence');
            $table->unique(['sequence_year', 'sequence']);
            $table->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $table->string('customer_name');
            $table->date('invoice_date');
            $table->date('due_date');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('total', 18, 2);
            $table->json('document_payload');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignUuid('invoice_id')->nullable()->index()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('invoice_id');
        });
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_sequences');
    }
};
