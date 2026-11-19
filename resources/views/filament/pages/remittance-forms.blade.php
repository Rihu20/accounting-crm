<x-filament-panels::page>
    @include('filament.parts.style')
    <section class="crm-card">
        <h3>Our bank accounts <span class="crm-muted">({{ $this->banks()->count() }})</span></h3>
        <p>Saved for every Form A2. On each form choose the account debited for the remittance and the account debited for charges; they can be at different banks.</p>
        @forelse($this->banks() as $bank)
            <div class="crm-row">
                <span><strong>{{ $bank->bank_name }}{{ $bank->branch ? ', '.$bank->branch : '' }}</strong><small>{{ $bank->currency }}{{ $bank->account_type ? ' '.$bank->account_type : '' }} · {{ $bank->account_no }}{{ $bank->notes ? ' · '.$bank->notes : '' }}</small></span>
                <span style="display:flex;gap:12px">{{ ($this->editBankAction)(['bank' => $bank->id]) }} {{ ($this->removeBankAction)(['bank' => $bank->id]) }}</span>
            </div>
        @empty
            <p class="crm-muted">{{ $this->bankHint() }}</p>
        @endforelse
    </section>
    @php($parties = \App\Services\RemittanceFormService::parties())
    <section class="crm-card">
        <h3>Parties paid by Form A2 <span class="crm-muted">({{ $parties->count() }})</span></h3>
        <p>Every beneficiary a form was made for, with the bank details used on the latest form. These details are also saved to the vendor and filled in next time.</p>
        @forelse($parties as $party)
            @php($d = $party['form']->data)
            <div class="crm-row">
                <span><strong>{{ $party['form']->beneficiary }}</strong><small>A/c {{ $d['bank_account'] ?? '-' ?: '-' }} · {{ $d['bank_name'] ?? '' ?: 'bank not set' }}{{ ($d['bank_address'] ?? '') ? ', '.$d['bank_address'] : '' }} · SWIFT {{ $d['swift'] ?? '' ?: '-' }}{{ ($d['address'] ?? '') ? ' · '.$d['address'] : '' }}{{ ($d['country'] ?? '') ? ' · '.$d['country'] : '' }}</small></span>
                <span class="amt"><small>last form</small> {{ \Illuminate\Support\Carbon::parse($d['date'] ?? $party['form']->created_at)->format('d.m.Y') }}<br><small>{{ $party['count'] }} form{{ $party['count'] === 1 ? '' : 's' }} since {{ \Illuminate\Support\Carbon::parse($party['first'])->format('d.m.Y') }}</small></span>
            </div>
        @empty
            <p class="crm-muted">No party yet. Parties appear here once a form is made for them.</p>
        @endforelse
    </section>
    @php($forms = $this->preparedForms())
    <section class="crm-card">
        <h3>Forms prepared <span class="crm-muted">(latest {{ $forms->count() }})</span> <a href="{{ \App\Filament\Pages\RemittanceRegister::getUrl() }}">Open the remittance register</a></h3>
        <p>The latest forms. The remittance register has all of them, with handed-to-bank, remitted, 15CA/CB, edit, copy, cancel and Excel export.</p>
        @forelse($forms as $form)
            <div class="crm-row">
                <span><strong>{{ $form->beneficiary }}</strong><small>{{ \Illuminate\Support\Carbon::parse($form->data['date'] ?? $form->created_at)->format('d.m.Y') }} · {{ \App\Services\Money::format((string) $form->amount, $form->currency) }}{{ $form->expense?->vendor_invoice_no ? ' · invoice '.$form->expense->vendor_invoice_no : '' }} · remittance {{ $form->debitBank?->bank_name ?? 'no bank' }}, charges {{ $form->chargesBank?->bank_name ?? 'no bank' }} · by {{ $form->preparer?->name ?? 'unknown' }}</small></span>
                <span class="amt"><span class="crm-badge {{ ['Prepared' => 'gray', 'Submitted' => 'gray', 'Remitted' => 'green', 'Cancelled' => 'red'][$form->status] ?? 'gray' }}">{{ $form->status }}</span><br><a class="crm-link" href="{{ route('crm.remittance-form', $form) }}">Open (Word)</a></span>
            </div>
        @empty
            <p class="crm-muted">No form prepared yet. Use “Prepare Form A2” on a bill below.</p>
        @endforelse
    </section>
    {{ $this->table }}
</x-filament-panels::page>
