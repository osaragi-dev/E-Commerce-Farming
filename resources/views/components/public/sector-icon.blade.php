@props(['slug' => null])

@if ($slug === 'perkebunan')
    <svg {{ $attributes }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M12 21v-8m0 4C5 17 3 13 3 7c6 0 9 3 9 10Zm0-4c0-6 3-10 9-10 0 6-3 10-9 10ZM7 21h10" />
    </svg>
@elseif ($slug === 'perikanan')
    <svg {{ $attributes }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M3 12C8 4 15 4 19 12c-4 8-11 8-16 0Zm16 0 3-4v8l-3-4ZM8 7l2-3 4 2M8 17l2 3 4-2" />
        <circle cx="7.5" cy="11" r=".75" fill="currentColor" stroke="none" />
    </svg>
@elseif ($slug === 'peternakan')
    <svg {{ $attributes }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M19 14c0 4.42-3.13 7-7 7s-7-2.58-7-7S8.5 3 12 3s7 6.58 7 11Z" />
        <path d="M8 14c0 2 1 3.5 2.5 4" />
    </svg>
@else
    <x-heroicon-o-squares-2x2 {{ $attributes }} aria-hidden="true" />
@endif
