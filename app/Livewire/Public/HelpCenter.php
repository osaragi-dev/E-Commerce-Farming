<?php

namespace App\Livewire\Public;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Help — Agung Farm & Garden')]
class HelpCenter extends Component
{
    public function render(): View
    {
        return view('livewire.public.help-center');
    }
}
