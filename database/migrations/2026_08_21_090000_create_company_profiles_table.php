<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_name');
            $table->text('description')->nullable();
            $table->text('address')->nullable();
            $table->string('whatsapp_number')->nullable();
            $table->string('email')->nullable();
            $table->string('instagram')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Company Profile bersifat singleton (Bab: Settings). Seed satu baris
        // di sini supaya aplikasi selalu punya tepat 1 record untuk dikelola
        // Admin, tanpa perlu logic "create if not exists" tersebar di banyak
        // tempat. Halaman edit-nya sendiri tidak pernah expose Create/Delete.
        DB::table('company_profiles')->insert([
            'id' => (string) Str::orderedUuid(),
            'company_name' => 'Nama Perusahaan',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('company_profiles');
    }
};
