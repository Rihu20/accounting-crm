<?php

namespace App\Services;

use App\Models\User;

/**
 * Four fixed roles. The matrix below is the single source of truth for who may do what.
 *
 *  - Accountant            : everything, no barriers.
 *  - Accountant Assistant  : prepares proforma / tax invoices, credit & debit notes and expenses.
 *                            Never sees payment receipts or payment status.
 *  - Director              : sees every invoice and expense tab, changes payment status freely,
 *                            approves tax invoices and paralegal requests. Cannot create invoices.
 *  - Paralegal             : read-only view of proforma, tax invoices and law firm / patent office
 *                            expenses. Can only raise requests (pay this firm / client initiated payment).
 */
class PlatformAccess
{
    public const ACCOUNTANT = 'Accountant';

    public const ASSISTANT = 'Accountant Assistant';

    public const DIRECTOR = 'Director';

    public const PARALEGAL = 'Paralegal';

    public const ROLES = [self::ACCOUNTANT, self::ASSISTANT, self::DIRECTOR, self::PARALEGAL];

    public const LABELS = [
        self::ACCOUNTANT => 'Accountant (full access)',
        self::ASSISTANT => 'Accountant Assistant (prepares invoices & expenses)',
        self::DIRECTOR => 'Director (payments, approvals, dashboard)',
        self::PARALEGAL => 'Paralegal (view + requests)',
    ];

    public static function permissions(): array
    {
        return [
            'platform.staff.access', 'platform.users.manage',
            'accounting.settings.manage',
            'accounting.masters.view', 'accounting.masters.create', 'accounting.masters.edit', 'accounting.masters.import',
            'accounting.invoices.view', 'accounting.invoices.create', 'accounting.invoices.edit', 'accounting.invoices.finalise', 'accounting.invoices.export',
            'accounting.tax.request', 'accounting.tax.approve', 'accounting.tax.issue',
            'accounting.notes.view', 'accounting.notes.create',
            'accounting.expenses.view_all', 'accounting.expenses.view_paralegal', 'accounting.expenses.create', 'accounting.expenses.edit_all', 'accounting.expenses.pay',
            'accounting.expenses.view_own', 'accounting.expenses.edit_own',
            'payments.view', 'payments.mark_received',
            'requests.create', 'requests.review',
            'accounting.reports.view', 'accounting.documents.manage', 'accounting.payroll.manage',
        ];
    }

    public static function matrix(): array
    {
        return [
            self::ACCOUNTANT => self::permissions(),
            self::ASSISTANT => [
                'platform.staff.access',
                'accounting.masters.view', 'accounting.masters.create', 'accounting.masters.edit',
                'accounting.invoices.view', 'accounting.invoices.create', 'accounting.invoices.edit', 'accounting.invoices.finalise',
                'accounting.tax.request', 'accounting.tax.issue',
                'accounting.notes.view', 'accounting.notes.create',
                'accounting.expenses.view_all', 'accounting.expenses.create', 'accounting.expenses.edit_all',
                'accounting.documents.manage',
            ],
            self::DIRECTOR => [
                'platform.staff.access',
                'accounting.invoices.view', 'accounting.invoices.export',
                'accounting.tax.approve',
                'accounting.notes.view',
                'accounting.expenses.view_all', 'accounting.expenses.pay',
                'payments.view', 'payments.mark_received',
                'requests.review',
                'accounting.reports.view',
            ],
            self::PARALEGAL => [
                'platform.staff.access',
                'accounting.invoices.view',
                'accounting.expenses.view_paralegal',
                'payments.view',
                'requests.create',
            ],
        ];
    }

    public static function allows(?User $user, string $permission): bool
    {
        if (! $user?->is_active) {
            return false;
        }
        $module = explode('.', $permission)[0];
        if (array_key_exists($module, config('platform.modules')) && ! config('platform.modules.'.$module)) {
            return false;
        }
        foreach (self::matrix() as $role => $grants) {
            if (in_array($permission, $grants, true) && $user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    public static function role(?User $user): ?string
    {
        foreach (self::ROLES as $role) {
            if ($user?->hasRole($role)) {
                return $role;
            }
        }

        return null;
    }

    public static function is(?User $user, string ...$roles): bool
    {
        return in_array(self::role($user), $roles, true);
    }
}
