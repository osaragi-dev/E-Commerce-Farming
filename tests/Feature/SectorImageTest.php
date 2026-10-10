<?php

use App\Filament\Resources\Sectors\Pages\ManageSectors;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('sector view resolves uploaded images from public storage when the default disk is local', function (): void {
    config(['filament.default_filesystem_disk' => 'local']);
    Storage::fake('local');
    Storage::fake('public');
    $path = UploadedFile::fake()->image('sector.jpg')->store('sectors', 'public');
    $sector = Sector::factory()->create(['image' => $path]);
    $actor = User::factory()->create();
    $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($actor);

    $page = Livewire::test(ManageSectors::class)->mountTableAction('view', $sector);
    $schema = $page->instance()->getSchema($page->instance()->getMountedActionSchemaName());

    expect($schema->getComponent('image')->getImageUrl($path))
        ->toBe(Storage::disk('public')->url($path));
});
