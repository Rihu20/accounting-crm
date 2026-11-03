<?php

namespace App\Filament\Resources;

use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use App\Services\PlatformAccess;
use Spatie\Permission\Models\Role;

class UserResource extends CrmResource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->allowed('platform.users.manage');
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny() && $record->id !== auth()->id();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
            TextInput::make('password')->password()->revealable()->minLength(12)->dehydrated(fn ($state) => filled($state))->required(fn (string $operation) => $operation === 'create')->helperText('Minimum 12 characters. Leave blank to keep the current password.'),
            Toggle::make('is_active')->label('Active')->default(true),
            Select::make('roles')->label('Role')->relationship('roles', 'name', modifyQueryUsing: fn ($query) => $query->whereIn('name', PlatformAccess::ROLES))
                ->getOptionLabelFromRecordUsing(fn (Role $record) => PlatformAccess::LABELS[$record->name] ?? $record->name)
                ->multiple()->maxItems(1)->preload()->required()->columnSpanFull()
                ->helperText('Accountant: full access · Assistant: prepares invoices/expenses, no payment view · Director: payments, approvals, dashboard · Paralegal: view + requests.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->weight('semibold'),
            TextColumn::make('email')->searchable(),
            TextColumn::make('roles.name')->label('Role')->badge()->color(fn ($state) => match ($state) {
                PlatformAccess::ACCOUNTANT => 'danger', PlatformAccess::DIRECTOR => 'warning', PlatformAccess::ASSISTANT => 'info', default => 'gray',
            }),
            \Filament\Tables\Columns\IconColumn::make('is_active')->label('Active')->boolean(),
        ])->recordActions([EditAction::make()->visible(fn (User $record) => static::canEdit($record))]);
    }

    public static function getPages(): array
    {
        return ['index' => UserResource\Pages\ManageRecords::route('/')];
    }
}
