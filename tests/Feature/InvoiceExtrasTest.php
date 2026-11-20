<?php

use App\Filament\Pages\DataChecks;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Pages\ManageRecords as InvoicePage;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\Paralegal;
use App\Models\Setting;
use App\Models\User;
use App\Services\EInvoiceService;
use App\Services\InvoiceService;
use App\Services\MasterDataChecks;
use App\Services\PaymentService;
use App\Services\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    Storage::fake('local');
    $role = function (string $name): User {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($name);

        return $user;
    };
    $this->accountant = $role('Accountant');
    $this->paralegal = $role('Paralegal');
    $this->actingAs($this->accountant);
    $para = Paralegal::create(['name' => 'Seema']);
    $this->foreign = Client::create(['ref_no' => '0901', 'name' => 'Acme GmbH', 'client_type' => 'Foreign', 'country' => 'Germany', 'default_currency' => 'USD', 'address' => 'Hauptstrasse 1, 10115 Berlin', 'email_1' => 'ip@acme.de', 'contact_person' => 'Dr Weber']);
    $this->indian = Client::create(['ref_no' => '0902', 'name' => 'Desi Pvt Ltd', 'client_type' => 'Indian', 'country' => 'India', 'state' => 'Maharashtra', 'gstin' => '27AABCU9603R1ZM', 'default_currency' => 'INR', 'address' => 'FC Road, Pune 411004']);
    Matter::create(['client_id' => $this->foreign->id, 'paralegal_id' => $para->id, 'ref_no' => '0901', 'our_ref' => 'P26090101DE', 'matter_title' => 'Filing', 'country' => 'Germany', 'service_type' => 'Professional Fees']);
    Matter::create(['client_id' => $this->indian->id, 'paralegal_id' => $para->id, 'ref_no' => '0902', 'our_ref' => 'P26090201IN', 'matter_title' => 'Design', 'country' => 'India', 'service_type' => 'Professional Fees']);
    $this->invoices = app(InvoiceService::class);
    $this->proforma = fn (Client $client, string $currency, array $rows) => $this->invoices->send($this->invoices->draft($this->accountant, $client->id, $currency, $rows, null, $client->client_type === 'Indian' ? 'india_gst' : null));
    $this->taxFrom = function (Invoice $proforma): Invoice {
        app(PaymentService::class)->receive($proforma, ['received_on' => today()->toDateString(), 'amount' => (string) $proforma->grand_total, 'currency' => $proforma->currency, 'bank_account_id' => $proforma->bank_account_id, 'txn_ref' => 'T-'.$proforma->id], $this->accountant);

        return $this->invoices->orderTax($proforma->fresh(), today()->toDateString(), $this->accountant);
    };
});

it('builds the e-invoice JSON for an export in rupees and asks for the rate first', function () {
    $tax = ($this->taxFrom)(($this->proforma)($this->foreign, 'USD', [['matter_ref' => 'P26090101DE', 'fee_type' => 'Official Fees', 'rate' => '400'], ['matter_ref' => 'P26090101DE', 'fee_type' => 'Professional Fees', 'rate' => '600']]));
    $service = app(EInvoiceService::class);
    expect(fn () => $service->payload($tax))->toThrow(ValidationException::class, 'INR exchange rate');

    $tax->update(['inr_exchange_rate' => '83.50']);
    $p = $service->payload($tax->fresh());
    expect($p['TranDtls']['SupTyp'])->toBe('EXPWOP')
        ->and($p['DocDtls'])->toBe(['Typ' => 'INV', 'No' => $tax->number, 'Dt' => today()->format('d/m/Y')])
        ->and($p['SellerDtls'])->toMatchArray(['Gstin' => '09ABBCA7682H1ZD', 'Pin' => 201301, 'Stcd' => '09'])
        ->and($p['BuyerDtls'])->toMatchArray(['Gstin' => 'URP', 'Pos' => '96', 'Pin' => 999999, 'Stcd' => '96'])
        ->and($p['ItemList'])->toHaveCount(2)
        ->and($p['ItemList'][1])->toMatchArray(['HsnCd' => '998213', 'IsServc' => 'Y', 'AssAmt' => 50100.0, 'GstRt' => 0.0, 'IgstAmt' => 0.0])
        ->and($p['ValDtls'])->toMatchArray(['AssVal' => 83500.0, 'IgstVal' => 0.0, 'TotInvVal' => 83500.0, 'TotInvValFc' => 1000.0])
        ->and($p['ExpDtls'])->toBe(['ForCur' => 'USD', 'CntCode' => 'DE'])
        ->and(json_decode($service->json($tax->fresh()), true))->toHaveCount(1);
});

it('builds a B2B e-invoice with IGST that adds up to the invoice total', function () {
    $tax = ($this->taxFrom)(($this->proforma)($this->indian, 'INR', [['matter_ref' => 'P26090201IN', 'fee_type' => 'Official Fees', 'rate' => '1000'], ['matter_ref' => 'P26090201IN', 'fee_type' => 'Professional Fees', 'rate' => '5000']]));
    $p = app(EInvoiceService::class)->payload($tax);
    $v = $p['ValDtls'];
    expect($p['TranDtls']['SupTyp'])->toBe('B2B')
        ->and($p['BuyerDtls'])->toMatchArray(['Gstin' => '27AABCU9603R1ZM', 'Pos' => '27', 'Pin' => 411004, 'Stcd' => '27'])
        ->and($v['IgstVal'])->toBe(900.0)->and($v['CgstVal'])->toBe(0.0)
        ->and(round($v['AssVal'] + $v['IgstVal'] + $v['CgstVal'] + $v['SgstVal'] + $v['OthChrg'] + $v['RndOffAmt'], 2))->toBe($v['TotInvVal'])
        ->and($v['TotInvVal'])->toBe((float) $tax->grand_total);

    $this->indian->update(['gstin' => null]);
    expect(fn () => app(EInvoiceService::class)->payload($tax->fresh()))->toThrow(ValidationException::class, 'registered buyers');
});

it('records the IRN from the portal response and prints it with a QR on the tax invoice', function () {
    $tax = ($this->taxFrom)(($this->proforma)($this->indian, 'INR', [['matter_ref' => 'P26090201IN', 'fee_type' => 'Professional Fees', 'rate' => '5000']]));
    $service = app(EInvoiceService::class);
    expect(fn () => $service->record($tax, ['irn' => 'abc', 'ack_no' => '1', 'ack_date' => now()], $this->accountant))->toThrow(ValidationException::class, '64-character');

    $irn = str_repeat('a1b2c3d4', 8);
    $service->record($tax, ['response' => json_encode(['Irn' => $irn, 'AckNo' => 112610012345678, 'AckDt' => '2026-11-20 10:15:00', 'SignedQRCode' => 'eyJhbGciOiJSUzI1NiJ9.sample.signature'])], $this->accountant);
    $tax = $tax->fresh();
    expect($tax)->irn->toBe($irn)->irn_ack_no->toBe('112610012345678')
        ->and($tax->irn_ack_date->format('Y-m-d H:i'))->toBe('2026-11-20 10:15')
        ->and($service->qrImage($tax))->toStartWith('data:image/png;base64,');

    $html = view('pdf.invoice', ['invoice' => $tax, 'pages' => app(PdfService::class)->pages($tax), 'settings' => Setting::pluck('value', 'key')->all()])->render();
    expect($html)->toContain('e-Invoice (GST IRP)', $irn, '112610012345678');

    $other = ($this->taxFrom)(($this->proforma)($this->indian, 'INR', [['matter_ref' => 'P26090201IN', 'fee_type' => 'Professional Fees', 'rate' => '100']]));
    expect(fn () => $service->record($other, ['irn' => $irn, 'ack_no' => '2', 'ack_date' => now()], $this->accountant))->toThrow(ValidationException::class, 'already recorded');
    expect(fn () => $service->record($other, ['irn' => str_repeat('f', 64), 'ack_no' => '2', 'ack_date' => now()], $this->paralegal))->toThrow(HttpException::class);
});

it('cancels a sent proforma without payments and keeps its number', function () {
    $sent = ($this->proforma)($this->foreign, 'USD', [['matter_ref' => 'P26090101DE', 'fee_type' => 'Professional Fees', 'rate' => '300']]);
    expect(fn () => $this->invoices->cancel($sent, '', $this->accountant))->toThrow(HttpException::class);
    expect(fn () => $this->invoices->cancel($sent, 'Wrong client', $this->paralegal))->toThrow(HttpException::class);

    $this->invoices->cancel($sent, 'Wrong client', $this->accountant);
    expect($sent->fresh())->status->toBe('Cancelled')->cancel_reason->toBe('Wrong client')->number->toBe($sent->number)
        ->and(InvoiceResource::displayStatus($sent->fresh()))->toBe('Cancelled');

    $paid = ($this->proforma)($this->foreign, 'USD', [['matter_ref' => 'P26090101DE', 'fee_type' => 'Professional Fees', 'rate' => '300']]);
    app(PaymentService::class)->receive($paid, ['received_on' => today()->toDateString(), 'amount' => '100', 'currency' => 'USD', 'bank_account_id' => $paid->bank_account_id, 'txn_ref' => 'P1'], $this->accountant);
    expect(fn () => $this->invoices->cancel($paid->fresh(), 'x', $this->accountant))->toThrow(HttpException::class);
});

it('copies an invoice to a new draft with the same fees and records sending to the client', function () {
    $tax = ($this->taxFrom)(($this->proforma)($this->foreign, 'USD', [['matter_ref' => 'P26090101DE', 'fee_type' => 'Official Fees', 'rate' => '400'], ['matter_ref' => 'P26090101DE', 'fee_type' => 'Professional Fees', 'rate' => '600']]));
    $copy = $this->invoices->copyToDraft($tax, $this->accountant);
    expect($copy)->doc_type->toBe('proforma')->status->toBe('Draft')->number->toBeNull()->grand_total->toBe('1000.00')->copied_from_id->toBe($tax->proforma_id)
        ->and($copy->items()->count())->toBe(2)
        ->and($copy->invoice_date->isToday())->toBeTrue()
        ->and($tax->fresh()->status)->toBe('Issued');

    $mail = InvoiceResource::clientEmail($tax);
    expect($mail)->toMatchArray(['to' => 'ip@acme.de'])->and($mail['subject'])->toContain('Tax invoice '.$tax->number)->and($mail['message'])->toContain('Dear Dr Weber')
        ->and(InvoiceResource::mailtoUrl($tax))->toStartWith('mailto:ip@acme.de?cc=');
    expect(fn () => $this->invoices->markSentToClient($copy, 'a@b.c', $this->accountant))->toThrow(HttpException::class);
    $this->invoices->markSentToClient($tax, 'ip@acme.de', $this->accountant);
    expect($tax->fresh())->client_sent_to->toBe('ip@acme.de')->and($tax->fresh()->client_sent_at)->not->toBeNull();
});

it('offers the new actions in the invoice list', function () {
    $sent = ($this->proforma)($this->foreign, 'USD', [['matter_ref' => 'P26090101DE', 'fee_type' => 'Professional Fees', 'rate' => '300']]);
    Livewire::test(InvoicePage::class)
        ->callTableAction('send_client', $sent, ['to' => 'ip@acme.de', 'subject' => 'Proforma', 'message' => 'Hi'])->assertHasNoTableActionErrors()
        ->callTableAction('copy_draft', $sent)->assertHasNoTableActionErrors()
        ->callTableAction('cancel_proforma', $sent, ['reason' => 'Duplicate'])->assertHasNoTableActionErrors();
    expect($sent->fresh())->status->toBe('Cancelled')->client_sent_to->toBe('ip@acme.de')
        ->and(Invoice::where('copied_from_id', $sent->id)->where('status', 'Draft')->exists())->toBeTrue();
});

it('flags foreign tax invoices without a rate, old drafts, the LUT and banks without account numbers', function () {
    $tax = ($this->taxFrom)(($this->proforma)($this->foreign, 'USD', [['matter_ref' => 'P26090101DE', 'fee_type' => 'Professional Fees', 'rate' => '300']]));
    $draft = $this->invoices->draft($this->accountant, $this->foreign->id, 'USD', [['matter_ref' => 'P26090101DE', 'fee_type' => 'Professional Fees', 'rate' => '10']]);
    $draft->forceFill(['created_at' => now()->subDays(9)])->saveQuietly();
    Setting::updateOrCreate(['key' => 'lut_valid_upto'], ['value' => today()->addDays(20)->toDateString()]);

    $problems = collect(app(MasterDataChecks::class)->accountsChecks())->map(fn ($c) => $c['record'].': '.$c['problem'])->implode("\n");
    expect($problems)->toContain($tax->number.': INR exchange rate missing', 'Draft #'.$draft->id.': Draft older than 7 days', 'LUT ARN is not set', 'LUT expires on', 'Account number missing');

    $tax->update(['inr_exchange_rate' => '83']);
    expect(collect(app(MasterDataChecks::class)->accountsChecks())->pluck('record'))->not->toContain($tax->number);
    $this->get(DataChecks::getUrl())->assertOk()->assertSee('Invoices, LUT and banks');
});
