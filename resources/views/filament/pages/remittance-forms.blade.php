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
    @php($forms = $this->preparedForms())
    <section class="crm-card">
        <h3>Forms prepared <span class="crm-muted">({{ $forms->count() }}{{ $forms->count() >= 50 ? ', latest 50' : '' }})</span></h3>
        <p>Every Form A2 made here or from the Expense register. Open one to get the same Word file again.</p>
        @forelse($forms as $form)
            <div class="crm-row">
                <span><strong>{{ $form->beneficiary }}</strong><small>{{ \Illuminate\Support\Carbon::parse($form->data['date'] ?? $form->created_at)->format('d.m.Y') }} · {{ \App\Services\Money::format((string) $form->amount, $form->currency) }}{{ $form->expense?->vendor_invoice_no ? ' · invoice '.$form->expense->vendor_invoice_no : '' }} · remittance {{ $form->debitBank?->bank_name ?? 'no bank' }}, charges {{ $form->chargesBank?->bank_name ?? 'no bank' }} · by {{ $form->preparer?->name ?? 'unknown' }}</small></span>
                <a class="crm-link" href="{{ route('crm.remittance-form', $form) }}">Open (Word)</a>
            </div>
        @empty
            <p class="crm-muted">No form prepared yet. Use “Prepare Form A2” on a bill below.</p>
        @endforelse
    </section>
    {{ $this->table }}
</x-filament-panels::page>
