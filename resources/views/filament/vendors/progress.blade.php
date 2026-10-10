<div class="w-full overflow-x-auto">
    <div class="flex min-w-[760px] items-start justify-between gap-2 px-2 py-3" aria-label="Vendor approval progress">
        @foreach ($steps as $index => $step)
            @php
                $stateClasses = match ($step['state']) {
                    'complete' => 'bg-success-900 text-white ring-success-300 dark:bg-success-800 dark:ring-success-700',
                    'current' => 'bg-success-500 text-white ring-success-200 dark:bg-success-500 dark:ring-success-800',
                    default => 'bg-gray-50 text-gray-400 ring-gray-200 dark:bg-gray-900 dark:text-gray-500 dark:ring-gray-700',
                };
                $labelClasses = $step['state'] === 'complete' ? 'text-success-800 dark:text-success-300' : 'text-gray-500 dark:text-gray-400';
            @endphp
            <div class="group relative flex min-w-0 flex-1 flex-col items-center text-center">
                @if ($index < count($steps) - 1)
                    @php($nextStep = $steps[$index + 1])
                    <div class="absolute left-[calc(50%+1.5rem)] top-6 h-0.5 w-[calc(100%-3rem)] {{ $nextStep['state'] === 'complete' ? 'bg-success-700 dark:bg-success-600' : 'bg-gray-200 dark:bg-gray-700' }}"></div>
                @endif
                <div class="relative z-10 flex size-12 items-center justify-center rounded-full ring-4 {{ $stateClasses }}">
                    <x-filament::icon :icon="$step['state'] === 'complete' ? 'heroicon-o-check-circle' : 'heroicon-o-clock'" class="size-5" />
                </div>
                <div class="mt-2 text-center">
                    <p class="text-xs font-semibold {{ $labelClasses }}">{{ $step['label'] }}</p>
                    @if ($step['date'])
                        <p class="mt-1 text-[10px] text-gray-500 dark:text-gray-400">{{ $step['date'] }}</p>
                        <p class="text-[10px] text-gray-500 dark:text-gray-400">{{ $step['time'] }}</p>
                        @if ($step['actor'])<p class="mt-1 max-w-28 truncate text-[10px] font-medium text-gray-600 dark:text-gray-300">{{ $step['actor'] }}</p>@endif
                    @else
                        <p class="mt-1 text-[10px] text-gray-400 dark:text-gray-500">Pending</p>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
