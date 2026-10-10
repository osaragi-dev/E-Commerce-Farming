<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        if (Unit::query()->exists()) {
            return;
        }

        // Dikonfirmasi supervisor SCCR (menggantikan katalog awal Bab 9
        // blueprint yang masih pakai kg/pcs/100gr).
        collect([
            ['code' => 'box', 'name' => 'Box'],
            ['code' => 'botol', 'name' => 'Botol'],
            ['code' => 'pack', 'name' => 'Pack'],
            ['code' => 'ekor', 'name' => 'Ekor'],
        ])->each(fn (array $unit) => Unit::query()->create($unit));
    }
}
