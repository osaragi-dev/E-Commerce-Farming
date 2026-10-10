@props(['path' => null, 'alt' => '', 'loading' => 'lazy'])

@if ($path && \Illuminate\Support\Facades\Storage::disk('public')->exists($path))
    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($path) }}" alt="{{ $alt }}" loading="{{ $loading }}" {{ $attributes }}>
@else
    <span {{ $attributes->except(['width', 'height', 'fetchpriority', 'decoding'])->class(['stored-image-placeholder']) }} role="img" aria-label="{{ $alt ? $alt.': photo unavailable' : 'Photo unavailable' }}">
        <x-heroicon-o-photo class="size-8" aria-hidden="true" />
    </span>
@endif
