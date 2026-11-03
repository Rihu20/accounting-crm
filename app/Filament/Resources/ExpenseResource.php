<?php

namespace App\Filament\Resources;

use App\Models\Expense;
use App\Models\PatentOffice;
use App\Services\ExpenseGroups;
use App\Services\ExpenseService;
use App\Services\Money;
use App\Services\OptimisticUpdate;
use App\Services\PortalWorkflow;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ExpenseResource extends CrmResource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Expenses';

    protected static ?string $navigationLabel = 'Expense register';

    protected static ?int $navigationSort = 1;

    protected static ?string $model = Expense::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    public static function canViewAny(): bool
    {
        return (bool) (auth()->user()?->allowed('accounting.expenses.view_all') || auth()->user()?->allowed('accounting.expenses.view_paralegal'));
    }

    public static function canCreate(): bool
    {
        return auth()->user()->allowed('accounting.expenses.create');
    }

    public static function canEdit(Model $record): bool
    {
        if (! auth()->user()->allowed('accounting.expenses.edit_all')) {
            return false;
        }

        // Once paid, only the Accountant may still correct the entry.
        return $record->status !== 'Paid' || auth()->user()->allowed('accounting.expenses.pay') && auth()->user()->allowed('accounting.expenses.edit_all');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['vendor', 'patentOffice']);
        return auth()->user()->allowed('accounting.expenses.view_all') ? $query : PortalWorkflow::paralegalExpenses($query);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Hidden::make('lock_version')->default(0),
            Select::make('expense_group')->label('Expense group')->options(ExpenseGroups::LABELS)->default(fn ($livewire) => in_array($livewire->activeTab, ['foreign', 'patent_office']) ? $livewire->activeTab : 'indian')->required()->live()->afterStateUpdated(function (Set $set) {
                $set('category', null);
                $set('patent_office_id', null);
                $set('taxable_amount', null);
            }),
            Select::make('patent_office_id')->label('Patent / IP office')->options(fn () => PatentOffice::where('is_active', true)->orderBy('country')->get()->mapWithKeys(fn ($office) => [$office->id => $office->display_name]))->searchable()->visible(fn (Get $get) => $get('expense_group') === 'patent_office')->required(fn (Get $get) => $get('expense_group') === 'patent_office'),
            TextInput::make('party_country')->label('Party country')->maxLength(255),
            TextInput::make('our_ref')->label('Our full matter reference')->maxLength(255),
            Textarea::make('purpose')->label('Purpose of invoice')->maxLength(4000),
            TextInput::make('pan_number')->label('PAN number')->maxLength(20)->visible(fn (Get $get) => $get('expense_group') === 'indian'),
            TextInput::make('gstin')->label('GST number')->maxLength(30)->visible(fn (Get $get) => $get('expense_group') === 'indian'),
            TextInput::make('taxable_amount')->label('Taxable amount (INR)')->numeric()->minValue(0.01)->live(onBlur: true)->visible(fn (Get $get) => $get('expense_group') === 'indian')->helperText('Optional. Fill this to calculate GST, TDS and total payable automatically.'),
            Select::make('gst_mode')->label('GST split')->options(['igst' => 'IGST', 'cgst_sgst' => 'CGST + SGST'])->default('igst')->required()->visible(fn (Get $get) => $get('expense_group') === 'indian'),
            TextInput::make('gst_rate')->label('Total GST rate (%)')->numeric()->minValue(0)->maxValue(100)->default(0)->required()->visible(fn (Get $get) => $get('expense_group') === 'indian')->helperText('Enter 18 for 9% CGST + 9% SGST.'),
            TextInput::make('tds_section')->label('TDS section')->maxLength(40)->visible(fn (Get $get) => $get('expense_group') === 'indian'),
            TextInput::make('tds_rate')->label('TDS rate (%)')->numeric()->minValue(0)->maxValue(100)->default(0)->required()->visible(fn (Get $get) => $get('expense_group') === 'indian'),
            TextInput::make('payee_name')->label('Paid to / law firm / supplier')->maxLength(512),
            Select::make('expense_type')->options(auth()->user()->allowed('accounting.expenses.edit_all') ? ['owner' => 'Owner expense', 'vendor' => 'Vendor invoice'] : ['owner' => 'Owner expense'])->default(fn () => auth()->user()->allowed('accounting.expenses.edit_all') ? 'vendor' : 'owner')->required(), DatePicker::make('invoice_date')->label('Date')->default(today())->required()->displayFormat('d.m.Y'), TextInput::make('amount')->label('Total payable')->numeric()->minValue(0.01)->required(fn (Get $get) => blank($get('taxable_amount')))->helperText('For Indian purchases with a taxable amount, this is calculated on save: taxable + GST - TDS.'), Select::make('currency')->options(array_combine(config('crm.expense_currencies'), config('crm.expense_currencies')))->default('INR')->required(), Select::make('category')->options(fn (Get $get) => ExpenseGroups::categories($get('expense_group')))->default(fn ($livewire) => $livewire->activeTab === 'indian_admin' ? 'Admin expense' : null)->required(), Select::make('paid_by')->options(auth()->user()->allowed('accounting.expenses.edit_all') ? ['company_bank' => 'Company bank', 'owner_card' => 'Owner credit card', 'owner_personal' => 'Owner personal', 'cash' => 'Cash'] : ['owner_card' => 'Owner credit card', 'owner_personal' => 'Owner personal', 'cash' => 'Cash'])->required(), TextInput::make('card_label')->helperText('Label only, such as HDFC CC. Never enter a card number.')->maxLength(40)->notRegex('/\d{12,}/'), TextInput::make('ref_no')->label('Client Ref No')->nullable()->regex('/^\d{4}$/')->exists('clients', 'ref_no'), FileUpload::make('bill_path')->label('Expense PDF / receipt')->required(fn (?Model $record) => $record === null)->disk(config('crm.disk'))->directory('Expenses/'.now()->format('Y-m'))->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize(25600)->visibility('private'), Textarea::make('notes'), Select::make('vendor_id')->relationship('vendor', 'name')->searchable()->preload()->visible(fn () => auth()->user()->allowed('accounting.expenses.edit_all')), TextInput::make('vendor_invoice_no')->visible(fn () => auth()->user()->allowed('accounting.expenses.edit_all')), DatePicker::make('due_date')->visible(fn () => auth()->user()->allowed('accounting.expenses.edit_all')), TextInput::make('gst_amount')->numeric()->minValue(0)->default(0)->visible(fn () => auth()->user()->allowed('accounting.expenses.edit_all')), Toggle::make('requires_15ca_cb')->visible(fn () => auth()->user()->allowed('accounting.expenses.edit_all')), TextInput::make('form_status')->visible(fn () => auth()->user()->allowed('accounting.expenses.edit_all')), Toggle::make('tds_applicable')->visible(fn () => auth()->user()->allowed('accounting.expenses.edit_all')), TextInput::make('tds_amount')->numeric()->minValue(0)->default(0)->visible(fn () => auth()->user()->allowed('accounting.expenses.edit_all')),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        $pay = fn () => auth()->user()->allowed('payments.view') || auth()->user()->allowed('accounting.expenses.pay');

        return $table->striped()->persistColumnsInSession()->columns([
            TextColumn::make('vendor_invoice_no')->label('Invoice No')->searchable()->placeholder('-')->weight('semibold'),
            TextColumn::make('invoice_date')->label('Invoice Date')->date('d.m.Y')->sortable(),
            TextColumn::make('payee_name')->label('Party Name')->state(fn ($record) => $record->payee_name ?: $record->vendor?->name)->searchable()->wrap(),
            TextColumn::make('currency')->badge(),
            TextColumn::make('amount')->label('Invoice Value / Total Payable')->formatStateUsing(fn ($state, $record) => Money::format((string) $state, $record->currency))->alignEnd()->sortable(),
            TextColumn::make('party_country')->label('Party Country')->state(fn ($record) => $record->party_country ?: $record->vendor?->country ?: $record->patentOffice?->country)->searchable()->toggleable(),
            TextColumn::make('our_ref')->label('Our Ref')->searchable()->toggleable(),
            TextColumn::make('purpose')->label('Purpose of Invoice')->wrap()->limit(80)->toggleable(),
            TextColumn::make('payment_request.status')->label('Payment request')->badge()->placeholder('-')->color(fn ($state) => $state === 'Pending' ? 'warning' : ($state === 'Approved' ? 'success' : 'danger'))->description(fn ($record) => $record->payment_request['requested_by_name'] ?? null)->toggleable(),
            TextColumn::make('status')->badge()->color(fn ($state) => $state === 'Paid' ? 'success' : 'warning')->visible($pay),
            TextColumn::make('paid_on')->label('Transfer Date')->date('d.m.Y')->placeholder('-')->toggleable()->visible($pay),
            TextColumn::make('payment_bank_name')->label('Bank (Payment Transferred)')->placeholder('-')->toggleable()->visible($pay),
            TextColumn::make('notes')->label('Comment')->wrap()->limit(80)->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('pan_number')->label('PAN Number')->visible(fn ($livewire) => in_array($livewire->activeTab, ['indian', 'indian_admin']))->toggleable(),
            TextColumn::make('gstin')->label('GST No')->visible(fn ($livewire) => in_array($livewire->activeTab, ['indian', 'indian_admin']))->toggleable(),
            TextColumn::make('taxable_amount')->label('Taxable Amount')->numeric(decimalPlaces: 2)->visible(fn ($livewire) => in_array($livewire->activeTab, ['indian', 'indian_admin']))->toggleable(),
            TextColumn::make('gst_rate')->label('GST Rate %')->visible(fn ($livewire) => in_array($livewire->activeTab, ['indian', 'indian_admin']))->toggleable(),
            TextColumn::make('igst')->label('IGST')->numeric(decimalPlaces: 2)->visible(fn ($livewire) => in_array($livewire->activeTab, ['indian', 'indian_admin']))->toggleable(),
            TextColumn::make('cgst')->label('CGST')->numeric(decimalPlaces: 2)->visible(fn ($livewire) => in_array($livewire->activeTab, ['indian', 'indian_admin']))->toggleable(),
            TextColumn::make('sgst')->label('SGST')->numeric(decimalPlaces: 2)->visible(fn ($livewire) => in_array($livewire->activeTab, ['indian', 'indian_admin']))->toggleable(),
            TextColumn::make('tds_section')->label('TDS Section')->visible(fn ($livewire) => in_array($livewire->activeTab, ['indian', 'indian_admin']))->toggleable(),
            TextColumn::make('tds_rate')->label('TDS Rate %')->visible(fn ($livewire) => in_array($livewire->activeTab, ['indian', 'indian_admin']))->toggleable(),
            TextColumn::make('tds_amount')->label('TDS')->numeric(decimalPlaces: 2)->visible(fn ($livewire) => in_array($livewire->activeTab, ['indian', 'indian_admin']))->toggleable(),
            TextColumn::make('expense_group')->label('Group')->formatStateUsing(fn ($state) => ExpenseGroups::LABELS[$state] ?? $state)->badge()->toggleable(),
            TextColumn::make('category')->searchable()->toggleable(),
            TextColumn::make('patentOffice.acronym')->label('IP Office')->toggleable(),
            TextColumn::make('paid_by')->badge()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('reimbursement_status')->badge()->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('expense_type')->options(['owner' => 'Owner', 'vendor' => 'Vendor']),
            SelectFilter::make('status')->options(['Unpaid' => 'Unpaid', 'Paid' => 'Paid'])->visible($pay),
            SelectFilter::make('currency')->options(array_combine(config('crm.expense_currencies'), config('crm.expense_currencies'))),
            SelectFilter::make('reimbursement_status')->options(['Pending' => 'Pending', 'Reimbursed' => 'Reimbursed', 'Not reimbursable' => 'Not reimbursable']),
            Filter::make('dates')->schema([DatePicker::make('from'), DatePicker::make('until')])->query(fn (Builder $query, array $data) => $query->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('invoice_date', '>=', $date))->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('invoice_date', '<=', $date))),
        ])->recordActions([
            Action::make('request_payment')->label('Request payment')->icon('heroicon-o-megaphone')->color('warning')->button()->size('sm')
                ->visible(fn (Expense $record) => auth()->user()->allowed('requests.create') && in_array($record->expense_group, PortalWorkflow::PARALEGAL_GROUPS, true) && $record->status === 'Unpaid' && ! in_array($record->payment_request['status'] ?? null, ['Pending', 'Approved']))
                ->modalDescription('Director / Accountant will be asked to pay this invoice.')
                ->schema([Textarea::make('note')->label('Why / by when')->maxLength(1000)])
                ->action(function (Expense $record, array $data) {
                    app(PortalWorkflow::class)->requestExpense($record, auth()->user(), $data['note'] ?? null);
                    \Filament\Notifications\Notification::make()->title('Payment request sent')->success()->send();
                }),
            Action::make('review_payment')->label('Review request')->icon('heroicon-o-check-badge')->color('warning')->button()->size('sm')
                ->visible(fn (Expense $record) => PortalWorkflow::canReviewRequests(auth()->user()) && $record->status === 'Unpaid' && ($record->payment_request['status'] ?? null) === 'Pending')
                ->modalDescription(fn (Expense $record) => ($record->payment_request['requested_by_name'] ?? 'Paralegal').': '.($record->payment_request['note'] ?? 'Please pay this invoice.'))
                ->schema([Select::make('decision')->options(['approve' => 'Approve payment', 'reject' => 'Reject'])->required()])
                ->action(fn (Expense $record, array $data) => app(PortalWorkflow::class)->decideExpense($record, auth()->user(), $data['decision'] === 'approve')),
            Action::make('view_bill')->label('View PDF / receipt')->visible(fn ($record) => filled($record->bill_path))->url(fn ($record) => route('crm.expense-file', $record))->openUrlInNewTab(), EditAction::make()->visible(fn (Model $record) => self::canEdit($record))->using(fn (Model $record, array $data) => app(OptimisticUpdate::class)->save($record, $data)), Action::make('paid')->label('Mark paid')->icon('heroicon-o-banknotes')->color('success')->visible(fn ($record) => auth()->user()->allowed('accounting.expenses.pay') && $record->expense_type === 'vendor' && $record->status === 'Unpaid')->schema([DatePicker::make('paid_on')->default(today())->required(), TextInput::make('paid_ref')->label('Transaction reference')->required(), TextInput::make('payment_bank_name')->label('Bank name (payment transferred)')->maxLength(255)])->action(fn ($record, array $data) => app(ExpenseService::class)->paid($record, $data, auth()->user())), Action::make('unpaid')->label('Mark unpaid')->icon('heroicon-o-arrow-uturn-left')->color('danger')->visible(fn ($record) => auth()->user()->allowed('accounting.expenses.pay') && $record->expense_type === 'vendor' && $record->status === 'Paid')->schema([Textarea::make('reason')->required()->maxLength(500)])->action(fn ($record, array $data) => app(ExpenseService::class)->unpaid($record, $data['reason'], auth()->user())), Action::make('reimburse')->visible(fn ($record) => auth()->user()->allowed('accounting.expenses.pay') && $record->expense_type === 'owner' && $record->reimbursement_status === 'Pending')->schema([Select::make('reimbursement_status')->options(['Reimbursed' => 'Reimbursed', 'Not reimbursable' => 'Not reimbursable'])->required(), DatePicker::make('reimbursed_on')->default(today())->required(), TextInput::make('reimbursed_ref')->required()])->action(fn ($record, array $data) => app(ExpenseService::class)->reimburse($record, $data, auth()->user()))], position: \Filament\Tables\Enums\RecordActionsPosition::BeforeColumns)->emptyStateHeading('No expenses here yet')->emptyStateIcon('heroicon-o-receipt-percent')->defaultSort('invoice_date', 'desc')->defaultPaginationPageOption(25);
    }

    public static function getPages(): array
    {
        return ['index' => ExpenseResource\Pages\ManageRecords::route('/')];
    }
}
