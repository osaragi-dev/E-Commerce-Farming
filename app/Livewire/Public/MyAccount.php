<?php

namespace App\Livewire\Public;

use App\Enums\ShippingLocation;
use App\Models\Customer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

#[Title('My Account | Agung Farm & Garden')]
class MyAccount extends Component
{
    use WithFileUploads;

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public bool $isEditing = false;

    public ?string $profilePhotoPath = null;

    public $profilePhoto = null;

    public function mount(): void
    {
        $customer = $this->authenticatedCustomer();

        $this->name = $customer->name;
        $this->phone = $customer->phone ?? '';
        $this->email = $customer->email ?? '';
        $this->address = $customer->address ?? '';
        $this->profilePhotoPath = $customer->profile_photo_path;
    }

    public function startEditing(): void
    {
        $this->isEditing = true;
        $this->resetValidation();
    }

    public function save(): void
    {
        $customer = $this->authenticatedCustomer();
        $validated = $this->validate($this->rules($customer));
        $oldProfilePhotoPath = $customer->profile_photo_path;
        $newProfilePhotoPath = $oldProfilePhotoPath;

        if ($this->profilePhoto !== null) {
            $newProfilePhotoPath = $this->profilePhoto->store('profile-photos', 'public');
        }

        try {
            DB::transaction(function () use ($customer, $validated, $newProfilePhotoPath): void {
                $emailChanged = $customer->email !== $validated['email'];

                $customer->update([
                    'name' => $validated['name'],
                    'phone' => $validated['phone'],
                    'email' => $validated['email'],
                    'address' => $validated['address'],
                    'profile_photo_path' => $newProfilePhotoPath,
                    'email_verified_at' => $emailChanged ? null : $customer->email_verified_at,
                ]);
            });
        } catch (Throwable $exception) {
            if ($newProfilePhotoPath !== $oldProfilePhotoPath) {
                Storage::disk('public')->delete($newProfilePhotoPath);
            }

            throw $exception;
        }

        if ($oldProfilePhotoPath !== null && $newProfilePhotoPath !== $oldProfilePhotoPath) {
            Storage::disk('public')->delete($oldProfilePhotoPath);
        }

        $this->profilePhotoPath = $newProfilePhotoPath;
        $this->reset('profilePhoto');
        $this->isEditing = false;
        $this->dispatch('profile-updated', message: 'Profile updated successfully.');
    }

    public function cancelEdit(): void
    {
        $customer = $this->authenticatedCustomer();

        $this->name = $customer->name;
        $this->phone = $customer->phone ?? '';
        $this->email = $customer->email ?? '';
        $this->address = $customer->address ?? '';
        $this->profilePhotoPath = $customer->profile_photo_path;
        $this->reset('profilePhoto');
        $this->resetValidation();
        $this->isEditing = false;
    }

    public function render(): View
    {
        return view('livewire.public.my-account', [
            'addressOptions' => ShippingLocation::options(),
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(Customer $customer): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => [
                'required',
                'string',
                'regex:/^[0-9]+$/',
                'max:20',
                Rule::unique('customers', 'phone')->whereNull('deleted_at')->ignore($customer->getKey()),
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('customers', 'email')->whereNull('deleted_at')->ignore($customer->getKey()),
            ],
            'address' => ['required', Rule::in(array_keys(ShippingLocation::options()))],
            'profilePhoto' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:2048',
            ],
        ];
    }

    private function authenticatedCustomer(): Customer
    {
        $customer = auth('customer')->user();

        if (! $customer instanceof Customer) {
            abort(403);
        }

        return $customer;
    }
}
