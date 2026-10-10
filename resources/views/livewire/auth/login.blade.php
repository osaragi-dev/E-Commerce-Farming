<div class="auth-shell">
    <div class="auth-card">
        <div class="auth-heading">
            <p class="eyebrow">Welcome back</p>
            <h1>Log In</h1>
            <p class="auth-description">Good food starts here. Sign in to continue shopping.</p>
        </div>

        @if (session('status'))
            <p class="form-success" role="status">{{ session('status') }}</p>
        @endif

        <form wire:submit="login" class="auth-form">
            <div class="form-field">
                <label for="login-email" class="form-label">Email <span class="text-required" aria-hidden="true">*</span></label>
                <input id="login-email" type="email" wire:model="email" autocomplete="email" required class="form-input" @error('email') aria-invalid="true" aria-describedby="login-email-error" @enderror>
                @error('email') <p id="login-email-error" class="form-error" role="alert">{{ $message }}</p> @enderror
            </div>

            <x-public.password-input id="login-password" label="Password" model="password" autocomplete="current-password" />

            <div class="auth-options">
                <label class="form-check">
                    <input type="checkbox" wire:model="remember">
                    <span>Remember me</span>
                </label>
                <a href="{{ route('password.request') }}" class="text-link">Forgot password?</a>
            </div>

            <div class="flex gap-3">
                <a href="{{ route('home') }}" class="btn btn-ghost flex-1">Cancel</a>
                <button type="submit" class="btn btn-primary flex-1" wire:loading.attr="disabled" wire:target="login">
                    <span wire:loading.remove wire:target="login">Log In</span>
                    <span wire:loading wire:target="login">Logging in...</span>
                </button>
            </div>
        </form>

        <div class="auth-divider">or</div>
        <x-public.google-button>Log In with Google</x-public.google-button>
    </div>

    <div class="auth-footer">
        <p>Don't have an account? <a href="{{ route('register') }}">Sign Up</a></p>
    </div>
</div>
