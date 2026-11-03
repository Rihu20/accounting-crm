<x-filament-panels::page>
@include('filament.parts.style')
@php
    $role = $d->role();
    $fmt = fn ($a, $c) => \App\Services\Money::format((string) $a, $c);
    $hour = (int) now()->format('H');
    $greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
    $inv = \App\Filament\Resources\InvoiceResource::getUrl();
    $exp = \App\Filament\Resources\ExpenseResource::getUrl();
    $days = fn ($date) => $date ? (int) $date->startOfDay()->diffInDays(today()) : 0;
@endphp

<div class="crm-hero">
    <div style="position:relative;z-index:1">
        <div class="crm-eyebrow">{{ now()->format('l, d M Y') }} · {{ $role }}</div>
        <h2>{{ $greet }}, {{ \Illuminate\Support\Str::of($user->name)->before(' ') }}</h2>
        <p>
            @switch($role)
                @case('Director') Payments, approvals and who still owes what – all in one view. @break
                @case('Accountant Assistant') Prepare proformas, tax invoices, notes and expenses. @break
                @case('Paralegal') Track invoices and tell accounts what needs to be paid. @break
                @default Full control of invoices, payments and expenses.
            @endswitch
        </p>
    </div>
    <div class="crm-hero-actions">
        @if($user->allowed('accounting.invoices.create'))
            <a class="crm-btn" href="{{ $inv }}?tab=drafts">＋ New proforma</a>
            <a class="crm-btn ghost" href="/admin/word-upload">From Word file</a>
        @elseif($user->allowed('payments.mark_received'))
            <a class="crm-btn" href="{{ $inv }}?tab=pending">Record a payment</a>
            <a class="crm-btn ghost" href="{{ $exp }}?tab=unpaid">Bills to pay</a>
        @elseif($role === 'Paralegal')
            <a class="crm-btn" href="{{ $inv }}">Open invoices</a>
            <a class="crm-btn ghost" href="{{ $exp }}">Law firm & office bills</a>
        @endif
    </div>
</div>

{{-- ============ DIRECTOR + ACCOUNTANT ============ --}}
@if(in_array($role, ['Director', 'Accountant']))
    @php($rec = $d->receivables()) @php($got = $d->receivedThisMonth()) @php($pay = $d->payables()) @php($paid = $d->paidThisMonth()) @php($ap = $d->approvals())
    <div class="crm-grid">
        <div class="crm-stat t-red"><div class="ico">⏳</div><small>To receive</small>
            <div class="cur">@forelse($rec as $c => $r)<span>{{ $fmt($r['amount'], $c) }}<em>{{ $r['count'] }} proforma</em></span>@empty<span>Nothing pending</span>@endforelse</div>
            <a href="{{ $inv }}?tab=pending">{{ $d->overdueCount() }} overdue 15+ days →</a></div>
        <div class="crm-stat t-green"><div class="ico">✓</div><small>Received this month</small>
            <div class="cur">@forelse($got as $c => $r)<span>{{ $fmt($r['amount'], $c) }}<em>{{ $r['count'] }} receipt</em></span>@empty<span>No receipts yet</span>@endforelse</div>
            <a href="{{ $inv }}?tab=received">Payment received →</a></div>
        <div class="crm-stat t-amber"><div class="ico">₹</div><small>To pay (vendors)</small>
            <div class="cur">@forelse($pay as $c => $r)<span>{{ $fmt($r['amount'], $c) }}<em>{{ $r['count'] }} bill</em></span>@empty<span>All bills paid</span>@endforelse</div>
            <a href="{{ $exp }}?tab=unpaid">Open bills →</a></div>
        <div class="crm-stat t-blue"><div class="ico">↗</div><small>Paid this month</small>
            <div class="cur">@forelse($paid as $c => $r)<span>{{ $fmt($r['amount'], $c) }}<em>{{ $r['count'] }} bill</em></span>@empty<span>Nothing paid yet</span>@endforelse</div>
            <a href="{{ $exp }}">Expense register →</a></div>
    </div>

    <div class="crm-split">
        <section class="crm-card">
            <h3>Waiting for your approval <span class="crm-badge {{ array_sum($ap) ? 'red' : 'green' }}">{{ array_sum($ap) }} open</span></h3>
            <div class="crm-approvals">
                <a href="{{ $inv }}?tab=approvals"><b class="{{ $ap['tax'] ? 'hot' : '' }}">{{ $ap['tax'] }}</b><strong>Tax invoices</strong><small>Paid proformas to approve</small></a>
                <a href="{{ $inv }}?tab=approvals"><b class="{{ $ap['client_payments'] ? 'hot' : '' }}">{{ $ap['client_payments'] }}</b><strong>Client payments</strong><small>Reported by paralegals</small></a>
                <a href="{{ $exp }}?tab=approvals"><b class="{{ $ap['expenses'] ? 'hot' : '' }}">{{ $ap['expenses'] }}</b><strong>Pay requests</strong><small>Law firms & offices</small></a>
            </div>
        </section>
        <section class="crm-card">
            @php($t = $d->trend())
            <h3>Last 6 months <span class="crm-legend"><span><i></i>Proforma</span><span><i class="tax"></i>Tax invoice</span></span></h3>
            <div class="crm-bars">@foreach($t['rows'] as $r)<div class="col"><div class="pair"><i style="height:{{ max(3, $r['proforma'] / $t['max'] * 100) }}%" title="{{ $r['proforma'] }} proforma"></i><i class="tax" style="height:{{ max(3, $r['tax'] / $t['max'] * 100) }}%" title="{{ $r['tax'] }} tax invoices"></i></div><span>{{ $r['label'] }}</span></div>@endforeach</div>
        </section>
    </div>

    <div class="crm-split even">
        <section class="crm-card">
            <h3>Who still has to pay us <a href="{{ $inv }}?tab=pending">View all</a></h3>
            @forelse($d->pendingReceivables() as $i)
                <a class="crm-row" href="{{ $inv }}?tab=pending&tableSearch={{ urlencode($i->number) }}">
                    <span><strong>{{ $i->client->name }}</strong><small>{{ $i->number }} · {{ $days($i->sent_at) }} days</small></span>
                    <span class="amt">{{ $fmt($i->outstandingAmount(), $i->currency) }}<br>@if($days($i->sent_at) >= 15)<span class="crm-badge red">Follow up</span>@elseif($i->status === 'Partially received')<span class="crm-badge blue">Part paid</span>@else<span class="crm-badge">Awaiting</span>@endif</span>
                </a>
            @empty
                <div class="crm-empty"><span>✓</span><strong>No pending receivables</strong>Every sent proforma is paid.</div>
            @endforelse
        </section>
        <section class="crm-card">
            <h3>Bills we still have to pay <a href="{{ $exp }}?tab=unpaid">View all</a></h3>
            @forelse($d->unpaidPayables() as $e)
                <a class="crm-row" href="{{ $exp }}?tab=unpaid">
                    <span><strong>{{ $e->payee_name ?: $e->vendor?->name ?: 'Vendor' }}</strong><small>{{ $e->vendor_invoice_no ?: 'No invoice no' }} · {{ $e->due_date ? 'due '.$e->due_date->format('d.m.Y') : $e->invoice_date?->format('d.m.Y') }}</small></span>
                    <span class="amt">{{ $fmt($e->amount, $e->currency) }}<br>@if(($e->payment_request['status'] ?? null) === 'Pending')<span class="crm-badge red">Requested</span>@elseif($e->due_date && $e->due_date->isPast())<span class="crm-badge red">Overdue</span>@else<span class="crm-badge gray">Unpaid</span>@endif</span>
                </a>
            @empty
                <div class="crm-empty"><span>✓</span><strong>All vendor bills are paid</strong></div>
            @endforelse
        </section>
    </div>

    <section class="crm-card" style="margin-top:16px">
        <h3>Latest receipts</h3>
        @forelse($d->recentPayments() as $p)
            <div class="crm-row"><span><strong>{{ $p->invoiceRef?->client?->name }}</strong><small>{{ $p->invoiceRef?->number }} · {{ $p->received_on->format('d.m.Y') }} · by {{ $p->markedBy?->name }}</small></span><span class="amt">{{ $p->entry_type === 'write_off' ? 'Write-off '.$fmt($p->write_off_amount, $p->currency) : $fmt($p->amount, $p->currency) }}@if(bccomp((string) $p->tds_amount, '0', 2) > 0)<br><small>+ TDS {{ $fmt($p->tds_amount, $p->currency) }}</small>@endif</span></div>
        @empty
            <p class="crm-muted">No payments recorded yet.</p>
        @endforelse
    </section>
@endif

{{-- ============ ACCOUNTANT ASSISTANT (and Accountant work queue) ============ --}}
@if(in_array($role, ['Accountant Assistant', 'Accountant']))
    @php($q = $d->assistantQueue())
    @if($role === 'Accountant Assistant')
    <div class="crm-grid">
        <div class="crm-stat t-amber"><div class="ico">✎</div><small>Drafts to finalise</small><b>{{ $q['drafts'] }}</b><a href="{{ $inv }}?tab=drafts">Open drafts →</a></div>
        <div class="crm-stat t-green"><div class="ico">✓</div><small>Ready for tax invoice</small><b>{{ $q['ready'] }}</b><a href="{{ $inv }}?tab=ready">Generate now →</a></div>
        <div class="crm-stat t-red"><div class="ico">📄</div><small>Proformas this month</small><b>{{ $q['proformas_month'] }}</b><a href="{{ $inv }}?tab=proforma">Proforma list →</a></div>
        <div class="crm-stat t-blue"><div class="ico">🧾</div><small>Tax invoices this month</small><b>{{ $q['tax_month'] }}</b><a href="{{ $inv }}?tab=tax">Tax invoices →</a></div>
    </div>
    @endif
    <div class="crm-split">
        <section class="crm-card">
            <h3>Approved – generate tax invoice <a href="{{ $inv }}?tab=ready">View all</a></h3>
            @forelse($d->readyForTax() as $i)
                <a class="crm-row" href="{{ $inv }}?tab=ready"><span><strong>{{ $i->client->name }}</strong><small>{{ $i->number }} · approved by {{ $i->tax_authorization['reviewed_by_name'] ?? 'Director' }}</small></span><span class="crm-badge green">Generate</span></a>
            @empty
                <div class="crm-empty"><span>✓</span><strong>Nothing waiting</strong>Approved proformas appear here.</div>
            @endforelse
            @if($q['rejected'])<p style="margin-top:10px"><span class="crm-badge red">{{ $q['rejected'] }} request(s) rejected</span> – check the note in the invoice list.</p>@endif
            <h3 style="margin-top:18px">Drafts <a href="{{ $inv }}?tab=drafts">View all</a></h3>
            @forelse($d->drafts(4) as $i)
                <a class="crm-row" href="{{ $inv }}?tab=drafts"><span><strong>{{ $i->client->name }}</strong><small>Created {{ $i->created_at->format('d.m.Y') }}</small></span><span class="amt">{{ $fmt($i->grand_total, $i->currency) }}</span></a>
            @empty
                <p class="crm-muted">No drafts.</p>
            @endforelse
        </section>
        <section class="crm-card">
            <h3>Quick actions</h3>
            <div class="crm-quick">
                <a href="/admin/word-upload"><span>⬆</span><div>Word file → proforma<small>Paralegal fee file</small></div></a>
                <a href="{{ $inv }}?tab=drafts"><span>＋</span><div>New proforma<small>Enter manually</small></div></a>
                <a href="{{ \App\Filament\Resources\InvoiceNoteResource::getUrl() }}"><span>↺</span><div>Credit / Debit note<small>Against a tax invoice</small></div></a>
                <a href="{{ $exp }}"><span>₹</span><div>Record an expense<small>Law firm, office, Indian</small></div></a>
                <a href="/admin/vendor-scan"><span>🔍</span><div>Scan vendor PDF<small>Auto-read the bill</small></div></a>
                <a href="/admin/clients"><span>👤</span><div>Clients & matters<small>Masters</small></div></a>
            </div>
        </section>
    </div>
@endif

{{-- ============ PARALEGAL ============ --}}
@if($role === 'Paralegal')
    @php($s = $d->paralegalSummary())
    <div class="crm-grid">
        <div class="crm-stat t-amber"><div class="ico">⏳</div><small>Proformas awaiting payment</small><b>{{ $s['awaiting'] }}</b><a href="{{ $inv }}?tab=awaiting">See which →</a></div>
        <div class="crm-stat t-blue"><div class="ico">🧾</div><small>Tax invoices this month</small><b>{{ $s['tax_month'] }}</b><a href="{{ $inv }}?tab=tax">Tax invoices →</a></div>
        <div class="crm-stat t-red"><div class="ico">⚖</div><small>Unpaid law firm bills</small><b>{{ $s['law_firms_unpaid'] }}</b><a href="{{ $exp }}?tab=foreign">Law firms →</a></div>
        <div class="crm-stat t-green"><div class="ico">🏛</div><small>Unpaid patent office bills</small><b>{{ $s['offices_unpaid'] }}</b><a href="{{ $exp }}?tab=patent_office">Patent offices →</a></div>
    </div>
    <div class="crm-split even">
        <section class="crm-card">
            <h3>Clients yet to pay <a href="{{ $inv }}?tab=awaiting">View all</a></h3>
            <p>Client told you they paid? Open the invoice → <b>Client has initiated payment</b>.</p>
            @forelse($d->pendingReceivables() as $i)
                <a class="crm-row" href="{{ $inv }}?tab=awaiting"><span><strong>{{ $i->client->name }}</strong><small>{{ $i->number }} · {{ $days($i->sent_at) }} days</small></span><span>@if(($i->payment_initiation['status'] ?? null) === 'Pending')<span class="crm-badge blue">Reported</span>@else<span class="crm-badge">Awaiting</span>@endif</span></a>
            @empty
                <div class="crm-empty"><span>✓</span><strong>All proformas are paid</strong></div>
            @endforelse
        </section>
        <section class="crm-card">
            <h3>Law firm & office bills to pay <a href="{{ $exp }}">View all</a></h3>
            <p>Need one paid? Open it → <b>Request payment</b>. You have <b>{{ $s['my_requests'] }}</b> open request(s).</p>
            @forelse($d->unpaidPayables(6, true) as $e)
                <a class="crm-row" href="{{ $exp }}"><span><strong>{{ $e->payee_name ?: $e->vendor?->name }}</strong><small>{{ $e->vendor_invoice_no }} · {{ $e->our_ref }}</small></span><span class="amt">{{ $fmt($e->amount, $e->currency) }}<br>@if(($e->payment_request['status'] ?? null) === 'Pending')<span class="crm-badge blue">Requested</span>@else<span class="crm-badge gray">Unpaid</span>@endif</span></a>
            @empty
                <div class="crm-empty"><span>✓</span><strong>Nothing unpaid</strong></div>
            @endforelse
        </section>
    </div>
@endif

<section class="crm-card" style="margin-top:16px">
    <h3>Recent notifications</h3>
    @forelse($user->notifications()->latest()->limit(5)->get() as $n)
        <div class="crm-row"><span><strong>{{ $n->data['title'] ?? '' }}</strong><small>{{ $n->data['body'] ?? '' }}</small></span><small>{{ $n->created_at->diffForHumans() }}</small></div>
    @empty
        <p class="crm-muted">You're all caught up.</p>
    @endforelse
</section>
</x-filament-panels::page>
