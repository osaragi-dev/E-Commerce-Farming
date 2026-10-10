@props([
    'id',
    'label',
    'model',
    'autocomplete' => 'new-password',
])

<div {{ $attributes->class(['form-field']) }}>
    <label for="{{ $id }}" class="form-label">{{ $label }} <span class="text-required" aria-hidden="true">*</span></label>
    <div class="password-input-wrapper" x-data="{ show: false }">
        <input
            id="{{ $id }}"
            type="password"
            x-bind:type="show ? 'text' : 'password'"
            wire:model="{{ $model }}"
            autocomplete="{{ $autocomplete }}"
            class="form-input"
            required
            @error($model) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror
        >
        <button
            type="button"
            x-on:click="show = !show"
            class="password-toggle"
            aria-label="Show password"
            aria-pressed="false"
            x-bind:aria-label="show ? 'Hide password' : 'Show password'"
            x-bind:aria-pressed="show"
            aria-controls="{{ $id }}"
        >
            <svg x-show="!show" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true">
                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"></path>
                <circle cx="12" cy="12" r="3"></circle>
            </svg>
            <svg x-show="show" x-cloak xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true">
                <path d="m3 3 18 18M10.6 5.1A12 12 0 0 1 12 5c6.5 0 10 7 10 7a19 19 0 0 1-3.1 4M6.2 6.2A20 20 0 0 0 2 12s3.5 7 10 7a12 12 0 0 0 5.8-1.8M10 10a3 3 0 0 0 4 4"></path>
            </svg>
        </button>
    </div>
    @error($model) <p id="{{ $id }}-error" class="form-error" role="alert">{{ $message }}</p> @enderror
</div>
