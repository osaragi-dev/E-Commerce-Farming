<?php

namespace App\Livewire\Auth;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.auth')]
#[Title('Create Account | Agung Farm & Garden')]
class Register extends Component
{
    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function register(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => [
                'required',
                'string',
                'regex:/^[0-9]+$/',
                'max:20',
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'phone.regex' => 'The phone number must contain numbers only.',
        ]);

        $validated['email'] = mb_strtolower(trim($validated['email']));
        $validated['phone'] = Str::squish($validated['phone']);

        $customer = DB::transaction(function () use ($validated): Customer {
            $customerByEmail = Customer::withTrashed()
                ->whereRaw('LOWER(email) = ?', [$validated['email']])
                ->first();
            $customerByPhone = Customer::withTrashed()
                ->where('phone', $validated['phone'])
                ->first();

            if ($customerByEmail !== null
                && $customerByPhone !== null
                && $customerByEmail->isNot($customerByPhone)) {
                throw ValidationException::withMessages([
                    'phone' => 'This phone number belongs to another customer profile.',
                ]);
            }

            $customer = $customerByEmail ?? $customerByPhone;

            if (User::withTrashed()
                ->whereRaw('LOWER(email) = ?', [$validated['email']])
                ->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'This email is already used by an internal account.',
                ]);
            }

            if ($customer?->hasLoginAccount()) {
                throw ValidationException::withMessages([
                    'email' => 'An account with this email is already registered.',
                ]);
            }

            if ($customer?->trashed()) {
                $customer->restore();
            }

            $customer ??= new Customer;
            $customer->forceFill([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'],
                'password' => $validated['password'],
                'is_active' => true,
                'record_status' => 'saved',
            ])->save();

            $customer->refresh();
            Auth::guard('customer')->login($customer);

            return $customer;
        });

        $customer->sendEmailVerificationNotification();

        $this->redirect(route('verification.notice'), navigate: false);
    }

    public function render(): View
    {
        return view('livewire.auth.register');
    }
}
