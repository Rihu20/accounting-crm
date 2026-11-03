<x-filament-panels::page>
@include('filament.parts.style')
@php
    $r = app(\App\Services\ReportService::class);
    $ageing = $r->ageing();
    $monthly = $r->monthly();
    $fmt = fn ($a, $c) => \App\Services\Money::format((string) $a, $c);
    $pay = \App\Services\DashboardService::for(auth()->user())->payables();
@endphp

<div class="crm-split even" style="margin-top:0">
    <section class="crm-card">
        <h3>GSTR-1 working (Excel)</h3>
        <p>Tax invoices split into B2B / B2CL / B2CS / Export (LUT), plus a Credit-Debit Notes sheet. Foreign invoices use the INR rate saved in <b>Register details</b> – rows show <b>RATE NEEDED</b> where it is missing.</p>
        <form method="GET" action="/crm/reports/gstr1" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <input type="month" name="month" value="{{ now()->subMonthNoOverflow()->format('Y-m') }}" required style="border:1px solid #ddd4d3;border-radius:10px;padding:9px 12px">
            <button class="crm-btn" type="submit">⬇ Download GSTR-1</button>
        </form>
    </section>
    <section class="crm-card">
        <h3>Exports</h3>
        <div class="crm-quick">
            <a href="/crm/reports/ageing"><span>⏳</span><div>Receivables ageing<small>Every unpaid proforma with days</small></div></a>
            <a href="/crm/payments/export"><span>₹</span><div>Payment tracker<small>All invoices, received, TDS, balance</small></div></a>
        </div>
    </section>
</div>

<section class="crm-card" style="margin-top:16px">
    <h3>Receivables ageing <a href="/crm/reports/ageing">Excel</a></h3>
    @if($ageing)
        <div class="crm-scroll"><table class="crm-table">
            <tr><th>Currency</th>@foreach(array_keys(\App\Services\ReportService::BUCKETS) as $b)<th style="text-align:right">{{ $b }} days</th>@endforeach<th style="text-align:right">Total due</th><th style="text-align:right">Proformas</th></tr>
            @foreach($ageing as $c => $row)
                <tr><td><b>{{ $c }}</b></td>@foreach(array_keys(\App\Services\ReportService::BUCKETS) as $b)<td style="text-align:right;{{ $b !== '0-30' && bccomp($row[$b], '0', 2) > 0 ? 'color:#c62828;font-weight:600' : '' }}">{{ $fmt($row[$b], $c) }}</td>@endforeach<td style="text-align:right"><b>{{ $fmt($row['total'], $c) }}</b></td><td style="text-align:right">{{ $row['count'] }}</td></tr>
            @endforeach
        </table></div>
    @else
        <div class="crm-empty"><span>✓</span><strong>Nothing outstanding</strong>Every sent proforma is paid.</div>
    @endif
</section>

<div class="crm-split even">
    <section class="crm-card">
        <h3>Last 6 months – invoiced vs received</h3>
        <div class="crm-scroll"><table class="crm-table">
            <tr><th>Month</th><th>Tax invoices</th><th>Invoiced</th><th>Received</th></tr>
            @foreach(array_reverse($monthly) as $m)
                <tr><td><b>{{ $m['month'] }}</b></td><td>{{ $m['tax_count'] }}</td>
                    <td>@forelse($m['invoiced'] as $c => $a){{ $fmt($a, $c) }}<br>@empty<span class="crm-muted">—</span>@endforelse</td>
                    <td>@forelse($m['received'] as $c => $a){{ $fmt($a, $c) }}<br>@empty<span class="crm-muted">—</span>@endforelse</td></tr>
            @endforeach
        </table></div>
    </section>
    <section class="crm-card">
        <h3>Payables – unpaid vendor bills</h3>
        @forelse($pay as $c => $p)
            <div class="crm-row"><span><strong>{{ $c }}</strong><small>{{ $p['count'] }} bill(s)</small></span><span class="amt">{{ $fmt($p['amount'], $c) }}</span></div>
        @empty
            <div class="crm-empty"><span>✓</span><strong>All bills paid</strong></div>
        @endforelse
        <p style="margin-top:12px"><a class="crm-link" href="{{ \App\Filament\Resources\ExpenseResource::getUrl() }}?tab=unpaid">Open bills →</a></p>
    </section>
</div>

@if(auth()->user()->isRole('Accountant'))
<section class="crm-card" style="margin-top:16px"><h3>Audit history</h3><div class="crm-scroll"><table class="crm-table"><tr><th>Date</th><th>Who</th><th>Event</th><th>Record</th><th>Changes</th></tr>@foreach(\Spatie\Activitylog\Models\Activity::with('causer')->latest()->limit(30)->get() as $a)<tr><td>{{ $a->created_at->format('d.m.Y H:i') }}</td><td>{{ $a->causer?->name ?? 'System' }}</td><td>{{ $a->description }}</td><td>{{ class_basename($a->subject_type ?? '') }} #{{ $a->subject_id }}</td><td><details><summary>View</summary><pre style="white-space:pre-wrap;font-size:11px">{{ json_encode(['changes' => $a->attribute_changes, 'details' => $a->properties], JSON_PRETTY_PRINT) }}</pre></details></td></tr>@endforeach</table></div></section>
@endif
</x-filament-panels::page>
