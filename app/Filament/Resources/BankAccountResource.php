<?php

namespace App\Filament\Resources;

use App\Models\BankAccount;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BankAccountResource extends CrmResource
{
    protected static ?string $model = BankAccount::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationLabel = 'Bank accounts';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('bank_name')->required(), Select::make('currency')->options(['USD' => 'USD', 'INR' => 'INR', 'EUR' => 'EUR'])->required()->default('USD'), TextInput::make('beneficiary_name'), Textarea::make('beneficiary_address'), TextInput::make('account_no'), TextInput::make('swift'), TextInput::make('ifsc'), Textarea::make('bank_address'), Toggle::make('is_default'), Toggle::make('is_active')->default(true)])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('bank_name')->searchable()->sortable(), TextColumn::make('currency')->searchable()->sortable()->badge(), TextColumn::make('account_no')->searchable()->sortable(), TextColumn::make('is_default')->searchable()->sortable(), TextColumn::make('is_active')->searchable()->sortable()])->recordActions([ViewAction::make(), EditAction::make()])->defaultPaginationPageOption(10);
    }

    public static function getPages(): array
    {
        return ['index' => BankAccountResource\Pages\ManageRecords::route('/')];
    }
}
