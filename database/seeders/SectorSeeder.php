<?php

namespace Database\Seeders;

use App\Models\Sector;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SectorSeeder extends Seeder
{
    public function run(): void
    {
        if (Sector::query()->exists()) {
            return;
        }

        collect(['Peternakan', 'Perkebunan', 'Perikanan'])
            ->each(fn (string $name, int $index) => Sector::query()->create([
                'name' => $name,
                'slug' => Str::slug($name),
                'is_active' => true,
                'sort_order' => $index,
            ]));
    }
}
