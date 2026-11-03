<?php

namespace App\Filament\Resources;

use App\Models\PatentOffice;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PatentOfficeResource extends CrmResource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Masters';

    protected static ?string $navigationLabel = 'Patent / IP offices';

    protected static ?int $navigationSort = 6;

    protected static ?string $model = PatentOffice::class;

    public static function canViewAny(): bool
    {
        return auth()->user()?->allowed('accounting.masters.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->allowed('accounting.expenses.edit_all') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return self::canCreate();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->required()->maxLength(512), TextInput::make('acronym')->maxLength(40), TextInput::make('country')->required()->maxLength(120), Toggle::make('is_active')->default(true)])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('acronym')->searchable(), TextColumn::make('name')->searchable()->wrap(), TextColumn::make('country')->searchable()->sortable(), TextColumn::make('is_active')->badge()])->recordActions([EditAction::make()])->defaultSort('country');
    }

    public static function getPages(): array
    {
        return ['index' => PatentOfficeResource\Pages\ManageRecords::route('/')];
    }
}
