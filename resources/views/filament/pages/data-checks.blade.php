<x-filament-panels::page>
    @include('filament.parts.style')
    <section class="crm-card">
        <h3>Review client details before billing</h3>
        <p>These checks flag details worth reviewing. Records are only changed when you edit and save them. Missing email does not block billing.</p>
        <a class="crm-link" href="{{ \App\Filament\Resources\ClientResource::getUrl() }}">Open clients →</a>
    </section>
    <div class="crm-split even">
        @foreach(app(\App\Services\MasterDataChecks::class)->clients() as $check)
            <section class="crm-card">
                <h3>{{ $check['title'] }} <span class="crm-muted">({{ $check['count'] }})</span></h3>
                <p>{{ $check['help'] }}</p>
                @forelse($check['records'] as $record)
                    <div class="crm-row">
                        <span>{{ $record['ref_no'] ?: 'No reference' }} · {{ $record['name'] }}</span>
                        <a class="crm-link" href="{{ \App\Filament\Resources\ClientResource::getUrl('index', ['tableSearch' => $record['ref_no'] ?: $record['name']]) }}">Review</a>
                    </div>
                @empty
                    <p>✓ No issues found in this check.</p>
                @endforelse
                @if($check['count'] > count($check['records']))
                    <p class="crm-muted">Showing {{ count($check['records']) }} of {{ $check['count'] }} clients. Review the remaining records in Clients.</p>
                @endif
            </section>
        @endforeach
    </div>
    @php($bills = app(\App\Services\MasterDataChecks::class)->indianBills())
    <section class="crm-card">
        <h3>Indian vendor bills to review <span class="crm-muted">({{ $bills['count'] }})</span></h3>
        <p>PAN, GSTIN and TDS details on Indian purchases that look wrong. TDS without a PAN may have to be deducted at 20%; GST without the vendor GSTIN gives no input credit.</p>
        @forelse($bills['records'] as $record)
            <div class="crm-row">
                <span><strong>{{ $record['party'] }}</strong><small>{{ $record['invoice_no'] ?: 'No invoice no' }} · {{ implode(' · ', $record['problems']) }}</small></span>
                <a class="crm-link" href="{{ \App\Filament\Resources\ExpenseResource::getUrl('index', ['tab' => 'indian', 'tableSearch' => $record['invoice_no'] ?: $record['party']]) }}">Review</a>
            </div>
        @empty
            <p>✓ No issues found in this check.</p>
        @endforelse
        @if($bills['count'] > count($bills['records']))
            <p class="crm-muted">Showing {{ count($bills['records']) }} of {{ $bills['count'] }} bills.</p>
        @endif
    </section>
</x-filament-panels::page>
