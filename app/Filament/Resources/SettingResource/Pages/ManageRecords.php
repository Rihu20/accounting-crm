<?php

namespace App\Filament\Resources\SettingResource\Pages;

use App\Filament\Resources\SettingResource;
use App\Services\DataBackupService;
use Filament\Actions\Action;

class ManageRecords extends \Filament\Resources\Pages\ManageRecords
{
    protected static string $resource = SettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backup')->label('Download full backup')->icon('heroicon-o-circle-stack')
                ->visible(fn () => auth()->user()->allowed('accounting.settings.manage'))
                ->requiresConfirmation()->modalIcon('heroicon-o-circle-stack')->modalHeading('Download a full backup')
                ->modalDescription('One file with every record of the CRM: clients, matters, invoices, payments, expenses, vendors, Form A2s, users and settings. Keep it safe: it holds account numbers and login details. Uploaded PDFs are not inside; keep those separately.')
                ->modalSubmitActionLabel('Download')
                ->action(function () {
                    $json = app(DataBackupService::class)->exportJson();
                    activity()->causedBy(auth()->user())->withProperties(['bytes' => strlen($json)])->log('Full backup downloaded');

                    return response()->streamDownload(fn () => print ($json), 'crm-backup-'.now()->format('Y-m-d-His').'.json', ['Content-Type' => 'application/json']);
                }),
        ];
    }
}
