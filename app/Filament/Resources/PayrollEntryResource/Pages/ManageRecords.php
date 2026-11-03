<?php

namespace App\Filament\Resources\PayrollEntryResource\Pages;

use App\Filament\Resources\PayrollEntryResource;
use Filament\Actions\CreateAction;

class ManageRecords extends \Filament\Resources\Pages\ManageRecords
{
    protected static string $resource = PayrollEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->mutateDataUsing(fn (array $data): array => $data + ['created_by' => auth()->id()])];
    }
}
