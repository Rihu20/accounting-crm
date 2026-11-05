<?php

namespace App\Filament\Resources\InvoiceResource;

use App\Filament\Resources\InvoiceResource;
use App\Models\BankAccount;
use App\Models\Client;
use App\Models\Paralegal;
use App\Models\PaymentGateway;
use App\Models\Setting;
use App\Services\Gst;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class ProformaForm
{
    public static function fillClient(?Client $client, Set $set): void
    {
        if (! $client) {
            return;
        }
        $set('client_id', $client->id);
        $set('client_ref', $client->ref_no);
        foreach (['name', 'contact_person', 'address', 'email_1', 'phone', 'country', 'client_type', 'state', 'state_code', 'gstin'] as $field) {
            $set($field, $client->{$field});
        }
        $set('currency', $client->client_type === 'Indian' ? 'INR' : $client->default_currency);
        $set('gst_enabled', $client->client_type === 'Indian');
    }

    public static function components(): array
    {
        return [
            Section::make('1. Client details')->description('No reference or existing matter is needed. Choose a saved client to fill details, or enter a new client below.')->schema([
                Select::make('client_id')->label('Saved client (optional)')->options(fn () => Client::orderBy('name')->get()->mapWithKeys(fn ($client) => [$client->id => ($client->ref_no ? $client->ref_no.' - ' : '').$client->name]))->searchable()->live()->afterStateUpdated(fn ($state, Set $set) => self::fillClient(Client::find($state), $set))->columnSpanFull(),
                TextInput::make('client_ref')->label('Client Ref No (optional)')->regex('/^\d{4}$/')->maxLength(4)->live(onBlur: true)->afterStateUpdated(function ($state, Set $set): void {
                    if (filled($state)) {
                        self::fillClient(Client::where('ref_no', $state)->first(), $set);
                    }
                }),
                TextInput::make('name')->label('Client name')->required()->maxLength(255),
                TextInput::make('contact_person')->label('Attn name')->maxLength(255),
                TextInput::make('email_1')->label('Email')->email()->maxLength(255),
                Textarea::make('address')->rows(2)->maxLength(4000)->columnSpanFull(),
                TextInput::make('phone')->label('Telephone')->maxLength(100),
                TextInput::make('country')->required()->maxLength(255),
                Select::make('client_type')->label('Client type')->options(['Foreign' => 'Foreign', 'Indian' => 'Indian'])->default('Foreign')->required()->live()->afterStateUpdated(function ($state, Set $set): void {
                    $set('gst_enabled', $state === 'Indian');
                    $set('currency', $state === 'Indian' ? 'INR' : 'USD');
                    if ($state === 'Indian') {
                        $set('country', 'India');
                    }
                }),
                Select::make('paralegal_id')->label('Assigned paralegal (optional)')->options(fn () => Paralegal::orderBy('name')->pluck('name', 'id'))->searchable(),
            ])->columns(2),
            Section::make('2. Invoice information')->schema([
                Select::make('invoice_layout')->label('Fee presentation')->options(['combined' => 'Combined Official + Professional', 'detailed' => 'Separate Official, Professional and Translation'])->default('detailed')->required()->live()->helperText('For Indian clients, enter Official and Professional amounts separately for GST even if the PDF displays a combined amount.'),
                Select::make('currency')->options(['USD' => 'USD', 'INR' => 'INR', 'EUR' => 'EUR'])->default('USD')->required()->live()->afterStateUpdated(fn ($state, Set $set) => $set('bank_account_id', null)),
                DatePicker::make('invoice_date')->default(today())->required(),
                DatePicker::make('due_date')->default(today()->addDays(15))->required()->afterOrEqual('invoice_date'),
            ])->columns(2),
            Section::make('3. Matters and fees')->description('Only the work description and a fee amount are needed. Leave unavailable identifiers blank. SAC 998213 is applied automatically.')->schema([
                Repeater::make('items')->label('Matters')->minItems(1)->maxItems(100)->defaultItems(1)->addActionLabel('Add another matter')->itemLabel(fn (array $state): string => $state['work'] ?? 'New matter')->schema([
                    TextInput::make('work')->label('Work / service')->datalist(['National phase patent filing', 'Request for examination', 'Response to examination report', 'Patent renewal', 'Translation'])->required()->maxLength(2000)->columnSpanFull(),
                    TextInput::make('applicant_name')->label('Applicant name')->maxLength(255),
                    TextInput::make('country')->label('Filing country')->maxLength(255),
                    TextInput::make('app_no')->label('Application No')->maxLength(255),
                    TextInput::make('pct_no')->label('PCT No')->maxLength(255),
                    TextInput::make('your_ref')->label('Your Ref (optional)')->maxLength(255),
                    TextInput::make('our_ref')->label('Our Ref (optional)')->maxLength(255),
                    TextInput::make('combined_amount')->label('Combined fee')->numeric()->minValue(0)->maxValue(999999999999.99)->visible(fn (Get $get): bool => $get('../../invoice_layout') === 'combined' && $get('../../client_type') !== 'Indian'),
                    TextInput::make('official_amount')->label('Official Fees')->numeric()->minValue(0)->maxValue(999999999999.99)->visible(fn (Get $get): bool => $get('../../invoice_layout') !== 'combined' || $get('../../client_type') === 'Indian'),
                    TextInput::make('professional_amount')->label('Professional Fees')->numeric()->minValue(0)->maxValue(999999999999.99)->visible(fn (Get $get): bool => $get('../../invoice_layout') !== 'combined' || $get('../../client_type') === 'Indian'),
                    TextInput::make('translation_amount')->label('Translation Fees (optional)')->numeric()->minValue(0)->maxValue(999999999999.99)->visible(fn (Get $get): bool => $get('../../invoice_layout') !== 'combined'),
                    TextInput::make('language')->label('Translation language')->maxLength(100)->visible(fn (Get $get): bool => $get('../../invoice_layout') !== 'combined'),
                ])->columns(2),
            ]),
            Section::make('4. GST and other charges')->schema([
                Toggle::make('gst_enabled')->label('Apply GST to Professional Fees only')->default(false)->live()->visible(fn (Get $get): bool => $get('client_type') === 'Indian'),
                TextInput::make('gst_rate')->label('GST rate (%)')->numeric()->minValue(0)->maxValue(100)->default(fn () => Setting::get('gst_rate', '18'))->required(fn (Get $get): bool => $get('client_type') === 'Indian' && $get('gst_enabled'))->visible(fn (Get $get): bool => $get('client_type') === 'Indian' && $get('gst_enabled')),
                Select::make('state')->label('Indian billing state')->options(array_combine(array_keys(Gst::STATE_CODES), array_keys(Gst::STATE_CODES)))->searchable()->required(fn (Get $get): bool => $get('client_type') === 'Indian' && $get('gst_enabled'))->visible(fn (Get $get): bool => $get('client_type') === 'Indian'),
                TextInput::make('gstin')->label('Client GSTIN (optional)')->maxLength(15)->visible(fn (Get $get): bool => $get('client_type') === 'Indian'),
                InvoiceResource::additionalChargesField(),
            ])->columns(2),
            Section::make('5. Payment instructions')->schema([
                Select::make('payment_method')->label('Pay through')->options(['bank' => 'Bank account', 'gateway' => 'Payment gateway'])->default('bank')->required()->live(),
                Select::make('bank_account_id')->label('Bank account')->options(fn (Get $get) => BankAccount::where('is_active', true)->where('currency', $get('currency'))->get()->mapWithKeys(fn ($bank) => [$bank->id => $bank->bank_name.' - '.$bank->currency]))->searchable()->required(fn (Get $get): bool => $get('payment_method') === 'bank')->visible(fn (Get $get): bool => $get('payment_method') === 'bank'),
                Select::make('gateway_id')->label('Payment gateway')->options(fn () => PaymentGateway::where('is_active', true)->pluck('name', 'id'))->required(fn (Get $get): bool => $get('payment_method') === 'gateway')->visible(fn (Get $get): bool => $get('payment_method') === 'gateway'),
            ])->columns(2),
        ];
    }
}
