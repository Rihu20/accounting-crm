@php($role = auth()->user()?->crmRole())
@if($role)<span class="crm-role-chip crm-role-{{ \Illuminate\Support\Str::slug($role) }}">{{ $role }}</span>@endif
