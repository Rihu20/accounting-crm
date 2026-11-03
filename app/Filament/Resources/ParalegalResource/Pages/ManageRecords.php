<?php

namespace App\Filament\Resources\ParalegalResource\Pages;

use App\Filament\Resources\ParalegalResource;
use Filament\Actions\CreateAction;

class ManageRecords extends \Filament\Resources\Pages\ManageRecords
{
    protected static string $resource = ParalegalResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
