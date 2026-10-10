@props(['quantity', 'maximum', 'decrease', 'increase', 'label' => 'Quantity'])

<div {{ $attributes->class(['qty-stepper']) }} role="group" aria-label="{{ $label }}">
    <button
        type="button"
        wire:click="{{ $decrease }}"
        wire:loading.attr="disabled"
        wire:target="{{ $decrease }}, {{ $increase }}"
        aria-label="Decrease {{ strtolower($label) }}"
        @disabled($quantity <= 1)
    >
        <x-heroicon-o-minus class="size-4" aria-hidden="true" />
    </button>
    <output aria-live="polite" aria-atomic="true">{{ \App\Support\Quantity::format($quantity) }}</output>
    <button
        type="button"
        wire:click="{{ $increase }}"
        wire:loading.attr="disabled"
        wire:target="{{ $decrease }}, {{ $increase }}"
        aria-label="Increase {{ strtolower($label) }}"
        @disabled($quantity + 1 > $maximum)
    >
        <x-heroicon-o-plus class="size-4" aria-hidden="true" />
    </button>
</div>
