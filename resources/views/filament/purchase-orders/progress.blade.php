<div class="space-y-8">
    @foreach ($timelines as $name => $steps)
        @if ($steps !== [])
            @php
                $gridColumns = match (min(count($steps), 4)) {
                    1 => 'md:grid-cols-1',
                    2 => 'md:grid-cols-2',
                    3 => 'md:grid-cols-3',
                    default => 'md:grid-cols-4',
                };
            @endphp
            <section class="space-y-4">
                @if ($name === 'closing')
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Closing Approval</h3>
                @endif

                <ol @class(['grid grid-cols-1 gap-4', $gridColumns])>
                    @foreach ($steps as $step)
                        <li class="flex items-start gap-3 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                            <span @class([
                                'flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold',
                                'bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-300' => $step['complete'],
                                'bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-300' => $step['current'],
                                'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' => ! $step['complete'] && ! $step['current'],
                            ])>
                                {{ $step['complete'] ? '✓' : '•' }}
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $step['label'] }}</p>
                                @if ($step['date'])
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $step['date'] }} {{ $step['time'] }}</p>
                                @endif
                                @if ($step['actor'])
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $step['actor'] }}</p>
                                @endif
                                @if ($step['current'])
                                    <p class="mt-1 text-xs font-medium text-primary-700 dark:text-primary-300">Pending</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </section>
        @endif
    @endforeach
</div>
