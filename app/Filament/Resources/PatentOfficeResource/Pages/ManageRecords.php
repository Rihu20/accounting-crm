<?php

namespace App\Filament\Resources\PatentOfficeResource\Pages;

use App\Filament\Resources\PatentOfficeResource;
use Filament\Actions\CreateAction;

class ManageRecords extends \Filament\Resources\Pages\ManageRecords
{
    protected static string $resource = PatentOfficeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
