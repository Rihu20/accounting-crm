<?php

namespace App\Filament\Resources;

use App\Models\Vendor;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VendorResource extends CrmResource
{
    protected static ?string $model = Vendor::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Vendors';

    protected static string|\UnitEnum|null $navigationGroup = 'Masters';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->required()->unique(ignoreRecord: true), Select::make('vendor_type')->options(['Foreign' => 'Foreign', 'Indian' => 'Indian'])->required(), TextInput::make('country')->required(), TextInput::make('email')->email(), TextInput::make('tax_id'), Select::make('default_currency')->options(array_combine(config('crm.expense_currencies'), config('crm.expense_currencies')))->required()->default('USD'), Textarea::make('notes')])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable()->sortable(), TextColumn::make('vendor_type')->searchable()->sortable()->badge(), TextColumn::make('country')->searchable()->sortable(), TextColumn::make('default_currency')->searchable()->sortable()->badge()])->recordActions([ViewAction::make(), EditAction::make()])->defaultPaginationPageOption(10);
    }

    public static function getPages(): array
    {
        return ['index' => VendorResource\Pages\ManageRecords::route('/')];
    }
}
