<?php

namespace App\Livewire\Public;

use App\Models\Product;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SearchBar extends Component
{
    public string $query = '';

    public function mount(): void
    {
        // Kalau lagi di halaman hasil pencarian (URL punya ?q=...), kotak
        // pencarian di header ikut nunjukin kata kunci itu — bukan kosong
        // lagi seolah-olah belum pernah dicari.
        $this->query = (string) request()->query('q', '');
    }

    #[Computed]
    public function suggestions()
    {
        $term = trim($this->query);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        return Product::query()
            ->with(['images' => fn ($q) => $q->orderBy('sort_order')])
            ->saved()
            ->where('is_active', true)
            ->whereHas('variants', fn ($query) => $query->saved()->where('is_active', true))
            ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($term).'%'])
            ->limit(6)
            ->get();
    }

    public function goToResults()
    {
        return redirect()->route('catalog', ['q' => $this->query]);
    }

    public function render(): View
    {
        return view('livewire.public.search-bar');
    }
}
