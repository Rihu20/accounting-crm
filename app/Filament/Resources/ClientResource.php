<?php

namespace App\Filament\Resources;

use App\Models\Client;
use App\Services\Gst;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ClientResource extends CrmResource
{
    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'ref_no', 'gstin', 'email_1'];
    }

    protected static ?string $model = Client::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Clients';

    protected static string|\UnitEnum|null $navigationGroup = 'Masters';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('ref_no')->label('Client Ref No')->regex('/^\d{4}$/')->maxLength(4)->unique(ignoreRecord: true)->helperText('Four digits from the matter reference, for example 1248 in P25124809KR.'), TextInput::make('name')->required()->unique(ignoreRecord: true), Select::make('client_type')->options(['Foreign' => 'Foreign', 'Indian' => 'Indian'])->required()->live()->afterStateUpdated(function (?string $state, Set $set): void {
            if ($state === 'Indian') {
                $set('country', 'India');
                $set('default_currency', 'INR');
            }
        }), TextInput::make('country')->required(), TextInput::make('state')->datalist(array_keys(Gst::STATE_CODES))->helperText('Used to determine place of supply for Indian invoices.'), Select::make('state_code')->label('State code')->options(array_flip(Gst::STATE_CODES))->searchable()->helperText('Optional override if the state is not selected.'), TextInput::make('contact_person'), TextInput::make('email_1')->email(), TextInput::make('email_2')->email(), TextInput::make('phone'), Textarea::make('address')->columnSpanFull(), TextInput::make('gstin'), Select::make('default_currency')->options(['USD' => 'USD', 'INR' => 'INR', 'EUR' => 'EUR'])->required()->default('USD'), Textarea::make('notes')->columnSpanFull()])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('ref_no')->label('Client Ref No')->searchable()->sortable(), TextColumn::make('name')->searchable()->sortable(), TextColumn::make('client_type')->searchable()->sortable()->badge(), TextColumn::make('country')->searchable()->sortable(), TextColumn::make('default_currency')->searchable()->sortable()->badge()])->recordActions([ViewAction::make(), EditAction::make()])->defaultPaginationPageOption(10);
    }

    public static function getPages(): array
    {
        return ['index' => ClientResource\Pages\ManageRecords::route('/')];
    }
}
