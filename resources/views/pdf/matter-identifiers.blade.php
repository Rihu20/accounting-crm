<div class="matter-details">
    @if($item->applicant_name)<div>Applicant: {{ $item->applicant_name }}</div>@endif
    @if($item->app_no || $item->pct_no)
        <div>
            @if($item->app_no)<span class="identifier">App No.: {{ $item->app_no }}</span>@endif
            @if($item->pct_no)<span class="identifier">@if($item->app_no)| &nbsp; @endif PCT App No.: {{ $item->pct_no }}</span>@endif
        </div>
    @endif
    <div>
        @if($item->our_ref)<span class="identifier">Our ref: {{ $item->our_ref }}</span>@endif
        @if($item->your_ref)<span class="identifier">@if($item->our_ref)| &nbsp; @endif Your ref: {{ $item->your_ref }}</span>@endif
    </div>
</div>
