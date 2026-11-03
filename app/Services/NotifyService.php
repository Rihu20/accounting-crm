<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use App\Notifications\CrmNotification;

class NotifyService
{
    /** Accountants (full access). Kept as "admins" for older callers. */
    public function admins(string $title, string $body, ?string $url = null): void
    {
        $this->role(PlatformAccess::ACCOUNTANT, $title, $body, $url);
    }

    /** Everyone who can approve: Director + Accountant. */
    public function approvers(string $title, string $body, ?string $url = null): void
    {
        $this->role([PlatformAccess::ACCOUNTANT, PlatformAccess::DIRECTOR], $title, $body, $url);
    }

    public function role(string|array $roles, string $title, string $body, ?string $url = null): void
    {
        foreach (User::role((array) $roles)->where('is_active', true)->get() as $u) {
            $u->notify(new CrmNotification($title, $body, url($url ?? '/admin')));
        }
    }

    public function paralegals(Invoice $invoice, string $title, string $body): void
    {
        foreach ($invoice->items()->with('matter.paralegal.user')->get()->pluck('matter.paralegal.user')->filter()->unique('id') as $u) {
            $u->notify(new CrmNotification($title, $body, url('/admin')));
        }
    }
}
