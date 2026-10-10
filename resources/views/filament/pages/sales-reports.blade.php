<x-filament-panels::page>
    <form wire:submit="loadPayments">
        <x-filament::section>
            <x-slot name="heading">Report period</x-slot>
            <x-slot name="description">Filter paid transactions by payment creation date in Asia/Jakarta. Both dates are included.</x-slot>
            {{ $this->form }}
            <x-slot name="footer">
                <x-filament::button type="submit" icon="heroicon-o-eye" wire:loading.attr="disabled" wire:target="loadPayments">
                    <span wire:loading.remove wire:target="loadPayments">Preview</span>
                    <span wire:loading wire:target="loadPayments">Loading preview...</span>
                </x-filament::button>
            </x-slot>
        </x-filament::section>
    </form>
    @if ($period)
        <div class="flex flex-col gap-1">
            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Sales Preview</h2>
            <p class="text-sm sales-report-muted">Paid transactions from {{ $period['start'] }} to {{ $period['end'] }}. Export includes all matching transactions.</p>
        </div>
    @endif
    {{ $this->table }}
</x-filament-panels::page>
