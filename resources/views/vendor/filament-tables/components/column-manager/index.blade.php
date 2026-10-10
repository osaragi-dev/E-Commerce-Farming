@php
    use Filament\Tables\Enums\ColumnManagerResetActionPosition;
@endphp

@props([
    'applyAction',
    'columns' => null,
    'hasReorderableColumns',
    'hasToggleableColumns',
    'reorderAnimationDuration' => 300,
    'resetActionPosition' => ColumnManagerResetActionPosition::Header,
])

<div
    x-data="filamentTableColumnManager({
                columns: $wire.entangle('tableColumns'),
                isLive: {{ $applyAction->isVisible() ? 'false' : 'true' }},
            })"
    class="fi-ta-col-manager"
>
    @if ($hasToggleableColumns)
        <div class="fi-ta-col-manager-header" style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); width: 100%;">
            <x-filament::link
                color="success"
                tag="button"
                x-on:click="
                    deferredColumns.forEach((column) => {
                        if (column.type === 'group') {
                            column.columns.forEach((child) => {
                                if (child.isToggleable !== false) child.isToggled = true
                            })
                        } else if (column.isToggleable !== false) {
                            column.isToggled = true
                        }
                    })
                    deferredColumns = deferredColumns.slice()
                    if (isLive) applyTableColumnManager()
                "
                style="justify-self: start;"
            >
                Select All
            </x-filament::link>

            <x-filament::link
                color="warning"
                tag="button"
                x-on:click="
                    deferredColumns.forEach((column) => {
                        if (column.type === 'group') {
                            column.columns.forEach((child) => {
                                if (child.isToggleable !== false) child.isToggled = false
                            })
                        } else if (column.isToggleable !== false) {
                            column.isToggled = false
                        }
                    })
                    deferredColumns = deferredColumns.slice()
                    if (isLive) applyTableColumnManager()
                "
                style="justify-self: center;"
            >
                Unselect All
            </x-filament::link>

            <x-filament::link
                color="danger"
                tag="button"
                wire:click="resetTableColumnManager"
                x-on:click="resetDeferredColumns"
                style="justify-self: end;"
            >
                {{ __('filament-tables::table.column_manager.actions.reset.label') }}
            </x-filament::link>
        </div>
    @endif

    <x-filament-tables::column-manager.content
        :columns="$columns"
        :has-reorderable-columns="$hasReorderableColumns"
        :has-toggleable-columns="$hasToggleableColumns"
        :reorder-animation-duration="$reorderAnimationDuration"
    />

    @if ($applyAction->isVisible() || $resetActionPosition === ColumnManagerResetActionPosition::Footer)
        <div class="fi-ta-col-manager-actions-ctn">
            @if ($applyAction->isVisible())
                {{ $applyAction }}
            @endif

            @if ($resetActionPosition === ColumnManagerResetActionPosition::Footer)
                <x-filament::link
                    color="danger"
                    tag="button"
                    wire:click="resetTableColumnManager"
                    x-on:click="resetDeferredColumns"
                >
                    {{ __('filament-tables::table.column_manager.actions.reset.label') }}
                </x-filament::link>
            @endif
        </div>
    @endif
</div>
