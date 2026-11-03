<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions\CreateAction;

class ManageRecords extends \Filament\Resources\Pages\ManageRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->after(function (User $record): void {
            activity()->causedBy(auth()->user())->performedOn($record)->withProperties(['roles' => $record->roles()->pluck('name')->all()])->log('Roles assigned');
        })];
    }
}
