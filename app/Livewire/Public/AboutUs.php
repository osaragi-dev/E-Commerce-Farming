<?php

namespace App\Livewire\Public;

use App\Models\CompanyProfile;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('About Us — Agung Farm & Garden')]
class AboutUs extends Component
{
    public ?string $selectedGalleryId = null;

    public function openGallery(string $galleryId): void
    {
        if (! Str::isUuid($galleryId)) {
            $this->selectedGalleryId = null;

            return;
        }

        $this->selectedGalleryId = CompanyProfile::current()
            ->galleries()
            ->active()
            ->whereKey($galleryId)
            ->exists()
                ? $galleryId
                : null;
    }

    public function closeGallery(): void
    {
        $this->selectedGalleryId = null;
    }

    public function render(): View
    {
        $profile = CompanyProfile::current()->load([
            'galleries' => fn ($query) => $query
                ->active()
                ->select(['id', 'company_profile_id', 'image', 'description', 'sort_order', 'created_at'])
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->orderBy('id'),
        ]);

        return view('livewire.public.about-us', [
            'profile' => $profile,
            'selectedGallery' => $profile->galleries->firstWhere('id', $this->selectedGalleryId),
        ]);
    }
}
