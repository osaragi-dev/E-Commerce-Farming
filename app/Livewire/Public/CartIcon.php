<?php

namespace App\Livewire\Public;

use App\Models\Cart;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class CartIcon extends Component
{
    public string $mode = 'icon';

    #[On('cart-updated')]
    public function refresh(): void
    {
        // Kosong sengaja — cukup bikin komponen ini re-render ulang
        // (ambil hitungan Cart terbaru) tiap event 'cart-updated' lewat.
    }

    public function render(): View
    {
        $customer = auth('customer')->user();
        $count = $customer !== null
            ? Cart::forCustomer($customer)->items()->sum('quantity')
            : 0;

        return view('livewire.public.cart-icon', ['count' => $count]);
    }
}
