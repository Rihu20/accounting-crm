<?php

namespace App\Filament\Resources\ExpenseResource\Pages;

use App\Filament\Resources\ExpenseResource;
use App\Services\PortalWorkflow;
use Filament\Actions\CreateAction;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageRecords extends \Filament\Resources\Pages\ManageRecords
{
    protected static string $resource = ExpenseResource::class;

    public function getTabs(): array
    {
        $user = auth()->user();
        $group = fn (string $g) => fn (Builder $query) => $query->where('expense_group', $g);
        if (! $user->allowed('accounting.expenses.view_all')) {
            return [
                'foreign' => Tab::make('Law Firms')->icon('heroicon-o-building-library')->modifyQueryUsing($group('foreign')),
                'patent_office' => Tab::make('Patent Offices')->icon('heroicon-o-building-office-2')->modifyQueryUsing($group('patent_office')),
                'my_requests' => Tab::make('My requests')->modifyQueryUsing(fn (Builder $query): Builder => $query->where('payment_request->requested_by', $user->id)),
            ];
        }

        $tabs = ['all' => Tab::make('All expenses')];
        if (PortalWorkflow::canReviewRequests($user)) {
            $pending = fn (Builder $query) => $query->where('status', 'Unpaid')->where('payment_request->status', 'Pending');
            $tabs['approvals'] = Tab::make('Payment requests')->icon('heroicon-o-bell-alert')->modifyQueryUsing($pending)->badge($pending(\App\Models\Expense::query())->count() ?: null)->badgeColor('danger');
        }
        if ($user->allowed('payments.view') || $user->allowed('accounting.expenses.pay')) {
            $tabs['unpaid'] = Tab::make('To pay')->icon('heroicon-o-clock')->modifyQueryUsing(fn (Builder $query): Builder => $query->where('expense_type', 'vendor')->where('status', 'Unpaid'));
        }

        return $tabs + [
            'foreign' => Tab::make('Law Firms')->modifyQueryUsing($group('foreign')),
            'patent_office' => Tab::make('Patent Offices')->modifyQueryUsing($group('patent_office')),
            'indian' => Tab::make('Indian Purchase')->modifyQueryUsing($group('indian')),
            'indian_admin' => Tab::make('Indian: Admin')->modifyQueryUsing(fn (Builder $query) => $query->where('expense_group', 'indian')->where('category', 'Admin expense')),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New expense')->icon('heroicon-o-plus')->mutateDataUsing(function (array $data) {
            $data['paid_by_user_id'] = auth()->id();
            if (! auth()->user()->allowed('accounting.expenses.edit_all')) {
                $data['expense_type'] = 'owner';
                unset($data['vendor_id']);
            }$data['status'] = $data['expense_type'] === 'owner' ? 'Paid' : 'Unpaid';
            $data['reimbursement_status'] = 'Pending';

            return $data;
        })];
    }
}
