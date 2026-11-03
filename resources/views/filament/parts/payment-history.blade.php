<div style="overflow:auto">
    <div class="crm-mini-stats">
        <div><small>Invoice</small><b>{{ \App\Services\Money::format($invoice->grand_total, $invoice->currency) }}</b></div>
        <div><small>Received</small><b>{{ \App\Services\Money::format($invoice->registerPaymentTotal(), $invoice->currency) }}</b></div>
        <div><small>TDS</small><b>{{ \App\Services\Money::format($invoice->registerPaymentTotal('tds_amount'), $invoice->currency) }}</b></div>
        <div><small>Written off</small><b>{{ \App\Services\Money::format($invoice->registerPaymentTotal('write_off_amount'), $invoice->currency) }}</b></div>
        <div><small>Balance</small><b class="crm-red">{{ \App\Services\Money::format($invoice->outstandingAmount(), $invoice->currency) }}</b></div>
    </div>
    <table class="crm-table" style="margin-top:14px">
        <thead><tr>@foreach(['Date', 'Type', 'Received', 'TDS', 'Bank charges', 'Net in bank', 'Bank / Gateway', 'Reference', 'Recorded by', 'Notes'] as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse($invoice->registerPayments() as $payment)
            <tr>
                <td>{{ $payment->received_on->format('d.m.Y') }}</td>
                <td>{{ $payment->entry_type === 'write_off' ? 'Write-off '.\App\Services\Money::format($payment->write_off_amount, $payment->currency) : 'Receipt' }}</td>
                <td>{{ \App\Services\Money::format($payment->amount, $payment->currency) }}</td>
                <td>{{ \App\Services\Money::format($payment->tds_amount ?? '0', $payment->currency) }}</td>
                <td>{{ \App\Services\Money::format($payment->charges_deducted, $payment->currency) }}</td>
                <td>{{ \App\Services\Money::format(bcsub((string) $payment->amount, (string) $payment->charges_deducted, 2), $payment->currency) }}</td>
                <td>{{ $payment->bankAccount?->bank_name ?? $payment->gateway?->name ?? '—' }}</td>
                <td>{{ $payment->txn_ref }}</td>
                <td>{{ $payment->sender_name ? $payment->sender_name.' / ' : '' }}{{ $payment->markedBy?->name }}</td>
                <td style="white-space:pre-line">{{ trim($payment->payment_advice."\n".$payment->notes) }}</td>
            </tr>
        @empty
            <tr><td colspan="10" style="padding:20px">No payments recorded.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
