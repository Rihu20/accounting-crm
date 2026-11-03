<?php

namespace App\Filament\Resources;

use App\Models\Client;
use App\Models\InvoiceDocument;
use App\Services\AccountingFileService;
use App\Services\OptimisticUpdate;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class InvoiceDocumentResource extends CrmResource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Invoices';

    protected static ?string $navigationLabel = 'Uploaded invoice PDFs';

    protected static ?int $navigationSort = 5;

    protected static ?string $model = InvoiceDocument::class;

    protected static ?string $slug = 'invoice-documents';

    public static function canViewAny(): bool
    {
        return (bool) (auth()->user()?->allowed('accounting.invoices.create') || auth()->user()?->allowed('accounting.invoices.export'));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->allowed('accounting.invoices.edit') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Hidden::make('lock_version'), TextInput::make('file_name')->disabled()->dehydrated(false), Select::make('doc_type')->label('Invoice type')->options(['proforma' => 'Proforma', 'tax' => 'Tax invoice'])->required(), TextInput::make('invoice_no')->label('Invoice number')->maxLength(255), Select::make('client_id')->relationship('client', 'name')->searchable()->preload(), DatePicker::make('invoice_date')->label('Invoice date'), TextInput::make('period_year')->label('Archive year')->numeric()->minValue(1900)->maxValue(2100)->requiredWith('period_month'), Select::make('period_month')->label('Archive month')->options(self::months())->requiredWith('period_year'), Textarea::make('notes')->maxLength(4000)])->columns(2);
    }

    public static function months(): array
    {
        return [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];
    }

    public static function uploadAction(): Action
    {
        return Action::make('upload_pdfs')->label('Upload invoice PDFs')->visible(fn () => auth()->user()->allowed('accounting.invoices.create'))->modalDescription('Upload existing invoice PDFs, including your April–October files. They stay as original documents; this upload does not create receivables or change invoice numbering.')->schema([
            Select::make('doc_type')->label('Invoice type')->options(['proforma' => 'Proforma', 'tax' => 'Tax invoice'])->required(),
            FileUpload::make('files')->label('PDF files')->disk(config('crm.disk'))->directory(fn () => 'InvoiceUploads/'.auth()->id())->visibility('private')->acceptedFileTypes(['application/pdf'])->maxSize(25600)->multiple()->maxFiles(20)->storeFileNamesIn('file_names')->required()->helperText('Up to 20 PDFs per batch, 25 MB each. Use smaller batches if your connection or server limits the upload.'), Hidden::make('file_names')->default([]),
            Select::make('client_id')->label('Client (optional)')->options(Client::pluck('name', 'id'))->searchable(),
            TextInput::make('period_year')->label('Archive year (optional)')->numeric()->minValue(1900)->maxValue(2100)->requiredWith('period_month'), Select::make('period_month')->label('Archive month (optional)')->options(self::months())->requiredWith('period_year'), Textarea::make('notes')->maxLength(4000),
        ])->action(function (array $data) {
            $paths = $data['files'];
            unset($data['files']);
            app(AccountingFileService::class)->archive($paths, $data, auth()->user());
        });
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('file_name')->label('Original PDF')->searchable()->wrap(), TextColumn::make('doc_type')->label('Type')->formatStateUsing(fn ($state) => $state === 'tax' ? 'Tax invoice' : 'Proforma')->badge(), TextColumn::make('invoice_no')->label('Invoice number')->searchable(), TextColumn::make('client.name')->searchable(), TextColumn::make('invoice_date')->date('d.m.Y')->sortable(), TextColumn::make('period')->state(fn ($record) => $record->period_month && $record->period_year ? self::months()[$record->period_month].' '.$record->period_year : 'Not set'), TextColumn::make('uploader.name')->label('Uploaded by'), TextColumn::make('created_at')->dateTime('d.m.Y H:i')->sortable()])->filters([SelectFilter::make('period_month')->label('Month')->options(self::months()), SelectFilter::make('period_year')->label('Year')->options(fn () => InvoiceDocument::whereNotNull('period_year')->distinct()->orderByDesc('period_year')->pluck('period_year', 'period_year')->all())])->recordActions([Action::make('pdf')->label('View PDF')->url(fn ($record) => route('crm.invoice-document', $record))->openUrlInNewTab(), EditAction::make()->label('Edit details')->using(fn (Model $record, array $data) => app(OptimisticUpdate::class)->save($record, $data))])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => InvoiceDocumentResource\Pages\ManageRecords::route('/')];
    }
}
