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
    {{ $this->table }}
</x-filament-panels::page>
