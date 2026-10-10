<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('sso_id')->nullable()->unique();
            $table->string('password')->nullable()->change();
        });
        Schema::table('roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('sso_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique(['sso_id']);
            $table->dropColumn('sso_id');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['sso_id']);
            $table->dropColumn('sso_id');
        });
    }
};
