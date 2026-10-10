<div class="auth-shell">
    <div class="auth-card">
        <div class="auth-heading">
            <p class="eyebrow">Account recovery</p>
            <h1>Forgot Password</h1>
            <p class="auth-description">Step {{ $step }} of 3. {{ $step === 1 ? 'Find your account.' : ($step === 2 ? 'Verify your email.' : 'Choose a new password.') }}</p>
        </div>

        @if ($step === 1)
            <form wire:submit="findAccount" class="auth-form">
                <div class="form-field">
                    <label for="reset-email" class="form-label">Registered Email <span class="text-required" aria-hidden="true">*</span></label>
                    <input id="reset-email" type="email" wire:model="email" autocomplete="email" required class="form-input" @error('email') aria-invalid="true" aria-describedby="reset-email-error" @enderror>
                    @error('email') <p id="reset-email-error" class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="flex gap-3">
                    <a href="{{ route('login') }}" class="btn btn-ghost flex-1">Cancel</a>
                    <button type="submit" class="btn btn-primary flex-1" wire:loading.attr="disabled" wire:target="findAccount">
                        <span wire:loading.remove wire:target="findAccount">Continue</span>
                        <span wire:loading wire:target="findAccount">Sending code...</span>
                    </button>
                </div>
            </form>
        @elseif ($step === 2)
            <p class="auth-description">We sent a 6-digit verification code to <strong>{{ $email }}</strong>.</p>
            <form wire:submit="verifyEmail" class="auth-form">
                <div class="form-field">
                    <label for="reset-code" class="form-label">Verification Code <span class="text-required" aria-hidden="true">*</span></label>
                    <input id="reset-code" type="text" inputmode="numeric" autocomplete="one-time-code" wire:model="verificationCode" required class="form-input" maxlength="6" @error('verificationCode') aria-invalid="true" aria-describedby="reset-code-error" @enderror>
                    @error('verificationCode') <p id="reset-code-error" class="form-error" role="alert">{{ $message }}</p> @enderror
                    @error('email') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <button type="button" wire:click="findAccount" class="btn btn-ghost" wire:loading.attr="disabled" wire:target="findAccount">Resend Code</button>
                <div class="flex gap-3">
                    <a href="{{ route('login') }}" class="btn btn-ghost flex-1">Cancel</a>
                    <button type="submit" class="btn btn-primary flex-1" wire:loading.attr="disabled" wire:target="verifyEmail">Verify Email</button>
                </div>
            </form>
        @else
            <form wire:submit="resetPassword" class="auth-form">
                <x-public.password-input id="reset-password" label="New Password" model="password" />
                <p class="form-help">Use at least 8 characters.</p>
                <x-public.password-input id="reset-password-confirmation" label="Confirm New Password" model="password_confirmation" />
                <div class="flex gap-3">
                    <a href="{{ route('login') }}" class="btn btn-ghost flex-1">Cancel</a>
                    <button type="submit" class="btn btn-primary flex-1" wire:loading.attr="disabled" wire:target="resetPassword">Save New Password</button>
                </div>
            </form>
        @endif
    </div>

    <div class="auth-footer">
        <a href="{{ route('login') }}">Back to Login</a>
    </div>
</div>
