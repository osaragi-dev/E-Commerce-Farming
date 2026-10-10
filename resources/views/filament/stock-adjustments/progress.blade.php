<div class="w-full overflow-x-auto">
    <div class="flex min-w-[560px] items-start justify-between gap-2 px-2 py-3" aria-label="Stock adjustment workflow progress">
        @foreach ($steps as $index => $step)
            @php
                $icon = match ($step['key']) {
                    'requested' => 'heroicon-o-pencil-square',
                    'posted' => 'heroicon-o-folder-open',
                    default => 'heroicon-o-check-badge',
                };
                $stateClasses = match ($step['state']) {
                    'complete' => 'bg-success-900 text-white ring-success-300 dark:bg-success-800 dark:ring-success-700',
                    'current' => 'bg-success-500 text-white ring-success-200 dark:ring-success-800',
                    'stopped' => 'bg-warning-500 text-white ring-warning-200 dark:ring-warning-800',
                    default => 'bg-gray-50 text-gray-400 ring-gray-200 dark:bg-gray-900 dark:text-gray-500 dark:ring-gray-700',
                };
                $labelClasses = match ($step['state']) {
                    'complete' => 'text-success-800 dark:text-success-300',
                    'current' => 'text-success-700 dark:text-success-400',
                    'stopped' => 'text-warning-700 dark:text-warning-400',
                    default => 'text-gray-400 dark:text-gray-500',
                };
            @endphp
            <div class="relative flex min-w-0 flex-1 flex-col items-center text-center">
                @if ($index < count($steps) - 1)
                    <div class="absolute left-[calc(50%+1.5rem)] top-6 h-0.5 w-[calc(100%-3rem)] {{ in_array($steps[$index + 1]['state'], ['complete', 'current'], true) ? 'bg-success-800 dark:bg-success-600' : 'bg-gray-200 dark:bg-gray-700' }}"></div>
                @endif
                <div class="relative z-10 flex size-12 items-center justify-center rounded-full ring-4 {{ $stateClasses }}">
                    <x-filament::icon :icon="$icon" class="size-5" />
                </div>
                <div class="mt-3">
                    <p class="text-xs font-semibold {{ $labelClasses }}">{{ $step['label'] }}</p>
                    @if ($step['date'])
                        <p class="mt-1 text-[10px] text-gray-500 dark:text-gray-400">{{ $step['date'] }}</p>
                        <p class="text-[10px] text-gray-500 dark:text-gray-400">{{ $step['time'] }}</p>
                        @if ($step['actor'])
                            <p class="mt-1 max-w-28 truncate text-[10px] font-medium text-gray-600 dark:text-gray-300" title="{{ $step['actor'] }}">{{ $step['actor'] }}</p>
                        @endif
                    @else
                        <p class="mt-1 text-[10px] {{ $step['state'] === 'current' ? 'text-success-600 dark:text-success-400' : 'text-gray-400 dark:text-gray-500' }}">{{ $step['state'] === 'stopped' ? $step['message'] : ($step['state'] === 'current' ? 'Waiting' : 'Pending') }}</p>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
    @if (last($steps)['key'] === 'posted' && last($steps)['complete'])
        <div class="mt-3 flex items-start gap-2 rounded-lg bg-gray-50 px-3 py-2 text-xs dark:bg-gray-800">
            <x-filament::icon icon="heroicon-o-information-circle" class="mt-0.5 size-4 shrink-0" />
            <div><span class="font-semibold">Posted</span><br><span class="text-gray-500 dark:text-gray-400">{{ last($steps)['actor'] ?? 'System' }} &middot; {{ last($steps)['date'] }} {{ last($steps)['time'] }}</span></div>
        </div>
    @endif
</div>
