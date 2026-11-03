<?php

namespace App\Filament\Resources;

use App\Models\Paralegal;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ParalegalResource extends CrmResource
{
    protected static ?string $model = Paralegal::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Paralegals';

    protected static string|\UnitEnum|null $navigationGroup = 'Masters';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->required(), TextInput::make('email')->email()->unique(ignoreRecord: true), TextInput::make('phone'), TextInput::make('firm'), Select::make('user_id')->relationship('user', 'email')->searchable()->preload()->helperText('Link a Paralegal user account created under Users.')])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable()->sortable(), TextColumn::make('email')->searchable()->sortable(), TextColumn::make('phone')->searchable()->sortable(), TextColumn::make('firm')->searchable()->sortable()])->recordActions([ViewAction::make(), EditAction::make()])->defaultPaginationPageOption(10);
    }

    public static function getPages(): array
    {
        return ['index' => ParalegalResource\Pages\ManageRecords::route('/')];
    }
}
