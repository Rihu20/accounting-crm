<?php

namespace App\Filament\Resources\ExpenseResource;

use App\Models\Expense;
use App\Models\RemittanceBank;
use App\Services\RemittanceFormService;
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

/** The Form A2 actions (from a bill, or new from the Bank Form A2 page) and the saved-bank fields. */
class BankFormA2
{
    private const DESCRIPTION = 'Fills the bank\'s Form A2. Check every detail, then print, sign and hand it to the bank. Nothing is sent to the bank or the income-tax portal.';

    /** Form A2 for one vendor bill (Expense register and the bill list on the Bank Form A2 page). */
    public static function action(): Action
    {
        return Action::make('bank_form_a2')->label(fn (Expense $record) => 'Bank Form A2'.($record->a2_prepared_on ? ' (prepared '.$record->a2_prepared_on->format('d.m.Y').')' : ''))->icon('heroicon-o-building-library')->color('gray')
            ->visible(fn (Expense $record) => RemittanceFormService::canPrepare($record, auth()->user()))
            ->modalHeading(fn (Expense $record) => 'Bank Form A2 – '.($record->payee_name ?: $record->vendor?->name ?: 'vendor'))
            ->modalDescription(self::DESCRIPTION)
            ->modalSubmitActionLabel('Download Form A2 (Word)')->modalWidth('4xl')
            ->fillForm(fn (Expense $record) => app(RemittanceFormService::class)->defaults($record))
            ->schema(fn (Expense $record) => self::schema($record))
            ->action(fn (Expense $record, array $data) => self::download($record, $data));
    }

    /** A new Form A2 without a bill: pick a saved party or type a new one. */
    public static function newAction(): Action
    {
        return Action::make('new_form_a2')->label('New Form A2')->icon('heroicon-o-document-plus')
            ->visible(fn () => RemittanceFormService::canPrepareAny(auth()->user()))
            ->modalHeading('New Form A2')
            ->modalDescription(self::DESCRIPTION.' No vendor bill is needed.')
            ->modalSubmitActionLabel('Download Form A2 (Word)')->modalWidth('4xl')
            ->fillForm(fn () => app(RemittanceFormService::class)->newFormDefaults())
            ->schema(fn () => self::schema(null))
            ->action(fn (array $data) => self::download(null, $data));
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

    /** @return array<int, Section> */
    private static function schema(?Expense $record): array
    {
        $party = $record ? [] : [
            Select::make('party')->label('Saved party')->options(fn () => RemittanceFormService::partyOptions())->searchable()->live()->columnSpanFull()
                ->helperText('Pick a party paid before to fill its details, or leave blank and type a new beneficiary below.')
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    if (! $state) {
                        return;
                    }
                    foreach (app(RemittanceFormService::class)->partyDetails($state) as $field => $value) {
                        $set($field, $value);
                    }
                }),
        ];

        return [
            Section::make('Remittance')->description('Our banks are saved on the Bank Form A2 page. The remittance and the charges can go from different banks.')->columns(2)->schema([
                DatePicker::make('date')->required(),
                ...($record ? [] : [Select::make('currency')->options(RemittanceFormService::currencies())->required()->live()]),
                TextInput::make('amount')->label(fn (Get $get) => 'Amount ('.($record?->currency ?? $get('currency') ?? '').')')->numeric()->minValue(0.01)->required(),
                self::bankSelect('debit_bank_id', 'Bank account debited for the remittance (RFC / EEFC)', fn (Get $get) => $record?->currency ?? $get('currency'), $record),
                self::bankSelect('charges_bank_id', 'Bank account debited for charges', fn () => 'INR', $record),
            ]),
            Section::make('Beneficiary')->description('From the vendor master or this party\'s last form. Whatever you fill here is saved to the vendor for next time.')->columns(2)->schema([
                ...$party,
                TextInput::make('beneficiary_name')->label('Beneficiary name')->required(),
                TextInput::make('country')->label('Country providing the services'),
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
                TextInput::make('purpose_code')->label('Purpose code'),
                TextInput::make('purpose_desc')->label('Purpose code description'),
                TextInput::make('source_funds')->label('Source of funds'),
                Textarea::make('info')->label('Additional information')->rows(2),
            ]),
        ];
    }

    /** @param  array<string, mixed>  $data */
    private static function download(?Expense $record, array $data)
    {
        $form = app(RemittanceFormService::class)->prepare($record, $data, auth()->user());
        $note = Notification::make()->title('Form A2 ready');
        $form['missing'] ? $note->warning()->body('Still blank: '.implode(', ', $form['missing']).'. Fill these in before signing.')->persistent()->send() : $note->success()->body('Check it, sign it and hand it to the bank. It is listed under Forms prepared.')->send();

        return response()->streamDownload(fn () => print ($form['content']), $form['name'], ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);
    }

    private static function bankSelect(string $name, string $label, \Closure $currency, ?Expense $record): Select
    {
        return Select::make($name)->label($label)
            ->options(fn (Get $get) => RemittanceFormService::bankOptions($currency($get), [$record?->a2_debit_bank_id, $record?->a2_charges_bank_id]))
            ->searchable()
            ->createOptionForm(self::bankFields())
            ->createOptionModalHeading('Add our bank account')
            ->createOptionUsing(fn (array $data): int => RemittanceBank::create($data + ['is_active' => true])->getKey());
    }
}
