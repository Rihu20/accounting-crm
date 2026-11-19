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

/** The Form A2 action and the saved-bank fields, shared by the Expense register and the Bank Form A2 page. */
class BankFormA2
{
    public static function action(): Action
    {
        return Action::make('bank_form_a2')->label(fn (Expense $record) => 'Bank Form A2'.($record->a2_prepared_on ? ' (prepared '.$record->a2_prepared_on->format('d.m.Y').')' : ''))->icon('heroicon-o-building-library')->color('gray')
            ->visible(fn (Expense $record) => RemittanceFormService::canPrepare($record, auth()->user()))
            ->modalHeading(fn (Expense $record) => 'Bank Form A2 – '.($record->payee_name ?: $record->vendor?->name ?: 'vendor'))
            ->modalDescription('Fills the bank\'s Form A2 from this bill. Check every detail, then print, sign and hand it to the bank. Nothing is sent to the bank or the income-tax portal.')
            ->modalSubmitActionLabel('Download Form A2 (Word)')->modalWidth('4xl')
            ->fillForm(fn (Expense $record) => app(RemittanceFormService::class)->defaults($record))
            ->schema(fn (Expense $record) => [
                Section::make('Remittance')->description('Our banks are saved on the Bank Form A2 page. The remittance and the charges can go from different banks.')->columns(2)->schema([
                    DatePicker::make('date')->required(),
                    TextInput::make('amount')->label('Amount ('.$record->currency.')')->numeric()->minValue(0.01)->required(),
                    self::bankSelect('debit_bank_id', 'Bank account debited for the remittance (RFC / EEFC)', $record->currency, $record),
                    self::bankSelect('charges_bank_id', 'Bank account debited for charges', 'INR', $record),
                ]),
                Section::make('Beneficiary')->description('From the vendor master or this party\'s last form. Whatever you fill here is saved to the vendor for next time.')->columns(2)->schema([
                    TextInput::make('beneficiary_name')->label('Beneficiary name')->required(),
                    TextInput::make('country')->label('Country providing the services'),
                    Textarea::make('address')->label('Beneficiary address')->rows(2),
                    TextInput::make('bank_account')->label('Account no. / IBAN'),
                    TextInput::make('bank_name')->label('Beneficiary bank name'),
                    TextInput::make('swift')->label('SWIFT / ABA / Sort code / Routing / BLZ'),
                    Textarea::make('bank_address')->label('Beneficiary bank address')->rows(2),
                    TextInput::make('corr_bank_name')->label('Correspondent bank name'),
                    TextInput::make('corr_bank_swift')->label('Correspondent bank SWIFT'),
                    Textarea::make('corr_bank_address')->label('Correspondent bank address')->rows(2),                ]),
                Section::make('Purpose')->columns(2)->schema([
                    Textarea::make('purpose')->label('Purpose of remittance')->rows(2),
                    Select::make('charges')->label('Correspondent bank charges')->options(['BEN' => 'BEN', 'OUR' => 'OUR', 'SHA' => 'SHA'])->required(),
                    TextInput::make('purpose_code')->label('Purpose code'),
                    TextInput::make('purpose_desc')->label('Purpose code description'),
                    TextInput::make('source_funds')->label('Source of funds'),
                    Textarea::make('info')->label('Additional information')->rows(2),
                ]),
            ])
            ->action(function (Expense $record, array $data) {
                $form = app(RemittanceFormService::class)->prepare($record, $data, auth()->user());
                $note = Notification::make()->title('Form A2 ready');
                $form['missing'] ? $note->warning()->body('Still blank: '.implode(', ', $form['missing']).'. Fill these in before signing.')->persistent()->send() : $note->success()->body('Check it, sign it and hand it to the bank.')->send();

                return response()->streamDownload(fn () => print ($form['content']), $form['name'], ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);
            });
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

    private static function bankSelect(string $name, string $label, string $currency, Expense $record): Select
    {
        return Select::make($name)->label($label)
            ->options(fn () => RemittanceFormService::bankOptions($currency, [$record->a2_debit_bank_id, $record->a2_charges_bank_id]))
            ->searchable()
            ->createOptionForm(self::bankFields())
            ->createOptionModalHeading('Add our bank account')
            ->createOptionUsing(fn (array $data): int => RemittanceBank::create($data + ['is_active' => true])->getKey());
    }
}
