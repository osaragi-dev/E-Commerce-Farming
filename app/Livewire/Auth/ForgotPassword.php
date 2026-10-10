<?php

namespace App\Livewire\Auth;

use App\Models\Customer;
use App\Notifications\PasswordResetVerificationCode;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.auth')]
#[Title('Forgot Password | Agung Farm & Garden')]
class ForgotPassword extends Component
{
    public int $step = 1;

    public string $email = '';

    public string $verificationCode = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function findAccount(): void
    {
        $this->validate(['email' => ['required', 'email']]);

        $this->email = mb_strtolower(trim($this->email));

        $throttleKey = 'password-reset-code:'.hash('sha256', $this->email.'|'.request()->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $this->addError('email', 'Too many requests. Please try again later.');

            return;
        }

        RateLimiter::hit($throttleKey, 60);

        $customer = Customer::query()
            ->whereRaw('LOWER(email) = ?', [$this->email])
            ->where(function ($query): void {
                $query->whereNotNull('password')->orWhereNotNull('google_id');
            })
            ->first();

        if ($customer === null) {
            $this->addError('email', 'Email not found.');

            return;
        }

        $code = (string) random_int(100000, 999999);

        Cache::put($this->verificationKey(), [
            'code' => Hash::make($code),
            'attempts' => 0,
        ], now()->addMinutes(10));

        $customer->notify(new PasswordResetVerificationCode($code));

        $this->step = 2;
    }

    public function verifyEmail(): void
    {
        $this->validate([
            'verificationCode' => ['required', 'digits:6'],
        ]);

        $verification = Cache::get($this->verificationKey());

        if (! is_array($verification) || ! isset($verification['code'], $verification['attempts'])) {
            $this->addError('verificationCode', 'This verification code has expired. Please request a new code.');

            return;
        }

        if ($verification['attempts'] >= 5) {
            Cache::forget($this->verificationKey());
            $this->addError('verificationCode', 'Too many attempts. Please request a new code.');

            return;
        }

        if (! Hash::check($this->verificationCode, $verification['code'])) {
            Cache::put($this->verificationKey(), [
                ...$verification,
                'attempts' => $verification['attempts'] + 1,
            ], now()->addMinutes(10));
            $this->addError('verificationCode', 'The verification code is incorrect.');

            return;
        }

        Cache::forget($this->verificationKey());
        Cache::put($this->verifiedKey(), true, now()->addMinutes(10));
        $this->step = 3;
    }

    public function resetPassword(): void
    {
        if (! Cache::has($this->verifiedKey())) {
            $this->step = 2;
            $this->addError('verificationCode', 'Please verify your email before changing the password.');

            return;
        }

        $this->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        Customer::query()
            ->whereRaw('LOWER(email) = ?', [$this->email])
            ->firstOrFail()
            ->forceFill(['password' => $this->password])
            ->save();

        Cache::forget($this->verifiedKey());

        session()->flash('status', 'Password successfully changed, please log in.');

        $this->redirect(route('login'), navigate: false);
    }

    public function render(): View
    {
        return view('livewire.auth.forgot-password');
    }

    private function verificationKey(): string
    {
        return 'password-reset-verification:'.hash('sha256', $this->email);
    }

    private function verifiedKey(): string
    {
        return 'password-reset-verified:'.hash('sha256', $this->email);
    }
}
