<x-filament-panels::page>
@include('filament.parts.style')
<section class="crm-card">
<h3>Read a vendor PDF</h3>
<p>Local PDF reader — no API key required. Text PDFs and scanned pages are read on this computer. Check the suggestions before saving an expense.</p>
@if($errors->any())<p>{{ $errors->first() }}</p>@endif
<form class="crm-form" action="{{ route('crm.extractions.upload') }}" method="post" enctype="multipart/form-data" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Reading PDF… Please wait';">@csrf
<label>Invoice PDF (up to 15 MB, 25 pages)<input type="file" accept=".pdf" name="file" required></label>
<div><button class="crm-btn">Upload and read PDF</button></div>
</form>
</section>
@foreach(\Illuminate\Support\Facades\DB::table('expense_extractions')->where('user_id',auth()->id())->latest()->limit(5)->get() as $e)
@php($d=json_decode($e->data??'{}',true))
<section class="crm-card"><h3>Upload #{{ $e->id }} · {{ $e->status }}</h3>
@if($e->error)<p>{{ $e->error }}</p>@endif
@if(in_array($e->status,['Review required','Manual review']))
@foreach($d['_warnings']??[] as $warning)<p>{{ $warning }}</p>@endforeach
<form action="{{ route('crm.extractions.retry', $e->id) }}" method="post" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Reading…';">@csrf<button class="crm-btn">Read again</button></form>
<p>Suggested vendor: {{ $d['vendor_name']??'Not detected' }}. Choose an existing vendor, or verify the new vendor name and country below. New vendors are created only when you confirm.</p>
<form class="crm-form" action="/crm/extractions/{{ $e->id }}/confirm" method="post">@csrf
<label>Expense group<select name="expense_group"><option value="">Use vendor country</option>@foreach(\App\Services\ExpenseGroups::LABELS as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
<label>Patent / IP office (for office expenses)<select name="patent_office_id"><option value="">Choose office</option>@foreach(\App\Models\PatentOffice::where('is_active',true)->orderBy('country')->get() as $office)<option value="{{ $office->id }}">{{ $office->display_name }}</option>@endforeach</select></label>
<label>Existing vendor<select name="vendor_id"><option value="">Create vendor from fields below</option>@foreach(\App\Models\Vendor::all() as $v)<option value="{{ $v->id }}" @selected(($d['vendor_name']??'')===$v->name)>{{ $v->name }}</option>@endforeach</select></label>
<label>New vendor name<input name="vendor_name" value="{{ $d['vendor_name']??'' }}"></label>
<label>New vendor country<input name="vendor_country" value="{{ $d['_details']['country']??'' }}"></label>
<label>Invoice number<input name="vendor_invoice_no" value="{{ $d['invoice_no']??'' }}" required></label>
<label>Invoice date<input type="date" name="invoice_date" value="{{ $d['invoice_date']??'' }}" required></label>
<label>Due date<input type="date" name="due_date" value="{{ $d['due_date']??'' }}"></label>
<label>Currency<select name="currency" required><option value="">Choose currency</option>@foreach(config('crm.expense_currencies') as $c)<option @selected(($d['currency']??'')===$c)>{{ $c }}</option>@endforeach</select></label>
<label>Total amount<input type="number" step="0.01" min="0.01" name="amount" value="{{ $d['amount']??'' }}" required></label>
<label>Tax amount<input type="number" step="0.01" min="0" name="gst_amount" value="{{ $d['tax_amount']??'' }}"></label>
<label>Client Ref No (optional)<input name="ref_no" value="{{ $d['ref_no']??'' }}" pattern="[0-9]{4}"></label>
<label>Description<input name="notes" value="{{ $d['description']??'' }}"></label>
<div><button class="crm-btn">Confirm reviewed expense</button></div>
</form>
@if(!empty($d['_details']))<details><summary>Other details found in the PDF</summary>@foreach($d['_details'] as $key=>$value)<p><strong>{{ ucwords(str_replace('_',' ',$key)) }}:</strong> {{ is_array($value)?implode(', ',$value):$value }}</p>@endforeach</details>@endif
@if(!empty($d['_text']))<details><summary>Read extracted PDF text</summary><pre style="white-space:pre-wrap;max-height:360px;overflow:auto">{{ $d['_text'] }}</pre></details>@endif
@elseif(in_array($e->status,['Queued','Processing']))<p>Reading is in progress. Refresh to see the result.</p>@endif
</section>
@endforeach
</x-filament-panels::page>
