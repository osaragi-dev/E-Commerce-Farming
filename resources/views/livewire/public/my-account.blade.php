<div>
    <section class="py-8 md:py-12">
        <div class="wrap">
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <a href="{{ route('home') }}">Home</a>
                <span>/</span>
                <span class="breadcrumb-current" aria-current="page">My Account</span>
            </nav>

            <div class="account-layout flex gap-6 mt-6">
                <x-account.sidebar />

                <div class="account-main">
                    <div class="account-card">
                        <div class="account-card-heading">
                            <div>
                                <p class="eyebrow">Profile</p>
                                <h1 class="page-heading">My Account</h1>
                                <p>Keep your contact and delivery details up to date.</p>
                            </div>

                            @if (! $isEditing)
                                <button
                                    type="button"
                                    wire:click="startEditing"
                                    class="account-edit-button"
                                    aria-label="Edit profile"
                                >
                                    <svg class="account-edit-icon" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M12 20h9" />
                                        <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5Z" />
                                    </svg>
                                    <span>Edit</span>
                                </button>
                            @endif
                        </div>

                        @if ($isEditing)
                            <form wire:submit="save" class="account-form">
                                <div class="profile-photo-panel">
                                    <div class="profile-avatar profile-avatar-large">
                                        @if ($profilePhoto)
                                            <img src="{{ $profilePhoto->temporaryUrl() }}" alt="Profile photo preview">
                                        @elseif ($profilePhotoPath)
                                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($profilePhotoPath) }}" alt="{{ $name }}">
                                        @else
                                            {{ \Illuminate\Support\Str::of($name ?: 'U')->substr(0, 1)->upper() }}
                                        @endif
                                    </div>

                                    <div class="form-field profile-photo-field">
                                        <label class="form-label" for="profile-photo">Profile Photo</label>
                                        <input
                                            id="profile-photo"
                                            type="file"
                                            wire:model="profilePhoto"
                                            accept="image/jpeg,image/png,image/webp"
                                            aria-describedby="profile-photo-help profile-photo-error"
                                            class="form-input"
                                        >
                                        <p class="form-help" id="profile-photo-help">JPG, PNG, or WEBP. Maximum size 2 MB.</p>
                                        <p wire:loading wire:target="profilePhoto" class="form-help" role="status">Uploading photo...</p>
                                        @error('profilePhoto') <p id="profile-photo-error" class="form-error" role="alert">{{ $message }}</p> @enderror
                                    </div>
                                </div>

                                <div class="account-form-grid">
                                    <div class="form-field">
                                        <label class="form-label" for="name">Name <span class="text-required" aria-hidden="true">*</span></label>
                                        <input id="name" type="text" wire:model="name" class="form-input" autocomplete="name" required @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                                        @error('name') <p id="name-error" class="form-error" role="alert">{{ $message }}</p> @enderror
                                    </div>

                                    <div class="form-field">
                                        <label class="form-label" for="phone">Phone Number <span class="text-required" aria-hidden="true">*</span></label>
                                        <input id="phone" type="tel" wire:model="phone" class="form-input" autocomplete="tel" inputmode="numeric" required @error('phone') aria-invalid="true" aria-describedby="phone-error" @enderror>
                                        @error('phone') <p id="phone-error" class="form-error" role="alert">{{ $message }}</p> @enderror
                                    </div>

                                    <div class="form-field">
                                        <label class="form-label" for="email">Email <span class="text-required" aria-hidden="true">*</span></label>
                                        <input id="email" type="email" wire:model="email" class="form-input" autocomplete="email" required @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                                        @error('email') <p id="email-error" class="form-error" role="alert">{{ $message }}</p> @enderror
                                    </div>

                                    <div class="form-field account-form-field-full">
                                        <label class="form-label" for="address">Address <span class="text-required" aria-hidden="true">*</span></label>
                                        <select id="address" wire:model="address" class="form-input" required @error('address') aria-invalid="true" aria-describedby="address-error" @enderror>
                                            <option value="">Select an address</option>
                                            @foreach ($addressOptions as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        @error('address') <p id="address-error" class="form-error" role="alert">{{ $message }}</p> @enderror
                                    </div>
                                </div>

                                <div class="account-form-actions">
                                    <span class="form-help"><span class="text-required" aria-hidden="true">*</span> Required fields</span>
                                    <div class="flex gap-3">
                                        <button type="button" wire:click="cancelEdit" class="btn btn-ghost">Cancel</button>
                                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save,profilePhoto">
                                            <span wire:loading.remove wire:target="save">Save Changes</span>
                                            <span wire:loading wire:target="save">Saving...</span>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        @else
                            <div class="profile-photo-panel">
                                <div class="profile-avatar profile-avatar-large">
                                    @if ($profilePhotoPath)
                                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($profilePhotoPath) }}" alt="{{ $name }}">
                                    @else
                                        {{ \Illuminate\Support\Str::of($name ?: 'U')->substr(0, 1)->upper() }}
                                    @endif
                                </div>

                                <div class="profile-photo-summary">
                                    <p class="form-label">Profile Photo</p>
                                    <p class="form-help">Use Edit to update your profile photo.</p>
                                </div>
                            </div>

                            <dl class="profile-summary">
                                <div class="profile-summary-item">
                                    <dt class="profile-summary-label">Name</dt>
                                    <dd class="profile-summary-value">{{ $name ?: '-' }}</dd>
                                </div>

                                <div class="profile-summary-item">
                                    <dt class="profile-summary-label">Phone Number</dt>
                                    <dd class="profile-summary-value">{{ $phone ?: '-' }}</dd>
                                </div>

                                <div class="profile-summary-item">
                                    <dt class="profile-summary-label">Email</dt>
                                    <dd class="profile-summary-value">{{ $email ?: '-' }}</dd>
                                </div>

                                <div class="profile-summary-item profile-summary-item-full">
                                    <dt class="profile-summary-label">Address</dt>
                                    <dd class="profile-summary-value">{{ $addressOptions[$address] ?? ($address ?: '-') }}</dd>
                                </div>
                            </dl>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
