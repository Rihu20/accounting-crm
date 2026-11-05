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
</x-filament-panels::page>
