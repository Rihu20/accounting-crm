<?php

namespace Database\Seeders;

use App\Models\BankAccount;
use App\Models\PaymentGateway;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([PlatformSeeder::class, PatentOfficeSeeder::class]);
        $settings = ['company_name' => 'ANALYSTIP LEGAL SERVICES PVT LTD', 'company_address' => 'Plot No C-116, Sector 2, Noida, Uttar Pradesh, 201301, India', 'company_state' => 'Uttar Pradesh', 'company_gstin' => '09ABBCA7682H1ZD', 'company_email' => 'accounting@analystip.com', 'company_mobile' => '+91 9117831863', 'company_phone' => '+120-4374415', 'terms' => "1. Remitter pays all foreign bank charges by choosing OUR so we receive the full amount.\n2. This invoice reflects the actual invoice amount as mutually agreed service fees.", 'gst_rate' => '18', 'tax_prefix' => 'AIP', 'proforma_prefix' => 'PI', 'sequence_padding' => '4', 'year_logic' => 'financial', 'lut_arn' => '', 'lut_valid_upto' => '', 'credit_note_prefix' => 'CN', 'debit_note_prefix' => 'DN', 'followup_days' => '15', 'followup_repeat_days' => '7'];
        foreach ($settings as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        foreach (['Deutsche Bank' => ['USD', 'INR', 'EUR'], 'ICICI Bank' => ['INR', 'USD']] as $bank => $currencies) {
            foreach ($currencies as $cur) {
                BankAccount::firstOrCreate(['bank_name' => $bank, 'currency' => $cur], ['is_default' => $bank === 'Deutsche Bank', 'is_active' => true]);
            }
        }
        foreach (['PayPal', 'Skydo', 'Razorpay'] as $g) {
            PaymentGateway::firstOrCreate(['name' => $g]);
        }
    }
}
