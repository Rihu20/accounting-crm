<?php

namespace App\Console\Commands;

use App\Models\BankAccount;
use App\Models\PaymentGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AddDemoPaymentOptions extends Command
{
    protected $signature = 'crm:demo-payment-options';

    protected $description = 'Add clearly labelled demo banks and gateways without modifying existing payment options';

    public function handle(): int
    {
        if (! app()->environment(['local', 'demo', 'testing'])) {
            $this->error('Demo payment options can only be added in local, demo or testing environments.');

            return self::FAILURE;
        }
        DB::transaction(function (): void {
            foreach (['Deutsche Bank' => 'DB', 'ICICI Bank' => 'ICICI'] as $name => $code) {
                foreach (['INR', 'USD', 'EUR'] as $currency) {
                    BankAccount::firstOrCreate(['bank_name' => $name.' (DEMO)', 'currency' => $currency], [
                        'beneficiary_name' => 'DEMO ONLY - AnalystIP Test Account',
                        'beneficiary_address' => 'Demo address - not for real payments',
                        'account_no' => 'DEMO-'.$code.'-'.$currency.'-001',
                        'swift' => 'DEMOONLY', 'ifsc' => 'DEMO0000000',
                        'bank_address' => 'Demo bank details - do not transfer money',
                        'is_active' => true, 'is_default' => false,
                    ]);
                }
            }
            foreach (['PayPal', 'Razorpay', 'Skydo'] as $name) {
                PaymentGateway::firstOrCreate(['name' => $name.' (DEMO)'], [
                    'instructions' => 'DEMO ONLY: '.$name.' test payment option. No live checkout or payment collection is connected. Do not send money.',
                    'is_active' => true,
                ]);
            }
        });
        $this->info('Demo options ready: Deutsche Bank and ICICI (INR, USD, EUR), PayPal, Razorpay and Skydo. Existing options were not changed.');

        return self::SUCCESS;
    }
}
