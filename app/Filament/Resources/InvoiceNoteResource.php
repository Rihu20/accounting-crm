<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InvoiceNoteResource\Pages\ManageRecords;
use App\Models\Invoice;
use App\Models\InvoiceNote;
use App\Services\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class InvoiceNoteResource extends CrmResource
{
    protected static ?string $model = InvoiceNote::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Invoices';

    protected static ?string $navigationLabel = 'Credit & Debit Notes';

    protected static ?int $navigationSort = 4;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-refund';

    protected static ?string $modelLabel = 'credit / debit note';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->allowed('accounting.notes.view');
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->allowed('accounting.notes.create');
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /** Form used by the "New note" header action (numbers, GST and PDF are automatic). */
    public static function noteSchema(): array
    {
        return [
            Select::make('type')->options(['credit' => 'Credit Note (reduce invoice)', 'debit' => 'Debit Note (increase invoice)'])->required()->default('credit'),
            Select::make('invoice_id')->label('Against tax invoice')->options(fn () => Invoice::with('client')->where('doc_type', 'tax')->where('status', 'Issued')->latest('invoice_date')->get()->mapWithKeys(fn ($i) => [$i->id => $i->number.' – '.$i->client->name.' – '.Money::format((string) $i->grand_total, $i->currency)]))->searchable()->required(),
            DatePicker::make('note_date')->label('Date')->required()->default(today()),
            TextInput::make('taxable_amount')->label('Professional fees (GST applies for Indian clients)')->numeric()->minValue(0)->default(0),
            TextInput::make('non_taxable_amount')->label('Official fees / other (no GST)')->numeric()->minValue(0)->default(0),
            Textarea::make('reason')->required()->maxLength(2000)->columnSpanFull(),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::noteSchema())->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->striped()->columns([
            TextColumn::make('number')->searchable()->weight('semibold'),
            TextColumn::make('type')->badge()->formatStateUsing(fn ($state) => $state === 'debit' ? 'Debit Note' : 'Credit Note')->color(fn ($state) => $state === 'debit' ? 'warning' : 'info'),
            TextColumn::make('invoice.number')->label('Against invoice')->searchable(),
            TextColumn::make('invoice.client.name')->label('Client')->searchable()->wrap(),
            TextColumn::make('note_date')->label('Date')->date('d.m.Y')->sortable(),
            TextColumn::make('gst')->label('GST')->state(fn (InvoiceNote $record) => $record->gstTotal())->formatStateUsing(fn ($state, $record) => Money::format((string) $state, $record->currency ?? $record->invoice->currency))->alignEnd(),
            TextColumn::make('amount')->label('Total')->formatStateUsing(fn ($state, $record) => Money::format((string) $state, $record->currency ?? $record->invoice->currency))->alignEnd()->weight('semibold'),
            TextColumn::make('reason')->wrap()->limit(80),
            TextColumn::make('creator.name')->label('Created by')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('type')->options(['credit' => 'Credit Note', 'debit' => 'Debit Note']),
        ])->recordActions([
            Action::make('pdf')->label('PDF')->icon('heroicon-o-document-arrow-down')->url(fn (InvoiceNote $record) => url('/crm/notes/'.$record->id.'/pdf'))->openUrlInNewTab(),
        ])->defaultSort('note_date', 'desc')->emptyStateHeading('No credit or debit notes')->emptyStateDescription('Raise one from a tax invoice row, or with “New note”.')->emptyStateIcon('heroicon-o-receipt-refund');
    }

    public static function getPages(): array
    {
        return ['index' => ManageRecords::route('/')];
    }
}
