@props(['show', 'close', 'id'])

<div
    x-data
    x-show="$wire.{{ $show }}"
    x-trap.inert.noscroll="$wire.{{ $show }}"
    x-on:keydown.escape.window="if ($wire.{{ $show }}) $wire.{{ $close }}()"
    x-on:click.self="$wire.{{ $close }}()"
    x-transition.opacity
    x-cloak
    {{ $attributes->class(['modal-overlay']) }}
>
    <section class="modal-box" role="dialog" aria-modal="true" aria-labelledby="{{ $id }}" tabindex="-1">
        <div class="modal-header">
            <h2 id="{{ $id }}" class="modal-title">{{ $title }}</h2>
            <button type="button" wire:click="{{ $close }}" class="modal-close" aria-label="Close dialog">
                <x-heroicon-o-x-mark class="size-5" aria-hidden="true" />
            </button>
        </div>
        {{ $slot }}
    </section>
</div>
