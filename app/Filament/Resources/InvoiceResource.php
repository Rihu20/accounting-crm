<?php

namespace App\Filament\Resources;

use App\Models\BankAccount;
use App\Models\Invoice;
use App\Models\PaymentGateway;
use App\Services\InvoiceFormat;
use App\Services\InvoiceRegisterService;
use App\Services\InvoiceService;
use App\Services\Money;
use App\Services\NoteService;
use App\Services\OptimisticUpdate;
use App\Services\PaymentService;
use App\Services\PortalWorkflow;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InvoiceResource extends CrmResource
{
    protected static ?string $model = Invoice::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 2;

    protected static string|\UnitEnum|null $navigationGroup = 'Invoices';

    protected static ?string $navigationLabel = 'Proforma & Tax Invoices';

    protected static ?string $modelLabel = 'invoice';

    protected static ?string $recordTitleAttribute = 'number';

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'client.name', 'client.ref_no', 'items.our_ref'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return ($record->number ?: 'Draft').' · '.$record->client?->name;
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['Type' => $record->doc_type === 'tax' ? 'Tax invoice' : 'Proforma', 'Value' => Money::format((string) $record->grand_total, $record->currency), 'Status' => self::displayStatus($record)];
    }

    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return self::getUrl().'?tableSearch='.urlencode($record->number ?: $record->client?->name);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('client');
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->allowed('accounting.invoices.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()->allowed('accounting.invoices.edit') && $record->status === 'Draft';
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }
        $count = match (true) {
            $user->allowed('accounting.tax.approve') => self::approvalsQuery(Invoice::query())->count(),
            $user->allowed('accounting.tax.issue') => Invoice::where('doc_type', 'proforma')->where('status', 'Received')->where('tax_authorization->status', 'Approved')->count(),
            default => 0,
        };

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['client', 'items', 'bankAccount', 'gateway', 'paralegal', 'taxInvoice', 'proforma.payments.bankAccount', 'proforma.payments.gateway', 'proforma.payments.markedBy', 'payments.bankAccount', 'payments.gateway', 'payments.markedBy']);
    }

    /** Proformas waiting for a Director / Accountant decision. */
    public static function approvalsQuery(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('payment_initiation->status', 'Pending')
            ->orWhere(fn (Builder $p) => $p->where('doc_type', 'proforma')->whereIn('status', ['Sent', 'Partially received', 'Received'])->where('tax_authorization->status', 'Pending'))
            ->orWhere(fn (Builder $p) => $p->where('doc_type', 'proforma')->where('status', 'Received')->whereNull('tax_authorization')));
    }

    public static function seesPayments(): bool
    {
        return (bool) auth()->user()?->allowed('payments.view');
    }

    /** Status as each role is allowed to see it. The Assistant never learns whether money came in. */
    public static function displayStatus(Invoice $record): string
    {
        if ($record->doc_type === 'tax') {
            return $record->status === 'Issued' ? 'Tax invoice issued' : $record->status;
        }
        $approval = $record->tax_authorization['status'] ?? null;
        if (! self::seesPayments() && in_array($record->status, ['Sent', 'Partially received', 'Received'])) {
            return match ($approval) {
                'Approved' => $record->status === 'Received' ? 'Approved for tax invoice' : 'Sent',
                'Pending' => 'Approval requested',
                'Rejected' => 'Approval rejected',
                default => 'Sent',
            };
        }
        if ($record->status === 'Received' && $approval === 'Approved') {
            return 'Approved for tax invoice';
        }

        return match ($record->status) {
            'Converted' => 'Tax invoice raised',
            'Sent' => 'Awaiting payment',
            default => $record->status,
        };
    }

    public static function additionalChargesField(): Repeater
    {
        return Repeater::make('additional_charges')->label('Additional charges')->schema([
            TextInput::make('label')->label('Charge name')->datalist(['Platform Fees', 'Payment Gateway Charges', 'Merchant Charges', 'Bank Charges', 'Other charges'])->required()->maxLength(80),
            TextInput::make('amount')->label('Amount (invoice currency)')->numeric()->minValue(0)->maxValue(999999999999.99)->required(),
        ])->columns(2)->defaultItems(0)->maxItems(10)->addActionLabel('Add charge')->columnSpanFull()->helperText('Choose a suggested name or type your own. Amounts are added to this invoice total.');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Hidden::make('lock_version')->default(0),
            self::additionalChargesField(), TextInput::make('disbursement_label')->label('Existing other-charge label')->maxLength(80)->visible(fn ($record) => $record && bccomp((string) $record->disbursement_total, '0', 2) > 0),
            Select::make('invoice_layout')->label('Invoice format')->options(InvoiceFormat::LABELS)->required()->helperText('Combined hides fee amounts; Detailed groups fees under each matter; India GST taxes Professional Fees only.'), DatePicker::make('invoice_date')->required()->displayFormat('d.m.Y'), DatePicker::make('due_date')->required()->displayFormat('d.m.Y'),
            Select::make('bank_account_id')->options(fn ($record) => BankAccount::where('is_active', true)->where('currency', $record?->currency)->get()->mapWithKeys(fn ($b) => [$b->id => $b->bank_name.' '.$b->currency]))->nullable(), Select::make('gateway_id')->options(PaymentGateway::where('is_active', true)->pluck('name', 'id'))->nullable(), Toggle::make('print_exchange_rate'),
            Repeater::make('items')->relationship()->schema([TextInput::make('our_ref')->label('Matter Ref')->disabled()->dehydrated(false), TextInput::make('ref_no')->label('Client Ref')->disabled(), TextInput::make('app_no')->label('App No')->maxLength(255), TextInput::make('pct_no')->label('PCT No')->maxLength(255), TextInput::make('matter_text')->required()->maxLength(200), Select::make('fee_type')->options(array_combine(InvoiceFormat::FEES, InvoiceFormat::FEES))->required(), TextInput::make('sac_code')->required(), TextInput::make('qty')->numeric()->minValue(0.01)->required(), TextInput::make('rate')->numeric()->minValue(0)->required(), Toggle::make('taxable')->label('GST applies to Professional Fees only')->disabled()->dehydrated(false), TextInput::make('tax_rate')->numeric()->minValue(0)->maxValue(100)->required()])->columns(4)->columnSpanFull()->addable(false)->deletable(false),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        $pay = fn () => self::seesPayments();
        $money = fn ($state, $record) => Money::format((string) $state, $record->currency);

        return $table->striped()->persistColumnsInSession()->columns([
            TextColumn::make('number')->label('Invoice No')->placeholder('Draft')->searchable()->sortable()->weight('semibold')->description(fn ($record) => $record->doc_type === 'tax' ? 'Tax invoice' : 'Proforma'),
            TextColumn::make('invoice_date')->label('Date')->date('d.m.Y')->sortable(),
            TextColumn::make('client.ref_no')->label('Client Ref')->searchable()->copyable(),
            TextColumn::make('client.name')->label('Client')->searchable()->limit(32)->tooltip(fn ($record) => $record->client?->name)->description(fn ($record) => $record->client?->country),
            TextColumn::make('items.our_ref')->label('Matter Ref')->searchable()->badge()->color('gray')->distinctList()->limitList(1)->expandableLimitedList()->toggleable(),
            TextColumn::make('paralegal.name')->label('Paralegal')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('currency')->badge()->color('gray'),
            TextColumn::make('grand_total')->label('Invoice Value')->formatStateUsing($money)->alignEnd()->sortable()->weight('semibold'),
            TextColumn::make('status')->label('Status')->badge()->state(fn ($record) => self::displayStatus($record))->color(fn ($state) => match ($state) {
                'Received', 'Tax invoice raised', 'Tax invoice issued', 'Approved for tax invoice' => 'success',
                'Draft', 'Cancelled' => 'gray',
                'Partially received', 'Approval requested' => 'info',
                'Approval rejected' => 'danger',
                default => 'warning',
            }),
            TextColumn::make('register_received')->label('Received')->state(fn ($record) => $record->registerPaymentTotal())->formatStateUsing($money)->alignEnd()->visible($pay),
            TextColumn::make('register_remaining')->label('Balance')->state(fn ($record) => $record->doc_type === 'proforma' ? $record->outstandingAmount() : null)->formatStateUsing($money)->alignEnd()->color('danger')->placeholder('-')->visible($pay),
            TextColumn::make('received_on')->label('Received on')->date('d.m.Y')->placeholder('-')->toggleable(isToggledHiddenByDefault: true)->visible($pay),
            TextColumn::make('payment_initiation.status')->label('Client payment report')->badge()->placeholder('-')->color(fn ($state) => $state === 'Pending' ? 'warning' : ($state === 'Approved' ? 'success' : 'gray'))->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('tax_authorization.status')->label('Tax invoice approval')->badge()->placeholder('-')->color(fn ($state) => $state === 'Pending' ? 'warning' : ($state === 'Approved' ? 'success' : 'danger'))->toggleable()->visible(fn () => ! auth()->user()->isRole('Paralegal')),
            TextColumn::make('proforma.number')->label('Proforma No')->searchable()->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('taxInvoice.number')->label('Tax Invoice No')->searchable()->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('inr_exchange_rate')->label('INR Rate')->state(fn ($record) => $record->currency === 'INR' ? '1' : $record->inr_exchange_rate)->placeholder('Not set')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('register_inr_amount')->label('Amount in INR')->state(fn ($record) => $record->registerInrAmount())->formatStateUsing(fn ($state) => Money::format((string) $state, 'INR'))->placeholder('Rate needed')->alignEnd()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('register_tds')->label('TDS')->state(fn ($record) => $record->registerPaymentTotal('tds_amount'))->formatStateUsing($money)->toggleable(isToggledHiddenByDefault: true)->visible($pay),
            TextColumn::make('register_charges')->label('Bank Charges')->state(fn ($record) => $record->registerPaymentTotal('charges_deducted'))->formatStateUsing($money)->toggleable(isToggledHiddenByDefault: true)->visible($pay),
            TextColumn::make('client.gstin')->label('Client GSTIN')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('register_comment')->label('Comment')->limit(60)->wrap()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('invoice_layout')->label('Format')->formatStateUsing(fn ($state) => InvoiceFormat::LABELS[$state] ?? $state)->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('doc_type')->label('Type')->options(['proforma' => 'Proforma', 'tax' => 'Tax invoice']),
            SelectFilter::make('status')->options(fn () => self::seesPayments()
                ? ['Draft' => 'Draft', 'Sent' => 'Awaiting payment', 'Partially received' => 'Partially received', 'Received' => 'Received', 'Converted' => 'Tax invoice raised', 'Issued' => 'Tax invoice issued']
                : ['Draft' => 'Draft', 'Converted' => 'Tax invoice raised', 'Issued' => 'Tax invoice issued']),
            SelectFilter::make('currency')->options(['USD' => 'USD', 'INR' => 'INR', 'EUR' => 'EUR']),
            SelectFilter::make('client_id')->label('Client')->relationship('client', 'name')->searchable(),
            SelectFilter::make('paralegal_id')->label('Paralegal')->relationship('paralegal', 'name'),
            Filter::make('overdue')->label('15+ days unpaid')->visible($pay)->query(fn (Builder $query) => $query->whereIn('status', ['Sent', 'Partially received'])->where('sent_at', '<=', now()->subDays(15))),
            Filter::make('dates')->schema([DatePicker::make('from'), DatePicker::make('until')])->query(fn (Builder $query, array $data) => $query->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('invoice_date', '>=', $d))->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('invoice_date', '<=', $d))),
        ])->recordActions([
            self::receiveAction(),
            ActionGroup::make([
                Action::make('reminder')->label('Payment reminder email')->icon('heroicon-o-envelope')
                    ->visible(fn (Invoice $record) => auth()->user()->allowed('payments.view') && $record->doc_type === 'proforma' && in_array($record->status, ['Sent', 'Partially received']))
                    ->modalHeading(fn (Invoice $record) => 'Reminder for '.$record->number)->modalWidth('2xl')
                    ->modalContent(fn (Invoice $record) => view('filament.parts.reminder', ['invoice' => $record, 'text' => self::reminderText($record)]))
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                Action::make('pdf')->label('View PDF')->icon('heroicon-o-document-arrow-down')->url(fn ($record) => url('/crm/invoices/'.$record->id.'/pdf'))->openUrlInNewTab(),

                // ---------- Paralegal: requests only ----------
                Action::make('report_payment')->label('Client has initiated payment')->icon('heroicon-o-megaphone')->color('warning')
                    ->visible(fn (Invoice $record) => auth()->user()->allowed('requests.create') && in_array($record->status, ['Sent', 'Partially received', 'Issued']) && ($record->payment_initiation['status'] ?? null) !== 'Pending')
                    ->modalDescription('Director / Accountant will be notified to check the bank.')
                    ->schema([TextInput::make('reference')->label('Payment details (date, amount, reference from client)')->required()->maxLength(255)])
                    ->action(function (Invoice $record, array $data) {
                        app(PortalWorkflow::class)->reportInvoicePayment($record, auth()->user(), $data['reference']);
                        Notification::make()->title('Sent to Director / Accountant')->success()->send();
                    }),

                // ---------- Director / Accountant: approvals ----------
                Action::make('review_payment')->label('Review client payment report')->icon('heroicon-o-check-badge')->color('warning')
                    ->visible(fn (Invoice $record) => PortalWorkflow::canReviewRequests(auth()->user()) && ($record->payment_initiation['status'] ?? null) === 'Pending')
                    ->modalDescription(fn (Invoice $record) => ($record->payment_initiation['requested_by_name'] ?? 'Paralegal').' reports: '.($record->payment_initiation['reference'] ?? ''))
                    ->schema([Select::make('decision')->options(['approve' => 'Confirmed – client has initiated', 'reject' => 'Not found / reject'])->required()])
                    ->action(fn (Invoice $record, array $data) => app(PortalWorkflow::class)->reviewInvoicePayment($record, auth()->user(), $data['decision'] === 'approve')),
                Action::make('decide_tax')->label('Approve tax invoice')->icon('heroicon-o-shield-check')->color('success')
                    ->visible(fn (Invoice $record) => PortalWorkflow::canAuthorizeTax(auth()->user()) && $record->doc_type === 'proforma' && in_array($record->status, ['Sent', 'Partially received', 'Received']) && (($record->tax_authorization['status'] ?? null) === 'Pending' || ($record->status === 'Received' && ! in_array($record->tax_authorization['status'] ?? null, ['Approved']))))
                    ->modalDescription(fn (Invoice $record) => $record->status === 'Received' ? 'Full payment is recorded. Approve so the tax invoice can be generated.' : 'Payment is not complete yet – you can only reject until the full payment is recorded.')
                    ->schema([Select::make('decision')->options(fn (Invoice $record) => $record->status === 'Received' ? ['approve' => 'Approve', 'reject' => 'Reject'] : ['reject' => 'Reject'])->required(), Textarea::make('note')->label('Note to assistant')->maxLength(500)])
                    ->action(fn (Invoice $record, array $data) => app(PortalWorkflow::class)->decideTax($record, auth()->user(), $data['decision'] === 'approve', $data['note'] ?? null)),

                // ---------- Assistant / Accountant: preparing ----------
                Action::make('request_tax')->label('Ask for tax invoice approval')->icon('heroicon-o-paper-airplane')
                    ->visible(fn (Invoice $record) => auth()->user()->allowed('accounting.tax.request') && ! PortalWorkflow::canAuthorizeTax(auth()->user()) && $record->doc_type === 'proforma' && in_array($record->status, ['Sent', 'Partially received', 'Received']) && ! in_array($record->tax_authorization['status'] ?? null, ['Pending', 'Approved']))
                    ->requiresConfirmation()->modalDescription('Director / Accountant will check the payment and approve.')
                    ->action(function (Invoice $record) {
                        app(PortalWorkflow::class)->requestTax($record, auth()->user());
                        Notification::make()->title('Approval requested')->success()->send();
                    }),
                Action::make('order')->label('Generate Tax Invoice')->icon('heroicon-o-document-check')->color('success')
                    ->visible(fn (Invoice $record) => auth()->user()->allowed('accounting.tax.issue') && $record->doc_type === 'proforma' && $record->status === 'Received' && (($record->tax_authorization['status'] ?? null) === 'Approved' || (PortalWorkflow::canAuthorizeTax(auth()->user()) && ! in_array($record->tax_authorization['status'] ?? null, ['Pending', 'Rejected']))))
                    ->schema([DatePicker::make('invoice_date')->label('Tax invoice date')->default(today())->required()])->requiresConfirmation()
                    ->action(fn ($record, array $data) => app(InvoiceService::class)->orderTax($record, $data['invoice_date'], auth()->user())),
                EditAction::make()->visible(fn (Model $record) => self::canEdit($record))->using(fn (Model $record, array $data) => app(OptimisticUpdate::class)->save($record, $data))->after(fn ($record) => app(InvoiceService::class)->recalculate($record)),
                Action::make('send')->label('Finalise proforma')->icon('heroicon-o-lock-closed')->requiresConfirmation()->visible(fn ($record) => auth()->user()->allowed('accounting.invoices.finalise') && $record->status === 'Draft')->action(fn ($record) => app(InvoiceService::class)->send($record)),
                Action::make('convert')->label('Convert currency')->icon('heroicon-o-arrows-right-left')->visible(fn ($record) => auth()->user()->allowed('accounting.invoices.finalise') && $record->status === 'Draft')->schema([Select::make('currency')->options(['USD' => 'USD', 'INR' => 'INR', 'EUR' => 'EUR'])->required(), TextInput::make('rate')->numeric()->minValue(0.00000001)->required(), DatePicker::make('rate_date')->default(today())->required(), Select::make('rate_source')->options(['RBI reference rate' => 'RBI reference rate', 'Bank rate' => 'Bank rate', 'Manual' => 'Manual'])->required()])->action(fn ($record, array $data) => app(InvoiceService::class)->convert($record, $data['currency'], (string) $data['rate'], $data['rate_date'], $data['rate_source'], auth()->user())),
                self::noteAction('credit'),
                self::noteAction('debit'),
                Action::make('register_details')->label('Register details')->icon('heroicon-o-pencil-square')->visible(fn () => auth()->user()->allowed('accounting.invoices.edit'))->fillForm(fn ($record) => $record->only(['lock_version', 'register_user', 'inr_exchange_rate', 'sender_name', 'register_comment', 'payment_intimation_date', 'payment_informed_by']))->schema([
                    Hidden::make('lock_version'),
                    TextInput::make('register_user')->label('User / Responsible person')->maxLength(255),
                    TextInput::make('inr_exchange_rate')->label('INR exchange rate on bill date')->numeric()->minValue(0.00000001)->helperText('INR for 1 unit of invoice currency (needed for GSTR-1 export value).'),
                    Textarea::make('register_comment')->label('Comment')->maxLength(4000),
                ])->action(fn ($record, array $data) => app(InvoiceRegisterService::class)->save($record, $data, auth()->user())),

                // ---------- Director / Accountant: payment status ----------
                Action::make('payment_history')->label('Payment history')->icon('heroicon-o-banknotes')->visible($pay)->modalHeading('Payments')->modalContent(fn ($record) => view('filament.parts.payment-history', ['invoice' => $record]))->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                Action::make('write_off')->label('Settle short payment')->icon('heroicon-o-scissors')->color('warning')
                    ->visible(fn ($record) => auth()->user()->allowed('payments.mark_received') && $record->doc_type === 'proforma' && $record->status === 'Partially received')
                    ->modalDescription(fn ($record) => 'Balance '.Money::format($record->outstandingAmount(), $record->currency).' will be written off and the proforma marked Received.')
                    ->schema([Textarea::make('reason')->label('Reason (e.g. bank charges deducted by remitter)')->required()->maxLength(500)])
                    ->action(fn ($record, array $data) => app(PaymentService::class)->writeOff($record, $data['reason'], auth()->user())),
                Action::make('revert')->label('Revert payment status')->icon('heroicon-o-arrow-uturn-left')->color('danger')->visible(fn ($record) => auth()->user()->allowed('payments.mark_received') && in_array($record->status, ['Received', 'Partially received']))->schema([Textarea::make('reason')->required()])->action(fn ($record, array $data) => app(PaymentService::class)->revert($record, $data['reason'], auth()->user())),
            ])->tooltip('Actions')->icon('heroicon-m-ellipsis-vertical')->iconButton()->color('gray'),
        ], position: RecordActionsPosition::BeforeColumns)->defaultSort('created_at', 'desc')->defaultPaginationPageOption(25)
            ->emptyStateHeading('No invoices here yet')->emptyStateIcon('heroicon-o-document-text');
    }

    public static function reminderText(Invoice $record): string
    {
        $record->loadMissing(['client', 'items', 'bankAccount']);
        $bank = $record->bankAccount;
        $days = (int) ($record->sent_at ?? $record->invoice_date)->copy()->startOfDay()->diffInDays(today());
        $matters = $record->items->pluck('our_ref')->filter()->unique()->implode(', ');
        $company = \App\Models\Setting::get('signature_company_name', 'AnalystIP');

        return "Subject: Payment reminder – {$record->number}".($matters ? " ({$matters})" : '')."\n\n"
            .'Dear '.($record->client->contact_person ?: 'Sir/Madam').",\n\n"
            ."This is a gentle reminder that our proforma invoice {$record->number} dated ".$record->invoice_date->format('d M Y')
            .' is still open ('.$days.' days). Balance due: '.Money::format($record->outstandingAmount(), $record->currency).".\n\n"
            .($bank ? "Bank details:\nBeneficiary: {$bank->beneficiary_name}\nBank: {$bank->bank_name}\nAccount / IBAN: {$bank->account_no}\nSWIFT: {$bank->swift}\n\n" : '')
            ."Please remit choosing OUR charges so that the full amount is received. If the payment has already been made, kindly share the remittance advice so we can match it.\n\n"
            ."Thank you,\n{$company} – Accounts";
    }

    public static function receiveAction(): Action
    {
        return Action::make('receive')->label('Record payment')->icon('heroicon-o-banknotes')->color('success')->button()->size('sm')
            ->visible(fn ($record) => auth()->user()->allowed('payments.mark_received') && $record->doc_type === 'proforma' && in_array($record->status, ['Sent', 'Partially received']))
            ->modalDescription(fn ($record) => 'Balance due: '.Money::format($record->outstandingAmount(), $record->currency))
            ->fillForm(fn ($record) => ['received_on' => today()->toDateString(), 'amount' => $record->outstandingAmount(), 'tds_amount' => '0', 'currency' => $record->currency, 'bank_account_id' => $record->bank_account_id, 'gateway_id' => $record->gateway_id, 'charges_deducted' => '0'])
            ->schema([
                DatePicker::make('received_on')->required(),
                TextInput::make('currency')->readOnly()->required(),
                TextInput::make('amount')->label('Amount received (gross)')->numeric()->minValue(0)->required()->helperText('Before bank charges. Enter less for a part payment.'),
                TextInput::make('tds_amount')->label('TDS deducted by client')->numeric()->minValue(0)->default(0)->helperText('Indian clients: TDS u/s 194J counts towards settling the invoice.'),
                Select::make('bank_account_id')->label('Bank')->options(fn ($record) => BankAccount::where('is_active', true)->where('currency', $record->currency)->get()->mapWithKeys(fn ($bank) => [$bank->id => $bank->bank_name.' '.$bank->currency]))->requiredWithout('gateway_id'),
                Select::make('gateway_id')->label('Payment gateway')->options(PaymentGateway::where('is_active', true)->pluck('name', 'id'))->requiredWithout('bank_account_id'),
                TextInput::make('txn_ref')->label('Transaction reference')->required()->maxLength(255),
                TextInput::make('charges_deducted')->label('Bank / gateway charges')->numeric()->minValue(0)->default(0),
                TextInput::make('sender_name')->maxLength(255),
                Textarea::make('payment_advice')->label('Payment advice / invoices covered')->maxLength(4000),
                Textarea::make('notes')->maxLength(4000),
            ])
            ->action(fn ($record, array $data) => app(PaymentService::class)->receive($record, $data, auth()->user()));
    }

    public static function noteAction(string $type): Action
    {
        $label = $type === 'credit' ? 'Credit note' : 'Debit note';

        return Action::make($type.'_note')->label('Raise '.$label)->icon($type === 'credit' ? 'heroicon-o-minus-circle' : 'heroicon-o-plus-circle')
            ->visible(fn (Invoice $record) => auth()->user()->allowed('accounting.notes.create') && $record->doc_type === 'tax' && $record->status === 'Issued')
            ->modalHeading(fn (Invoice $record) => $label.' against '.$record->number)
            ->modalDescription(fn (Invoice $record) => $record->client->client_type === 'Indian' ? 'GST @ '.\App\Models\Setting::get('gst_rate', '18').'% is added automatically on the taxable part (CGST+SGST or IGST by place of supply).' : 'Export invoice – no GST.')
            ->schema([
                DatePicker::make('note_date')->label('Date')->default(today())->required(),
                TextInput::make('taxable_amount')->label(fn (Invoice $record) => $record->client->client_type === 'Indian' ? 'Professional fees (taxable) – '.$record->currency : 'Professional fees – '.$record->currency)->numeric()->minValue(0)->default(0),
                TextInput::make('non_taxable_amount')->label(fn (Invoice $record) => 'Official fees / other (no GST) – '.$record->currency)->numeric()->minValue(0)->default(0),
                Textarea::make('reason')->required()->maxLength(2000)->placeholder($type === 'credit' ? 'e.g. Official fee reduced by the patent office' : 'e.g. Additional official fee charged'),
            ])
            ->action(function (Invoice $record, array $data) use ($type) {
                $note = app(NoteService::class)->create($record, $data + ['type' => $type], auth()->user());
                Notification::make()->title($note->label().' '.$note->number.' created')->success()->actions([
                    Action::make('open')->label('Open PDF')->url(url('/crm/notes/'.$note->id.'/pdf'), shouldOpenInNewTab: true),
                ])->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => InvoiceResource\Pages\ManageRecords::route('/')];
    }
}
