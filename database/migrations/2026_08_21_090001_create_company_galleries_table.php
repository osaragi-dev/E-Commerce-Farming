<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_galleries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_profile_id')->constrained('company_profiles')->cascadeOnDelete();
            $table->string('image');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_galleries');
    }
};
