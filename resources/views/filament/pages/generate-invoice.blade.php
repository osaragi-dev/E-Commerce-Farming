<x-filament-panels::page>
    @can('create', \App\Models\Invoice::class)
        <form wire:submit="preview">
            <x-filament::section heading="Generate Invoice">
                <p class="mb-6 text-sm text-gray-600 dark:text-gray-400">Choose an Invoice customer and unpaid orders. Preview the document, then generate the final invoice. Dates use Asia/Jakarta.</p>
                <x-filament::tabs label="Generate By" class="mb-6 w-fit max-w-full">
                    <x-filament::tabs.item :active="($data['generation_method'] ?? 'by_date') === 'by_date'" wire:click="$set('data.generation_method', 'by_date')">
                        Date Range
                    </x-filament::tabs.item>
                    <x-filament::tabs.item :active="($data['generation_method'] ?? 'by_date') === 'by_selected_order'" wire:click="$set('data.generation_method', 'by_selected_order')">
                        Selected Orders
                    </x-filament::tabs.item>
                </x-filament::tabs>
                {{ $this->form }}
                <x-slot name="footer">
                    <div class="flex flex-wrap gap-3">
                        <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="preview,generateInvoice">Preview Invoice</x-filament::button>
                        @if ($previewToken && ! $generatedInvoiceId)
                            <x-filament::button type="button" color="success" wire:click="generateInvoice" wire:loading.attr="disabled" wire:target="preview,generateInvoice">Generate Invoice</x-filament::button>
                        @endif
                    </div>
                </x-slot>
            </x-filament::section>
        </form>
    @endcan
    @if ($previewToken || $generatedInvoiceId)
        <x-filament::section heading="{{ $generatedInvoiceId ? 'Generated Invoice' : 'Invoice Preview' }}">
            <iframe title="Invoice PDF preview" class="h-[800px] w-full rounded-lg bg-white"
                src="{{ $generatedInvoiceId ? route('admin.invoices.show', $generatedInvoiceId) : route('admin.invoices.preview', $previewToken) }}"></iframe>
            @if ($generatedInvoiceId && auth('web')->user()?->can('Export:Invoice') && auth('web')->user()?->can('View:Invoice'))
                <x-slot name="footer">
                    <div class="flex flex-wrap gap-3">
                        <x-filament::button tag="a" href="{{ route('admin.invoices.download', [$generatedInvoiceId, 'pdf']) }}">Download PDF</x-filament::button>
                        <x-filament::button tag="a" href="{{ route('admin.invoices.download', [$generatedInvoiceId, 'docx']) }}" color="gray">Download Word</x-filament::button>
                    </div>
                </x-slot>
            @endif
        </x-filament::section>
    @endif
    <x-filament::section heading="Invoice History">
        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
