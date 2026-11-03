<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PayrollEntryResource\Pages\ManageRecords;
use App\Models\PayrollEntry;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class PayrollEntryResource extends CrmResource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Payroll';

    protected static ?int $navigationSort = 8;

    protected static ?string $model = PayrollEntry::class;

    protected static ?string $slug = 'payroll';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->allowed('accounting.payroll.manage');
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('employee')->required()->maxLength(255),
            DatePicker::make('period')->label('Payroll month')->required()->default(today()->startOfMonth())
                ->dehydrateStateUsing(fn (string $state): string => Carbon::parse($state)->startOfMonth()->toDateString()),
            TextInput::make('gross')->label('Gross pay (INR)')->required()->numeric()->minValue(0)->maxValue(999999999999.99)->regex('/^\d+(\.\d{1,2})?$/'),
            TextInput::make('deductions')->label('Deductions (INR)')->required()->numeric()->minValue(0)->lte('gross')->default(0)->regex('/^\d+(\.\d{1,2})?$/'),
            DatePicker::make('paid_on')->label('Paid date')->helperText('Fill this after payroll has been paid.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('employee')->searchable()->sortable(),
            TextColumn::make('period')->date('M Y')->sortable(),
            TextColumn::make('gross')->money('INR'),
            TextColumn::make('deductions')->money('INR'),
            TextColumn::make('net')->state(fn (PayrollEntry $record): string => $record->net())->money('INR'),
            TextColumn::make('paid_on')->date()->placeholder('Pending'),
        ])->recordActions([EditAction::make()])->defaultSort('period', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageRecords::route('/')];
    }
}
