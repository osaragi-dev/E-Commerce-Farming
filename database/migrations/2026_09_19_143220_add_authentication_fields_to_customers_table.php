<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('password')->nullable()->after('email');
            $table->rememberToken()->after('password');
            $table->timestamp('email_verified_at')->nullable()->after('remember_token');
            $table->string('google_id')->nullable()->unique()->after('email_verified_at');
            $table->string('profile_photo_path')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropUnique(['google_id']);
            $table->dropColumn([
                'password',
                'remember_token',
                'email_verified_at',
                'google_id',
                'profile_photo_path',
            ]);
        });
    }
};
