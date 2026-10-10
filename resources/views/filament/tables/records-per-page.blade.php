@if ($isPaginated && count($pageOptions) > 1)
    <label class="application-table-records-per-page">
        <x-filament::input.wrapper prefix="Show Entries">
            <x-filament::input.select wire:model.live="tableRecordsPerPage">
                @foreach ($pageOptions as $pageOption)
                    <option value="{{ $pageOption }}">
                        {{ $pageOption === 'all' ? 'All' : $pageOption }}
                    </option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </label>
@endif
