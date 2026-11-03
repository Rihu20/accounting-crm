<?php

namespace App\Filament\Resources\InvoiceDocumentResource\Pages;

use App\Filament\Resources\InvoiceDocumentResource;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageRecords extends \Filament\Resources\Pages\ManageRecords
{
    protected static string $resource = InvoiceDocumentResource::class;

    protected ?string $subheading = 'Original uploaded PDFs. Use Proforma and Tax tabs to organise existing invoices.';

    protected function getHeaderActions(): array
    {
        return [InvoiceDocumentResource::uploadAction()];
    }

    public function getTabs(): array
    {
        return ['all' => Tab::make('All PDFs'), 'proforma' => Tab::make('Proforma')->modifyQueryUsing(fn (Builder $query) => $query->where('doc_type', 'proforma')), 'tax' => Tab::make('Tax invoices')->modifyQueryUsing(fn (Builder $query) => $query->where('doc_type', 'tax'))];
    }
}
