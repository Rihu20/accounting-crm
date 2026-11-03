<?php

namespace App\Filament\Resources;

use App\Models\Client;
use App\Models\Matter;
use App\Services\MatterReference;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MatterResource extends CrmResource
{
    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation();
    }

    protected static ?string $model = Matter::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-briefcase';

    protected static ?string $navigationLabel = 'Matters';

    protected static string|\UnitEnum|null $navigationGroup = 'Masters';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('our_ref')->label('Full Matter Ref')->required()->regex('/^[PDT]\d{8}[A-Z]{2}$/i')->unique(ignoreRecord: true)->live(onBlur: true)
                ->helperText('Example: P25124809KR. Enter the complete reference received from the paralegal.')
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    $reference = strtoupper(trim($state ?? ''));
                    $set('our_ref', $reference);
                    $parts = MatterReference::parse($reference);
                    $set('ref_no', $parts['client_ref'] ?? null);
                    if ($parts) {
                        $set('client_id', Client::where('ref_no', $parts['client_ref'])->value('id'));
                    }
                }),
            TextInput::make('ref_no')->label('Client Ref No')->regex('/^\d{4}$/')->maxLength(4)->helperText('Filled from the full reference. The same client number can have several matters.'),
            TextEntry::make('reference_details')->label('Reference details')->state(function (Get $get): string {
                $parts = MatterReference::parse($get('our_ref'));

                return $parts ? $parts['type'].' / Client year '.$parts['year'].' / Client '.$parts['client_ref'].' / Matter '.$parts['matter_no'].' / Country '.$parts['country_code'] : 'P = Patent, D = Design, T = Trademark. Example: P / 25 / 1248 / 09 / KR.';
            })->columnSpanFull(),
            Select::make('client_id')->label('Client')->relationship('client', 'name')->getOptionLabelFromRecordUsing(fn ($record) => ($record->ref_no ? $record->ref_no.' - ' : '').$record->name)->searchable(['name', 'ref_no'])->preload()->required(),
            Select::make('paralegal_id')->relationship('paralegal', 'name')->searchable()->preload()->required(),
            TextInput::make('matter_title')->required(), TextInput::make('country')->required(),
            TextInput::make('your_ref')->label('Client external reference')->helperText('Optional reference supplied by the client.'), TextInput::make('app_no'), TextInput::make('pct_no'),
            Select::make('service_type')->options(['Official Fees' => 'Official Fees', 'Professional Fees' => 'Professional Fees', 'Translation' => 'Translation'])->required(), TextInput::make('language'), Select::make('status')->options(['Active' => 'Active', 'Closed' => 'Closed', 'On Hold' => 'On Hold'])->required()->default('Active'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('our_ref')->label('Matter Ref')->searchable(), TextColumn::make('ref_no')->label('Client Ref')->searchable()->sortable(), TextColumn::make('client.name')->searchable()->sortable(), TextColumn::make('matter_title')->searchable()->sortable(), TextColumn::make('paralegal.name')->searchable()->sortable(), TextColumn::make('status')->searchable()->sortable()->badge()])->recordActions([ViewAction::make(), EditAction::make()])->defaultPaginationPageOption(10);
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->allowed('accounting.masters.view');
    }

    protected static ?string $recordTitleAttribute = 'our_ref';

    public static function getGloballySearchableAttributes(): array
    {
        return ['ref_no', 'matter_title', 'our_ref', 'app_no', 'pct_no'];
    }

    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery();

        return $q;
    }

    public static function getPages(): array
    {
        return ['index' => MatterResource\Pages\ManageRecords::route('/')];
    }
}
