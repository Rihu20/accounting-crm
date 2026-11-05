<div class="matter-details">
    @if($item->app_no || $item->pct_no)
        <div>
            @if($item->app_no)<span class="identifier">App No.: {{ $item->app_no }}</span>@endif
            @if($item->pct_no)<span class="identifier">PCT App No.: {{ $item->pct_no }}</span>@endif
        </div>
    @endif
    <div>
        <span class="identifier">Our ref: {{ $item->our_ref }}</span>
        @if($item->your_ref)<span class="identifier">Your ref: {{ $item->your_ref }}</span>@endif
    </div>
</div>
