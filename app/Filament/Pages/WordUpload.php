<?php

namespace App\Filament\Pages;

use App\Models\Client;
use App\Models\Matter;
use Filament\Pages\Page;

class WordUpload extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Invoices';

    protected static ?string $navigationLabel = 'Proforma from Word file';

    protected static ?int $navigationSort = 3;

    public static function shouldRegisterNavigation(): bool
    {
        return filament()->getCurrentPanel()?->getId() !== 'portal' && parent::shouldRegisterNavigation();
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-arrow-up';

    protected static ?string $title = 'Upload Word file';

    public string $clientRef = '';

    protected string $view = 'filament.pages.wordupload';

    public static function canAccess(): bool
    {
        return auth()->user()->allowed('accounting.invoices.create');
    }

    public function getViewData(): array
    {
        $client = null;
        if (preg_match('/^\d{4}$/', $this->clientRef)) {
            $query = Client::where('ref_no', $this->clientRef);
            if (auth()->user()->hasRole('Paralegal')) {
                $query->whereIn('id', Matter::where('paralegal_id', auth()->user()->paralegal?->id ?? 0)->select('client_id'));
            }
            $client = $query->first();
        }

        return ['preview' => session('word_preview'), 'selectedClient' => $client];
    }
}
