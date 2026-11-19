<?php

namespace App\Filament\Pages;

use App\Exports\RowsExport;
use App\Filament\Resources\ExpenseResource\BankFormA2;
use App\Models\RemittanceForm;
use App\Services\Money;
use App\Services\RemittanceFormService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

/** Every Form A2 and what happened to it: handed to the bank, remitted (reference, rate, charges), 15CA/CB, cancelled. */
class RemittanceRegister extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\UnitEnum|null $navigationGroup = 'Expenses';

    protected static ?string $navigationLabel = 'Remittance register';

    protected static ?int $navigationSort = 4;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $title = 'Remittance register';

    protected static ?string $slug = 'remittance-register';

    protected string $view = 'filament.pages.remittance-register';

    public static function canAccess(): bool
    {
        return RemittanceForms::canAccess();
    }

    protected function getHeaderActions(): array
    {
        return [
            BankFormA2::newAction(),
            Action::make('export')->label('Export to Excel')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->action(fn () => Excel::download(new RowsExport($this->exportRows()), 'Remittance-register-'.today()->toDateString().'.xlsx')),
        ];
    }

    /** @return array<int, array<int, mixed>> */
    public function exportRows(): array
    {
        $rows = [['Form date', 'Status', 'Beneficiary', 'Country', 'Bills (invoice no.)', 'Currency', 'Amount', 'Exchange rate', 'INR equivalent', 'INR debited', 'Bank charges (INR)', 'Remittance bank', 'Remittance account', 'Charges bank', 'Charges account', 'Handed to bank', 'Remitted on', 'Bank reference', '15CA acknowledgement', '15CA date', '15CB number', '15CB date', 'Purpose code', 'Prepared by', 'Cancel reason']];
        $query = $this->getFilteredSortedTableQuery() ?? RemittanceForm::query();
        foreach ($query->with(['expenses', 'debitBank', 'chargesBank', 'preparer'])->get() as $f) {
            $rows[] = [
                $f->data['date'] ?? $f->created_at->toDateString(), $f->status, $f->beneficiary, $f->data['country'] ?? '', $f->expenses->pluck('vendor_invoice_no')->filter()->implode(', '),
                $f->currency, (float) $f->amount, $f->exchange_rate !== null ? (float) $f->exchange_rate : '', $f->exchange_rate !== null ? (float) Money::mul((string) $f->amount, (string) $f->exchange_rate) : '',
                $f->inr_debited !== null ? (float) $f->inr_debited : '', $f->bank_charges_inr !== null ? (float) $f->bank_charges_inr : '',
                $f->debitBank?->bank_name ?? '', $f->debitBank?->account_no ?? '', $f->chargesBank?->bank_name ?? '', $f->chargesBank?->account_no ?? '',
                $f->submitted_on?->toDateString() ?? '', $f->remitted_on?->toDateString() ?? '', $f->bank_reference ?? '',
                $f->form_15ca_ack ?? '', $f->form_15ca_date?->toDateString() ?? '', $f->form_15cb_no ?? '', $f->form_15cb_date?->toDateString() ?? '',
                $f->data['purpose_code'] ?? '', $f->preparer?->name ?? '', $f->cancelled_reason ?? '',
            ];
        }

        return $rows;
    }

    public function table(Table $table): Table
    {
        return $table->query(RemittanceForm::query()->with(['expenses', 'debitBank', 'chargesBank', 'preparer']))->columns([
            TextColumn::make('created_at')->label('Form date')->state(fn (RemittanceForm $r) => $r->data['date'] ?? $r->created_at)->date('d.m.Y')->sortable(),
            TextColumn::make('beneficiary')->searchable()->wrap()->weight('semibold')->description(fn (RemittanceForm $r) => $r->expenses->pluck('vendor_invoice_no')->filter()->map(fn ($n) => 'Inv '.$n)->implode(', ') ?: null),
            TextColumn::make('amount')->formatStateUsing(fn ($state, RemittanceForm $r) => Money::format((string) $state, $r->currency))->alignEnd()->sortable()
                ->description(fn (RemittanceForm $r) => $r->exchange_rate !== null ? '₹ '.RemittanceFormService::rupees(Money::mul((string) $r->amount, (string) $r->exchange_rate)).' at '.$r->exchange_rate : null),
            TextColumn::make('status')->badge()->color(fn ($state) => RemittanceForm::STATUSES[$state] ?? 'gray')
                ->description(fn (RemittanceForm $r) => match ($r->status) {
                    'Submitted' => 'to bank '.$r->submitted_on?->format('d.m.Y'), 'Remitted' => $r->remitted_on?->format('d.m.Y').' · '.$r->bank_reference, 'Cancelled' => $r->cancelled_reason, default => null
                }),
            TextColumn::make('debitBank.bank_name')->label('Banks')->placeholder('-')->description(fn (RemittanceForm $r) => 'charges: '.($r->chargesBank?->bank_name ?? '-'))->toggleable(),
            TextColumn::make('inr_debited')->label('INR debited')->state(fn (RemittanceForm $r) => $r->inr_debited !== null ? '₹ '.RemittanceFormService::rupees((string) $r->inr_debited) : null)->placeholder('-')
                ->description(fn (RemittanceForm $r) => $r->bank_charges_inr !== null ? 'charges ₹ '.RemittanceFormService::rupees((string) $r->bank_charges_inr) : null)->toggleable(),
            TextColumn::make('form_15ca_ack')->label('15CA / 15CB')->placeholder('Not filed')->description(fn (RemittanceForm $r) => $r->form_15cb_no ? '15CB '.$r->form_15cb_no : null)->toggleable(),
            TextColumn::make('preparer.name')->label('By')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('status')->options(array_combine(array_keys(RemittanceForm::STATUSES), array_keys(RemittanceForm::STATUSES))),
            SelectFilter::make('currency')->options(RemittanceFormService::currencies()),
            Filter::make('no_15ca')->label('15CA not recorded')->query(fn (Builder $q) => $q->whereNull('form_15ca_ack')->where('status', '!=', 'Cancelled')),
            Filter::make('dates')->schema([DatePicker::make('from'), DatePicker::make('until')])->query(fn (Builder $q, array $data) => $q->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),
        ])->recordActions([
            Action::make('download')->label('Open (Word)')->icon('heroicon-o-document-arrow-down')->button()->size('sm')->url(fn (RemittanceForm $r) => route('crm.remittance-form', $r)),
            ActionGroup::make([
                Action::make('submitted')->label('Handed to the bank')->icon('heroicon-o-building-library')->visible(fn (RemittanceForm $r) => $r->status === 'Prepared')
                    ->schema([DatePicker::make('date')->label('Given to the bank on')->default(today())->required()->maxDate(today())])
                    ->action(fn (RemittanceForm $r, array $data) => app(RemittanceFormService::class)->markSubmitted($r, $data['date'], auth()->user())),
                Action::make('remitted')->label('Remitted by the bank')->icon('heroicon-o-banknotes')->color('success')
                    ->visible(fn (RemittanceForm $r) => $r->isOpen() && auth()->user()->allowed('accounting.expenses.pay'))
                    ->modalDescription('The bank has sent the money. Its reference becomes the payment reference of the bills on this form, and they are marked paid.')
                    ->fillForm(fn (RemittanceForm $r) => ['remitted_on' => today()->toDateString(), 'exchange_rate' => $r->exchange_rate])
                    ->schema([Grid::make(2)->schema([
                        DatePicker::make('remitted_on')->label('Remitted on')->required()->maxDate(today()),
                        TextInput::make('bank_reference')->label('Bank reference / UTR / SWIFT ref')->required()->maxLength(255),
                        TextInput::make('exchange_rate')->label('Exchange rate applied')->numeric()->minValue(0.0001),
                        TextInput::make('inr_debited')->label('INR debited for the remittance')->numeric()->minValue(0),
                        TextInput::make('bank_charges_inr')->label('Bank charges (INR)')->numeric()->minValue(0),
                        FileUpload::make('advice_path')->label('Bank advice / MT103 (PDF or image)')->disk(config('crm.disk'))->directory('Remittances/'.now()->format('Y-m'))->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize(25600)->visibility('private'),
                    ])])
                    ->action(function (RemittanceForm $r, array $data): void {
                        $paid = app(RemittanceFormService::class)->markRemitted($r, $data, auth()->user());
                        Notification::make()->title('Remittance recorded')->body($paid ? $paid.' bill(s) marked paid.' : 'No unpaid bill was linked to this form.')->success()->send();
                    }),
                Action::make('form_15ca')->label('15CA / 15CB details')->icon('heroicon-o-clipboard-document-check')->visible(fn (RemittanceForm $r) => $r->status !== 'Cancelled')
                    ->modalDescription('When the 15CA acknowledgement is entered, the bills on this form show the form as Filed.')
                    ->fillForm(fn (RemittanceForm $r) => $r->only(['form_15ca_ack', 'form_15ca_date', 'form_15cb_no', 'form_15cb_date']))
                    ->schema([Grid::make(2)->schema([
                        TextInput::make('form_15ca_ack')->label('15CA acknowledgement no.')->maxLength(255), DatePicker::make('form_15ca_date')->label('15CA date')->maxDate(today()),
                        TextInput::make('form_15cb_no')->label('15CB certificate / UDIN no.')->maxLength(255), DatePicker::make('form_15cb_date')->label('15CB date')->maxDate(today()),
                    ])])
                    ->action(fn (RemittanceForm $r, array $data) => app(RemittanceFormService::class)->save15caCb($r, $data, auth()->user())),
                BankFormA2::editAction(),
                BankFormA2::copyAction(),
                Action::make('advice')->label('Bank advice')->icon('heroicon-o-paper-clip')->visible(fn (RemittanceForm $r) => filled($r->advice_path))->url(fn (RemittanceForm $r) => route('crm.remittance-advice', $r))->openUrlInNewTab(),
                Action::make('cancel')->label('Cancel form')->icon('heroicon-o-x-circle')->color('danger')->visible(fn (RemittanceForm $r) => $r->isOpen())
                    ->requiresConfirmation()->modalDescription('The form stays in the register as Cancelled and its bills can go on a new form.')
                    ->schema([Textarea::make('reason')->required()->maxLength(1000)])
                    ->action(fn (RemittanceForm $r, array $data) => app(RemittanceFormService::class)->cancel($r, $data['reason'], auth()->user())),
            ])->label('More')->button()->size('sm')->color('gray'),
        ])->defaultSort('id', 'desc')->defaultPaginationPageOption(25)
            ->emptyStateHeading('No Form A2 yet')->emptyStateDescription('Use New Form A2, or Prepare Form A2 on a bill.')->emptyStateIcon('heroicon-o-arrows-right-left');
    }
}
