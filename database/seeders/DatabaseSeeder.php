<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->call(UnitSeeder::class);
        $this->call(SectorSeeder::class);
        $this->call(CategorySeeder::class);

        if (app()->isLocal()) {
            $this->call(ProductSeeder::class);
        }
    }
}
