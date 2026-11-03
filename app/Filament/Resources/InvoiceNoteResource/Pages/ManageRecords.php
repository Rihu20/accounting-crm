<?php

namespace App\Filament\Resources\InvoiceNoteResource\Pages;

use App\Filament\Resources\InvoiceNoteResource;
use App\Models\Invoice;
use App\Services\NoteService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class ManageRecords extends \Filament\Resources\Pages\ManageRecords
{
    protected static string $resource = InvoiceNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label('New note')->icon('heroicon-o-plus')->visible(fn () => InvoiceNoteResource::canCreate())
                ->schema(InvoiceNoteResource::noteSchema())->modalWidth('2xl')
                ->action(function (array $data) {
                    $note = app(NoteService::class)->create(Invoice::findOrFail($data['invoice_id']), $data, auth()->user());
                    Notification::make()->title($note->label().' '.$note->number.' created')->success()->send();
                }),
        ];
    }
}
