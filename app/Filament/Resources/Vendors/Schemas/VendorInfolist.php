<?php

namespace App\Filament\Resources\Vendors\Schemas;

use App\Models\ApprovalLog;
use App\Models\Vendor;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class VendorInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Progress')->schema([
                ViewEntry::make('progress')->hiddenLabel()->view('filament.vendors.progress', fn (Vendor $record): array => ['steps' => self::timeline($record)])->columnSpanFull(),
            ])->columnSpanFull(),
            Section::make('Vendor Information')->columns(3)->schema([
                TextEntry::make('vendor_code')->label('Vendor Code'),
                TextEntry::make('name'),
                TextEntry::make('contact_person')->placeholder('-'),
                TextEntry::make('phone')->placeholder('-'),
                TextEntry::make('email')->placeholder('-'),
                TextEntry::make('address')->placeholder('-')->columnSpanFull(),
            ]),
            Section::make('Tax & Bank')->columns(2)->schema([
                TextEntry::make('tax_id')->label('Tax ID')->placeholder('-'),
                TextEntry::make('bank_name')->placeholder('-'),
                TextEntry::make('bank_account')->placeholder('-'),
                TextEntry::make('bank_account_name')->placeholder('-'),
            ]),
            Section::make('Terms & Status')->columns(3)->schema([
                TextEntry::make('payment_terms')->placeholder('-'),
                TextEntry::make('lead_time_days')->label('Lead Time (Days)'),
                TextEntry::make('is_active')->label('Active')->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No'),
                TextEntry::make('record_status')->label('Status')->badge(),
                TextEntry::make('approval_status')->label('Approval Status')->badge(),
            ]),
            Section::make('Notes')->schema([TextEntry::make('notes')->hiddenLabel()->placeholder('-')->columnSpanFull()]),
            Section::make('Detail Log')->schema([
                RepeatableEntry::make('approvalLogs')->hiddenLabel()->table([
                    TableColumn::make('Timestamp'), TableColumn::make('Action'), TableColumn::make('PIC'), TableColumn::make('Code'), TableColumn::make('Comment'),
                ])->schema([
                    TextEntry::make('created_at')->dateTime(),
                    TextEntry::make('action')->badge(),
                    TextEntry::make('actedBy.name')->placeholder('System'),
                    TextEntry::make('action')->label('Code')->formatStateUsing(fn (string $state): string => strtoupper($state)),
                    TextEntry::make('notes')->placeholder('-')->wrap(),
                ])->columnSpanFull(),
            ])->columnSpanFull(),
            Section::make('Audit')->columns(4)->collapsed()->schema([
                TextEntry::make('createdBy.name')->label('Created By')->placeholder('-'),
                TextEntry::make('created_at')->label('Created At')->dateTime(),
                TextEntry::make('updatedBy.name')->label('Updated By')->placeholder('-'),
                TextEntry::make('updated_at')->label('Updated At')->dateTime(),
                TextEntry::make('deletedBy.name')->label('Deleted By')->placeholder('-'),
                TextEntry::make('deleted_at')->label('Deleted At')->dateTime()->placeholder('-'),
            ]),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private static function timeline(Vendor $record): array
    {
        $logs = $record->approvalLogs->sortBy('created_at')->values();
        $levels = max(2, $record->approvalSetting?->levels->count() ?? 0);
        $steps = [[
            'key' => 'requested', 'label' => 'Requested', 'complete' => $logs->contains(fn (ApprovalLog $log): bool => $log->action === 'requested') || $record->approval_status?->value !== 'pending',
            'date' => $record->created_at?->format('d M Y'), 'time' => $record->created_at?->format('H:i'), 'actor' => $record->createdBy?->name,
        ]];
        for ($level = 1; $level < $levels; $level++) {
            $log = $logs->first(fn (ApprovalLog $candidate): bool => (int) $candidate->level === $level && $candidate->action === 'approved');
            $steps[] = ['key' => 'level-'.$level, 'label' => 'Level '.$level, 'complete' => $log !== null, 'date' => $log?->created_at?->format('d M Y'), 'time' => $log?->created_at?->format('H:i'), 'actor' => $log?->actedBy?->name];
        }
        $approved = $record->approval_status?->value === 'approved';
        $steps[] = ['key' => 'approved', 'label' => 'Approved', 'complete' => $approved, 'date' => $approved ? $logs->last()?->created_at?->format('d M Y') : null, 'time' => $approved ? $logs->last()?->created_at?->format('H:i') : null, 'actor' => $approved ? $logs->last()?->actedBy?->name : null];
        foreach ($steps as $index => &$step) {
            $step['state'] = $step['complete'] ? 'complete' : ($index === collect($steps)->search(fn (array $candidate): bool => ! $candidate['complete']) ? 'current' : 'pending');
            $step['message'] = $step['complete'] ? 'Approved' : 'Waiting';
        }
        unset($step);

        return $steps;
    }
}
