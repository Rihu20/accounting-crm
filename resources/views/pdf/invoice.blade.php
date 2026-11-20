<!doctype html><html><head><meta charset="utf-8"><style>
@page{margin:0}
body{font-family:DejaVu Sans,sans-serif;font-size:9px;line-height:1.4;color:#1c1b1b;margin:0}
.page{page-break-after:always;position:relative}.page:last-child{page-break-after:auto}
.band{height:7px;background:#c62828}
.pad{padding:0 30px}
table{width:100%;border-collapse:collapse}
td,th{vertical-align:top;padding:0}
.top td{padding-top:22px;padding-bottom:14px}
.logo{max-height:58px;max-width:200px;margin-bottom:8px}
.wordmark{font-size:22px;font-weight:bold;color:#1c1b1b;margin-bottom:6px}.wordmark span{color:#d32f2f}
.co{font-size:8.5px;line-height:1.55;color:#111}.co b{font-size:10px;color:#000}
.doc{text-align:right}
.kind{font-size:9px;letter-spacing:2px;text-transform:uppercase;color:#d32f2f;font-weight:bold}
.no{font-size:22px;font-weight:bold;margin:2px 0 8px}
.meta td{padding:0 0 6px 14px;text-align:left}
.lbl{display:block;font-size:7.5px;letter-spacing:1px;text-transform:uppercase;color:#8a8080}
.meta b{font-size:9.5px;color:#000}
.parties td{border-top:1px solid #eee6e5;border-bottom:1px solid #eee6e5;padding:12px 0}
.party{line-height:1.6}.party .name{font-size:12px;font-weight:bold;color:#000;margin:1px 0 2px}
.party-right{text-align:right;width:34%}
.items{table-layout:fixed;margin-top:10px}
.items th{white-space:nowrap;font-size:7.5px;letter-spacing:.8px;text-transform:uppercase;color:#6f6767;text-align:left;font-weight:bold;padding:8px 6px 8px 0;border-bottom:2px solid #1c1b1b}
.items td{padding:6px 6px 6px 0;border-bottom:1px solid #f1eaea;overflow-wrap:break-word}
.items .first{padding-left:30px}.items .last{padding-right:30px}
.items .c{text-align:center}.items .r{text-align:right}
.items .sn{color:#8a8080}
.items tr.grp td{background:#faf7f7;padding-top:8px;padding-bottom:6px;border-bottom:0}
.gt{font-weight:bold;font-size:9.5px}
.gm{font-size:8px;color:#3a3535;margin-top:1px}
.dot{color:#c9bcbc}
.gsub{font-weight:bold;color:#8a8080;font-size:8.5px}
.items tr.fee td.desc{padding-left:12px}
.lang{color:#6f6767}
.items tr.carry td{background:#f5f2f2;font-weight:bold;border-bottom:1px solid #e5dddd}
.bottom td{padding-top:12px}
.words b{display:block;font-size:9.5px;margin-top:2px}
.note{margin-top:8px;font-size:8.5px;color:#555;border-left:2px solid #e53935;padding-left:6px}
.totals td{padding:4px 0;border-bottom:1px solid #f1eaea}
.totals .v{text-align:right;font-weight:bold}
.totals small{color:#8a8080;font-weight:normal}
.grand{background:#1c1b1b;border-radius:5px}.grand td{background:#1c1b1b;color:#fff;padding:7px 9px;font-size:11px;font-weight:bold;border:0}
.grand small{color:#ddd;font-weight:normal}
.due .v{color:#c62828}
.pay{margin:14px 30px 0;border:1px solid #f3c7c7;background:#fff7f7;border-radius:8px;padding:10px 12px}
.h{font-size:7.5px;letter-spacing:1px;text-transform:uppercase;color:#d32f2f;font-weight:bold;margin-bottom:6px}
.br td{padding:3px 0;border-bottom:1px dashed #f0d9d9}
.br .k{width:150px;font-size:7.5px;letter-spacing:.8px;text-transform:uppercase;color:#8a8080;padding-top:5px}
.br .v{font-size:9.5px;font-weight:bold}
.hi{font-family:DejaVu Sans Mono,monospace;font-size:10px;background:#fff;border:1px solid #f3c7c7;border-radius:3px;padding:1px 6px}
.muted{color:#6f6767}
.foot td{padding-top:14px;vertical-align:bottom}
.terms{font-size:8px;color:#555}
.sign{text-align:center;width:34%}
.sign img{max-height:52px;max-width:150px}
.sigsp{height:40px}
.sigline{border-top:1px solid #1c1b1b;margin-top:3px;padding-top:3px;font-size:8.5px;font-weight:bold}
.gen{margin:14px 30px 0;padding-top:8px;border-top:1px solid #eee6e5;font-size:7.5px;color:#8a8080;text-align:center}
.stamp{position:absolute;left:300px;top:96px;border:2px solid #2e7d32;color:#2e7d32;font-weight:bold;font-size:18px;letter-spacing:4px;padding:3px 12px;transform:rotate(-12deg);opacity:.55;border-radius:5px}
.cont td{padding:16px 0 10px}
.compact .totals td{padding:2px 0;font-size:8px}.compact .pay{padding:7px 10px}.compact .br td{padding:1px 0}
</style></head><body>
@php
    $dp = $invoice->currency === 'JPY' ? 0 : 2;
    $indianDigits = new \NumberFormatter('en_IN', \NumberFormatter::DECIMAL);
    $indianDigits->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 2);
    $indianDigits->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);
    $num = fn ($v) => $invoice->currency === 'INR' ? $indianDigits->format((float) $v) : number_format((float) $v, $dp);
    $symbol = ['USD' => '$', 'INR' => '₹', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥'][$invoice->currency] ?? '';
    $isTax = $invoice->doc_type === 'tax';
    $client = $invoice->client;
    $indian = $client->client_type === 'Indian';
    $groupTotals = collect($pages)->flatMap(fn ($p) => $p['items'])->groupBy('matter_index')->map(fn ($g) => $g->reduce(fn ($s, $i) => bcadd($s, (string) $i->amount, 2), '0.00'));
    $paid = ($invoice->doc_type === 'proforma' && in_array($invoice->status, ['Received', 'Converted'], true)) || ($isTax && $invoice->proforma_id);
    $received = $invoice->doc_type === 'proforma' ? bcadd($invoice->registerPaymentTotal(), $invoice->registerPaymentTotal('tds_amount'), 2) : '0.00';
    $chargeCount = count($invoice->additional_charges ?? []);
    $date = fn ($d) => $d?->format('F d, Y');
@endphp
@foreach($pages as $page)<div class="page">
<div class="band"></div>
@if($page['first'] ?? $loop->first)
    @if($paid)<div class="stamp">PAID</div>@endif
    <table class="top"><tr>
        <td style="width:56%;padding-left:30px">@if($logo ?? null)<img class="logo" src="{{ $logo }}"><br>@else<div class="wordmark">Analyst<span>IP</span></div>@endif
            <div class="co"><b>{{ $settings['company_name'] ?? '' }}</b><br>{{ $settings['company_address'] ?? '' }}<br>GSTIN {{ $settings['company_gstin'] ?? '' }}<br>{{ $settings['company_email'] ?? '' }}{{ ($settings['company_mobile'] ?? '') ? ' · '.$settings['company_mobile'] : '' }}{{ ($settings['company_phone'] ?? '') ? ' · '.$settings['company_phone'] : '' }}</div></td>
        <td class="doc" style="padding-right:30px"><div class="kind">{{ $isTax ? 'Tax Invoice' : 'Proforma Invoice' }}</div><div class="no">{{ $invoice->number ?? 'DRAFT' }}</div>
            <table class="meta"><tr><td><span class="lbl">Invoice date</span><b>{{ $date($invoice->invoice_date) }}</b></td><td><span class="lbl">Due date</span><b>{{ $date($invoice->due_date) }}</b></td></tr><tr><td><span class="lbl">Payment term</span><b>{{ $invoice->payment_term_days }} days</b></td><td><span class="lbl">Currency</span><b>{{ $invoice->currency }}</b></td></tr></table></td>
    </tr></table>
    <div style="padding:0 30px"><table class="parties"><tr>
        <td class="party"><span class="lbl">Invoice to</span><div class="name">{{ $client->name }}</div>@if($client->contact_person)Attn. {{ $client->contact_person }}<br>@endif{{ $client->address }}<br>{{ implode(' · ', array_filter([$client->email_1, $client->email_2, $client->phone])) }}@if($indian)<br>GSTIN: {{ $client->gstin ?: 'Unregistered' }}@endif<br>Place of Supply: {{ \App\Services\Gst::placeOfSupply($client) }}</td>
        <td class="party party-right"><span class="lbl">Client ref</span><b>{{ $client->ref_no ?: '—' }}</b>@if($isTax && $invoice->proforma)<br><br><span class="lbl">Against proforma</span><b>{{ $invoice->proforma->number }}</b>@endif</td>
    </tr></table></div>
@else
    <table class="cont"><tr><td style="padding-left:30px"><b>{{ $client->name }}</b> <span class="muted">— continued</span></td><td style="text-align:right;padding-right:30px"><span class="kind">{{ $isTax ? 'Tax Invoice' : 'Proforma Invoice' }}</span> <b>{{ $invoice->number ?? 'DRAFT' }}</b></td></tr></table>
@endif
<table class="items"><colgroup><col style="width:7%"><col style="width:43%"><col style="width:12%"><col style="width:9%"><col style="width:11%"><col style="width:6%"><col style="width:12%"></colgroup>
<thead><tr><th class="first">#</th><th>Description of service</th><th class="c">Country</th><th class="c">SAC</th><th class="r">Rate</th><th class="c">Qty</th><th class="r last">Amount ({{ $invoice->currency }})</th></tr></thead><tbody>
@if(bccomp($page['brought'], '0', 2) > 0)<tr class="carry"><td class="first" colspan="6">Brought forward</td><td class="r last">{{ $num($page['brought']) }}</td></tr>@endif
@foreach($page['items'] as $item)
    @if($item->show_matter)
        @php($ids = array_filter([$item->applicant_name ? 'Applicant: '.$item->applicant_name : null, $item->app_no ? 'App No.: '.$item->app_no : null, $item->pct_no ? 'PCT App No.: '.$item->pct_no : null, $item->our_ref ? 'Our ref: '.$item->our_ref : null, $item->your_ref ? 'Your ref: '.$item->your_ref : null]))
        <tr class="grp"><td class="first sn">{{ $item->matter_index }}</td><td colspan="5"><div class="gt">{{ $item->group_title }}</div>@if($ids)<div class="gm">{!! collect($ids)->map(fn ($s) => e($s))->implode(' <span class="dot">·</span> ') !!}</div>@endif</td><td class="r last gsub">{{ $num($groupTotals[$item->matter_index] ?? $item->amount) }}</td></tr>
    @endif
    <tr class="fee"><td class="first"></td><td class="desc">{{ $item->fee_type }}@if($item->language)<span class="lang"> · {{ $item->language }}</span>@endif @if($item->matter_text !== $item->group_title)<div class="gm">{{ $item->matter_text }}</div>@endif</td><td class="c">{{ $item->country }}</td><td class="c">{{ $item->sac_code }}</td><td class="r">{{ $num($item->rate) }}</td><td class="c">{{ rtrim(rtrim((string) $item->qty, '0'), '.') }}</td><td class="r last">{{ $num($item->amount) }}</td></tr>
@endforeach
@if(!$page['last'])<tr class="carry"><td class="first" colspan="6">Carried forward</td><td class="r last">{{ $num($page['carried']) }}</td></tr>@endif
</tbody></table>
@if($page['last'])<div class="{{ $chargeCount > 6 ? 'compact' : '' }}">
    <table class="bottom"><tr>
        <td style="padding-left:30px;padding-right:24px"><div class="words"><span class="lbl">Amount in words</span><b>{{ $invoice->amount_in_words }}</b></div>
            @if(!$indian && $isTax)<div class="note">{{ \App\Services\Gst::lutLine() }}</div>@endif
            @if($invoice->print_exchange_rate && $conversion = $invoice->conversions()->latest()->first())<div class="note">Exchange rate applied: 1 {{ $conversion->source_currency }} = {{ $conversion->rate }} {{ $conversion->target_currency }} ({{ $conversion->rate_date }})</div>@endif</td>
        <td style="width:44%;padding-right:30px"><table class="totals">
            <tr><td>Subtotal</td><td class="v">{{ $num($invoice->subtotal) }}</td></tr>
            @if(!$indian)<tr><td>IGST 0% <small>Export of services – LUT</small></td><td class="v">{{ $num(0) }}</td></tr>
            @elseif(!$invoice->gst_enabled)<tr><td>GST not applied</td><td class="v">{{ $num(0) }}</td></tr>
            @else
                @php($professionalBase = '0.00') @php($gstRates = [])
                @foreach($invoice->items as $fee) @if($fee->fee_type === 'Professional Fees') @php($professionalBase = bcadd($professionalBase, $fee->amount, 2)) @php($gstRates[] = $fee->tax_rate) @endif @endforeach
                @php($gstRates = array_values(array_unique($gstRates))) @php($gstRate = count($gstRates) === 1 ? $gstRates[0] : null)
                <tr><td>GST taxable Professional Fees</td><td class="v">{{ $num($professionalBase) }}</td></tr>
                @if(\App\Services\Gst::intraState($client))
                    <tr><td>CGST {{ $gstRate !== null ? bcdiv($gstRate, '2', 2).'%' : '' }}</td><td class="v">{{ $num(\App\Services\Money::round(bcdiv($invoice->tax_total, '2', 4))) }}</td></tr>
                    <tr><td>SGST {{ $gstRate !== null ? bcdiv($gstRate, '2', 2).'%' : '' }}</td><td class="v">{{ $num(bcsub($invoice->tax_total, \App\Services\Money::round(bcdiv($invoice->tax_total, '2', 4)), 2)) }}</td></tr>
                @else<tr><td>IGST {{ $gstRate !== null ? rtrim(rtrim($gstRate, '0'), '.').'%' : '' }}</td><td class="v">{{ $num($invoice->tax_total) }}</td></tr>@endif
            @endif
            @if(bccomp($invoice->disbursement_total, '0', 2) > 0)<tr><td>{{ $invoice->disbursement_label ?: 'Other charges' }}</td><td class="v">{{ $num($invoice->disbursement_total) }}</td></tr>@endif
            @foreach($invoice->additional_charges ?? [] as $charge)<tr><td>{{ $charge['label'] }}</td><td class="v">{{ $num($charge['amount']) }}</td></tr>@endforeach
            @if(bccomp((string) ($invoice->round_off ?? '0'), '0', 2) !== 0)<tr><td>Round off</td><td class="v">{{ $num($invoice->round_off) }}</td></tr>@endif
            <tr><td colspan="2" style="border:0;padding:6px 0 4px"><table class="grand"><tr><td style="border-radius:5px 0 0 5px">Total payable</td><td style="text-align:right;border-radius:0 5px 5px 0">{{ $symbol }}{{ $num($invoice->grand_total) }} <small>{{ $invoice->currency }}</small></td></tr></table></td></tr>
            @if(bccomp($received, '0', 2) > 0)<tr><td>Received</td><td class="v">{{ $num($received) }}</td></tr><tr class="due"><td>Balance due</td><td class="v">{{ $symbol }}{{ $num($invoice->outstandingAmount()) }}</td></tr>@endif
        </table></td>
    </tr></table>
    <div class="pay">
        @if($bank = $invoice->bankAccount)<div class="h">Pay through bank / wire transfer</div>
            <table class="br">
                @foreach([['Beneficiary name', $bank->beneficiary_name, false], ['Beneficiary address', $bank->beneficiary_address, false], ['Bank name', $bank->bank_name, false], ['Bank address', $bank->bank_address, false], ['Account / IBAN no.', $bank->account_no ?: '—', true], ['SWIFT / BIC code', $bank->swift, true], ['IFSC code', $bank->ifsc, true], ['Currency', $bank->currency, false]] as [$label, $value, $hi])
                    @if(filled($value))<tr><td class="k">{{ $label }}</td><td class="v">@if($hi)<span class="hi">{{ $value }}</span>@else{{ $value }}@endif</td></tr>@endif
                @endforeach
            </table>
            @if($invoice->gateway)<div style="margin-top:6px"><b>{{ $invoice->gateway->name }}</b> <span class="muted">{{ $invoice->gateway->instructions }}</span></div>@endif
        @elseif($invoice->gateway)<div class="h">Pay through {{ $invoice->gateway->name }}</div><div class="muted">{{ $invoice->gateway->instructions }}</div>
        @else<div class="h">Payment</div><div class="muted">Payment instructions pending.</div>@endif
    </div>
    <table class="foot"><tr>
        <td class="terms" style="padding-left:30px;padding-right:24px"><div class="h">Terms &amp; conditions</div>{!! nl2br(e($settings['terms'] ?? '')) !!}</td>
        <td class="sign" style="padding-right:30px">@if($signature ?? null)<img src="{{ $signature }}">@else<div class="sigsp"></div>@endif<div class="sigline">For {{ $settings['signature_company_name'] ?? 'AnalystIP' }}<br>Authorized Signatory</div></td>
    </tr></table>
</div>@endif
<div class="gen">{{ $settings['company_name'] ?? '' }} · {{ $settings['company_email'] ?? '' }}{{ ($settings['company_mobile'] ?? '') ? ' · '.$settings['company_mobile'] : '' }}<br>This is a computer generated invoice · Original copy for recipient · Page {{ $loop->iteration }} of {{ count($pages) }}</div>
</div>@endforeach</body></html>
