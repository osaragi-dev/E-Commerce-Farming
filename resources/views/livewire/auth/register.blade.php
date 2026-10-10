<div class="auth-shell">
    <div class="auth-card">
        <div class="auth-heading">
            <p class="eyebrow">From our farm to you</p>
            <h1>Create New Account</h1>
            <p class="auth-description">Join us for fresh products and a simpler way to shop.</p>
        </div>

        <form wire:submit="register" class="auth-form">
            <div class="form-field">
                <label for="register-name" class="form-label">Name <span class="text-required" aria-hidden="true">*</span></label>
                <input id="register-name" type="text" wire:model="name" autocomplete="name" required class="form-input" @error('name') aria-invalid="true" aria-describedby="register-name-error" @enderror>
                @error('name') <p id="register-name-error" class="form-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="form-field">
                <label for="register-email" class="form-label">Email <span class="text-required" aria-hidden="true">*</span></label>
                <input id="register-email" type="email" wire:model="email" autocomplete="email" required class="form-input" @error('email') aria-invalid="true" aria-describedby="register-email-error" @enderror>
                @error('email') <p id="register-email-error" class="form-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="form-field">
                <label for="register-phone" class="form-label">Phone Number <span class="text-required" aria-hidden="true">*</span></label>
                <input id="register-phone" type="tel" wire:model="phone" inputmode="numeric" autocomplete="tel" required class="form-input" @error('phone') aria-invalid="true" aria-describedby="register-phone-error" @enderror>
                @error('phone') <p id="register-phone-error" class="form-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <x-public.password-input id="register-password" label="Password" model="password" />
            <p class="form-help">Use at least 8 characters.</p>
            <x-public.password-input id="register-password-confirmation" label="Confirm Password" model="password_confirmation" />

            <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled" wire:target="register">
                <span wire:loading.remove wire:target="register">Sign Up</span>
                <span wire:loading wire:target="register">Creating account...</span>
            </button>
        </form>

        <div class="auth-divider">or</div>
        <x-public.google-button>Sign Up with Google</x-public.google-button>
    </div>

    <div class="auth-footer">
        <p>Have an account? <a href="{{ route('login') }}">Log In</a></p>
        <a href="{{ route('home') }}" class="auth-cancel-link">Cancel</a>
    </div>
</div>
