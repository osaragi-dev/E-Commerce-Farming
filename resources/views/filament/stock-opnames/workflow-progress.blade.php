@php
    $currentStatus = $record->status?->value;
    $steps = [
        ['key' => 'draft', 'label' => 'Requested'],
        ['key' => 'counting', 'label' => 'Started'],
        ['key' => 'frozen', 'label' => 'Frozen'],
        ['key' => 'approved', 'label' => 'Approved'],
        ['key' => 'submitted', 'label' => 'Ended'],
        ['key' => 'finalized', 'label' => 'Finalized'],
    ];
    $stepOrder = collect($steps)->pluck('key')->flip();
    $currentIndex = $stepOrder->get($currentStatus, 0);
@endphp

<ol class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6" aria-label="Stock opname workflow progress">
    @foreach ($steps as $index => $step)
        @php
            $isCurrent = $index === $currentIndex;
            $isComplete = $index < $currentIndex;
        @endphp
        <li class="flex items-center gap-3 lg:block">
            <div class="flex size-9 shrink-0 items-center justify-center rounded-full border-2 text-sm font-semibold {{ $isCurrent ? 'border-primary-600 bg-primary-600 text-white' : ($isComplete ? 'border-success-600 bg-success-600 text-white' : 'border-gray-300 bg-white text-gray-500 dark:border-gray-600 dark:bg-gray-900') }}">
                {{ $isComplete ? 'x' : $index + 1 }}
            </div>
            <div class="mt-0 lg:mt-3">
                <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $step['label'] }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $isCurrent ? 'Current step' : ($isComplete ? 'Completed' : 'Pending') }}</p>
            </div>
        </li>
    @endforeach
</ol>
