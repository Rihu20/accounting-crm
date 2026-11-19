<?php

namespace App\Filament\Resources\ExpenseResource;

use App\Models\Expense;
use App\Models\RemittanceBank;
use App\Models\RemittanceForm;
use App\Services\RemittanceFormService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/** The Form A2 actions (from a bill, new, edit, copy) and the saved-bank fields. */
class BankFormA2
{
    private const DESCRIPTION = 'Fills the bank\'s Form A2. Check every detail, then print, sign and hand it to the bank. Nothing is sent to the bank or the income-tax portal.';

    /** Form A2 for one vendor bill (Expense register and the bill list on the Bank Form A2 page). */
    public static function action(): Action
    {
        return self::modal(Action::make('bank_form_a2'))->label(fn (Expense $record) => 'Bank Form A2'.($record->a2_prepared_on ? ' (prepared '.$record->a2_prepared_on->format('d.m.Y').')' : ''))->icon('heroicon-o-building-library')->color('gray')
            ->visible(fn (Expense $record) => RemittanceFormService::canPrepare($record, auth()->user()))
            ->modalHeading(fn (Expense $record) => 'Bank Form A2 – '.($record->payee_name ?: $record->vendor?->name ?: 'vendor'))
            ->fillForm(fn (Expense $record) => app(RemittanceFormService::class)->defaults($record))
            ->schema(fn (Expense $record) => self::schema($record))
            ->action(fn (Expense $record, array $data) => self::download(app(RemittanceFormService::class)->prepare($record, $data, auth()->user())));
    }

    /** A new Form A2 without opening a bill: pick a saved party or type a new one, and any bills it pays. */
    public static function newAction(): Action
    {
        return self::modal(Action::make('new_form_a2'))->label('New Form A2')->icon('heroicon-o-document-plus')
            ->visible(fn () => RemittanceFormService::canPrepareAny(auth()->user()))
            ->modalHeading('New Form A2')
            ->fillForm(fn () => app(RemittanceFormService::class)->newFormDefaults())
            ->schema(fn () => self::schema(null))
            ->action(fn (array $data) => self::download(app(RemittanceFormService::class)->prepare(null, $data, auth()->user())));
    }

    /** Correct a prepared form that the bank has not yet acted on. */
    public static function editAction(): Action
    {
        return self::modal(Action::make('edit_form_a2'))->label('Edit')->icon('heroicon-o-pencil-square')
            ->visible(fn (RemittanceForm $record) => $record->isOpen() && RemittanceFormService::canPrepareAny(auth()->user()))
            ->modalHeading(fn (RemittanceForm $record) => 'Edit Form A2 – '.$record->beneficiary)
            ->fillForm(fn (RemittanceForm $record) => app(RemittanceFormService::class)->formData($record))
            ->schema(fn (RemittanceForm $record) => self::schema(null, $record))
            ->action(fn (RemittanceForm $record, array $data) => self::download(app(RemittanceFormService::class)->update($record, $data, auth()->user())));
    }

    /** A new form starting from an earlier one (same party, banks and purpose), dated today, without its bills. */
    public static function copyAction(): Action
    {
        return self::modal(Action::make('copy_form_a2'))->label('Copy as new')->icon('heroicon-o-document-duplicate')
            ->visible(fn () => RemittanceFormService::canPrepareAny(auth()->user()))
            ->modalHeading(fn (RemittanceForm $record) => 'New Form A2 from '.$record->beneficiary)
            ->fillForm(fn (RemittanceForm $record) => ['date' => today()->toDateString(), 'expense_ids' => [], 'exchange_rate' => null] + app(RemittanceFormService::class)->formData($record))
            ->schema(fn () => self::schema(null))
            ->action(fn (array $data) => self::download(app(RemittanceFormService::class)->prepare(null, $data, auth()->user())));
    }

    /** @return array<int, Field> */
    public static function bankFields(): array
    {
        return [
            TextInput::make('bank_name')->label('Bank name')->required()->maxLength(255),
            TextInput::make('branch')->label('Branch (AD branch)')->maxLength(255),
            TextInput::make('account_no')->label('Account no.')->required()->maxLength(64),
            Select::make('currency')->options(array_combine(config('crm.expense_currencies'), config('crm.expense_currencies')))->required()->default('INR'),
            Select::make('account_type')->label('Account type')->options(RemittanceBank::ACCOUNT_TYPES),
            Textarea::make('notes')->rows(2)->maxLength(1000),
        ];
    }

    private static function modal(Action $action): Action
    {
        return $action->modalDescription(self::DESCRIPTION)->modalSubmitActionLabel('Download Form A2 (Word)')->modalWidth('4xl');
    }

    /** @return array<int, Section> */
    private static function schema(?Expense $record, ?RemittanceForm $editing = null): array
    {
        $currency = fn (Get $get) => $record?->currency ?? $get('currency');
        $free = $record === null;

        return [
            Section::make('Remittance')->description('Our banks are saved on the Bank Form A2 page. The remittance and the charges can go from different banks.')->columns(2)->schema([
                DatePicker::make('date')->required(),
                ...($free ? [Select::make('currency')->options(RemittanceFormService::currencies())->required()->live()] : []),
                ...($free ? [Select::make('expense_ids')->label('Vendor bills paid by this remittance (optional)')->multiple()->searchable()->live()->columnSpanFull()
                    ->options(fn (Get $get) => RemittanceFormService::billOptions($get('currency'), $editing?->id, (array) $get('expense_ids')))
                    ->helperText('Choose one or more unpaid bills of the party: the amount and additional information are filled from them, and the bills are marked paid when the bank remits.')
                    ->afterStateUpdated(function (?array $state, Set $set, Get $get): void {
                        if (! $state) {
                            return;
                        }
                        $summary = RemittanceFormService::billsSummary($state);
                        $set('amount', $summary['amount']);
                        $set('info', $summary['info']);
                        if (blank($get('beneficiary_name')) && $summary['beneficiary']) {
                            self::fillParty($summary['beneficiary'], $set);
                        }
                    })] : []),
                TextInput::make('amount')->label(fn (Get $get) => 'Amount ('.($currency($get) ?? '').')')->numeric()->minValue(0.01)->required(),
                TextInput::make('exchange_rate')->label('Exchange rate (INR per unit, optional)')->numeric()->minValue(0.0001)->helperText('Fills "Equivalent to Rupees" on the form.'),
                self::bankSelect('debit_bank_id', 'Bank account debited for the remittance (RFC / EEFC)', $currency, $record, $editing),
                self::bankSelect('charges_bank_id', 'Bank account debited for charges', fn () => 'INR', $record, $editing),
            ]),
            Section::make('Beneficiary')->description('From the vendor master or this party\'s last form. Whatever you fill here is saved to the vendor for next time.')->columns(2)->schema([
                ...($free ? [Select::make('party')->label('Saved party')->options(fn () => RemittanceFormService::partyOptions())->searchable()->live()->columnSpanFull()
                    ->helperText('Pick a party paid before to fill its details, or leave blank and type a new beneficiary below.')
                    ->afterStateUpdated(fn (?string $state, Set $set) => $state ? self::fillParty($state, $set) : null)] : []),
                TextInput::make('beneficiary_name')->label('Beneficiary name')->required(),
                TextInput::make('country')->label('Country providing the services')->helperText('UAE, European countries and the UK need an IBAN.'),
                Textarea::make('address')->label('Beneficiary address')->rows(2),
                TextInput::make('bank_account')->label('Account no. / IBAN'),
                TextInput::make('bank_name')->label('Beneficiary bank name'),
                TextInput::make('swift')->label('SWIFT / ABA / Sort code / Routing / BLZ'),
                Textarea::make('bank_address')->label('Beneficiary bank address')->rows(2),
                TextInput::make('corr_bank_name')->label('Correspondent bank name'),
                TextInput::make('corr_bank_swift')->label('Correspondent bank SWIFT'),
                Textarea::make('corr_bank_address')->label('Correspondent bank address')->rows(2),
            ]),
            Section::make('Purpose')->columns(2)->schema([
                Textarea::make('purpose')->label('Purpose of remittance')->rows(2),
                Select::make('charges')->label('Correspondent bank charges')->options(['BEN' => 'BEN', 'OUR' => 'OUR', 'SHA' => 'SHA'])->required(),
                TextInput::make('purpose_code')->label('Purpose code')->datalist(array_keys(RemittanceFormService::PURPOSE_CODES))->live(onBlur: true)
                    ->helperText(collect(RemittanceFormService::PURPOSE_CODES)->map(fn ($d, $c) => $c.' '.$d)->implode(' · '))
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $code = strtoupper(trim((string) $state));
                        if (isset(RemittanceFormService::PURPOSE_CODES[$code])) {
                            $set('purpose_code', $code);
                            $set('purpose_desc', RemittanceFormService::PURPOSE_CODES[$code]);
                        }
                    }),
                TextInput::make('purpose_desc')->label('Purpose code description'),
                TextInput::make('source_funds')->label('Source of funds'),
                Textarea::make('info')->label('Additional information')->rows(2),
            ]),
        ];
    }

    private static function fillParty(string $name, Set $set): void
    {
        foreach (app(RemittanceFormService::class)->partyDetails($name) as $field => $value) {
            $set($field, $value);
        }
    }

    /** @param  array{name: string, content: string, missing: array<int, string>}  $form */
    private static function download(array $form)
    {
        $note = Notification::make()->title('Form A2 ready');
        $form['missing'] ? $note->warning()->body('Still blank: '.implode(', ', $form['missing']).'. Fill these in before signing.')->persistent()->send() : $note->success()->body('Check it, sign it and hand it to the bank. It is listed in the Remittance register.')->send();

        return response()->streamDownload(fn () => print ($form['content']), $form['name'], ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);
    }

    private static function bankSelect(string $name, string $label, Closure $currency, ?Expense $record, ?RemittanceForm $editing): Select
    {
        return Select::make($name)->label($label)
            ->options(fn (Get $get) => RemittanceFormService::bankOptions($currency($get), [$record?->a2_debit_bank_id, $record?->a2_charges_bank_id, $editing?->debit_bank_id, $editing?->charges_bank_id]))
            ->searchable()
            ->createOptionForm(self::bankFields())
            ->createOptionModalHeading('Add our bank account')
            ->createOptionUsing(fn (array $data): int => RemittanceBank::create($data + ['is_active' => true])->getKey());
    }
}
