@php
    $approvalStatus = $record->approval_status?->value;
    $levelCount = max(1, $levelCount);
    $steps = [['key' => 'requested', 'label' => 'Requested']];

    for ($level = 1; $level <= $levelCount; $level++) {
        $steps[] = ['key' => "level-{$level}", 'label' => "L{$level} Approved"];
    }

    $steps[] = ['key' => 'approved', 'label' => 'Approved'];

    $currentIndex = match (true) {
        $approvalStatus === 'approved' => count($steps) - 1,
        $approvalStatus === 'rejected' => 0,
        $approvalStatus === 'revised' => 0,
        $approvalStatus === 'requested' => 0,
        $approvalStatus === 'pending' && (int) $record->approval_level > 0 => (int) $record->approval_level,
        default => 0,
    };
@endphp

<ol class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6" aria-label="Stock opname approval progress">
    @foreach ($steps as $index => $step)
        @php
            $isCurrent = $index === $currentIndex && $approvalStatus !== 'approved';
            $isComplete = $index < $currentIndex || ($approvalStatus === 'approved' && $index === $currentIndex);
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

@if (in_array($approvalStatus, ['rejected', 'revised'], true))
    <p class="mt-4 text-sm font-medium text-warning-700 dark:text-warning-400">
        Approval status: {{ str($approvalStatus)->headline() }}
    </p>
@endif
