@php
    $payment = $getRecord();
    $voidApproval = $payment->void_approval;
@endphp

<div class="flex flex-wrap items-center gap-1">
    <x-filament::badge :color="$payment->status->getColor()">{{ $payment->status->getLabel() }}</x-filament::badge>
    @if ($voidApproval)
        <x-filament::badge :color="match ($voidApproval) {
            'approved' => 'success',
            'rejected', 'cancelled' => 'danger',
            default => 'warning',
        }">Void: {{ str($voidApproval)->headline() }}</x-filament::badge>
    @endif
</div>
