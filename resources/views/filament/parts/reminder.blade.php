<div x-data="{ copied: false, text: @js($text) }" style="display:grid;gap:12px">
    <p class="crm-muted" style="font-size:13px">Copy this and send it from your email to {{ implode(', ', array_filter([$invoice->client->email_1, $invoice->client->email_2])) ?: 'the client' }}.</p>
    <textarea readonly rows="16" x-ref="t" style="width:100%;font-family:inherit;font-size:13px;border:1px solid #e7dfde;border-radius:12px;padding:12px;background:#fcfafa">{{ $text }}</textarea>
    <div><button type="button" class="crm-btn" x-on:click="navigator.clipboard.writeText(text).then(() => { copied = true; setTimeout(() => copied = false, 2000) }).catch(() => { $refs.t.select(); document.execCommand('copy'); copied = true })"><span x-show="!copied">⧉ Copy email</span><span x-show="copied">✓ Copied</span></button></div>
</div>
