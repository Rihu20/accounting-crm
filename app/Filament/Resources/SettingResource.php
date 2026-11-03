<?php

namespace App\Filament\Resources;

use App\Models\Setting;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SettingResource extends CrmResource
{
    protected static ?string $model = Setting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Settings';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('key')->required()->disabled()->dehydrated(), Textarea::make('value')->rows(4)->columnSpanFull()])->columns(2);
    }

    public static function table(Table $table): Table
    {
        $labels = [
            'company_name' => 'Company name (on invoices)', 'company_address' => 'Company address', 'company_state' => 'Company state', 'company_gstin' => 'Company GSTIN',
            'company_email' => 'Accounts email', 'company_mobile' => 'Mobile', 'company_phone' => 'Landline', 'terms' => 'Terms & conditions (invoice footer)',
            'gst_rate' => 'GST rate % on professional fees', 'tax_prefix' => 'Tax invoice prefix', 'proforma_prefix' => 'Proforma prefix', 'credit_note_prefix' => 'Credit note prefix', 'debit_note_prefix' => 'Debit note prefix',
            'sequence_padding' => 'Number digits', 'year_logic' => 'Numbering year (financial / calendar)', 'followup_days' => 'First payment follow-up after (days)', 'followup_repeat_days' => 'Repeat follow-up every (days)',
            'lut_arn' => 'LUT ARN (printed on export invoices)', 'lut_valid_upto' => 'LUT valid up to', 'signature_company_name' => 'Name above signature', 'logo_path' => 'Logo file', 'signature_path' => 'Signature file',
        ];

        return $table->columns([TextColumn::make('label')->label('Setting')->state(fn ($record) => $labels[$record->key] ?? $record->key)->description(fn ($record) => $record->key)->weight('semibold'), TextColumn::make('value')->searchable()->limit(70)->wrap()->placeholder('Not set')])->recordActions([ViewAction::make(), EditAction::make()])->defaultPaginationPageOption(10);
    }

    public static function getPages(): array
    {
        return ['index' => SettingResource\Pages\ManageRecords::route('/')];
    }
}
