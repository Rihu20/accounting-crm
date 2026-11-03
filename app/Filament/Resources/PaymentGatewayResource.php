<?php

namespace App\Filament\Resources;

use App\Models\PaymentGateway;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentGatewayResource extends CrmResource
{
    protected static ?string $model = PaymentGateway::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'Payment gateways';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->required(), Textarea::make('instructions')->columnSpanFull(), Toggle::make('is_active')->default(true)])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable()->sortable(), TextColumn::make('instructions')->searchable()->sortable(), TextColumn::make('is_active')->searchable()->sortable()])->recordActions([ViewAction::make(), EditAction::make()])->defaultPaginationPageOption(10);
    }

    public static function getPages(): array
    {
        return ['index' => PaymentGatewayResource\Pages\ManageRecords::route('/')];
    }
}
