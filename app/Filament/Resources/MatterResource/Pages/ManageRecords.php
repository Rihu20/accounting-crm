<?php

namespace App\Filament\Resources\MatterResource\Pages;

use App\Filament\Resources\MatterResource;
use Filament\Actions\CreateAction;

class ManageRecords extends \Filament\Resources\Pages\ManageRecords
{
    protected static string $resource = MatterResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
