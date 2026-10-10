<?php

namespace App\Livewire\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.auth')]
#[Title('Log In | Agung Farm & Garden')]
class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $credentials = $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (Auth::guard('customer')->attempt([
            ...$credentials,
            'is_active' => true,
        ], $this->remember)) {
            if (request()->hasSession()) {
                request()->session()->regenerate();
            }

            $customer = Auth::guard('customer')->user();

            if (! $customer->hasVerifiedEmail()) {
                $customer->sendEmailVerificationNotification();
                $this->redirect(route('verification.notice'), navigate: false);

                return;
            }

            $this->redirect(route('home'), navigate: false);

            return;
        }

        $this->addError('email', 'Incorrect email or password.');
    }

    public function render(): View
    {
        return view('livewire.auth.login');
    }
}
