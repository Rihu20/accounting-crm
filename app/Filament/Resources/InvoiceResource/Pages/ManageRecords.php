<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceDocumentResource;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\ProformaForm;
use App\Models\Invoice;
use App\Services\ManualProformaService;
use App\Services\PlatformAccess;
use App\Services\PortalWorkflow;
use Filament\Actions\Action;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageRecords extends \Filament\Resources\Pages\ManageRecords
{
    protected static string $resource = InvoiceResource::class;

    public function getTabs(): array
    {
        $user = auth()->user();
        $proforma = fn (Builder $query): Builder => $query->where('doc_type', 'proforma');
        $tax = fn (Builder $query): Builder => $query->where('doc_type', 'tax');

        if ($user->isRole(PlatformAccess::PARALEGAL)) {
            return [
                'proforma' => Tab::make('Proforma Invoices')->icon('heroicon-o-document')->modifyQueryUsing($proforma),
                'tax' => Tab::make('Tax Invoices')->icon('heroicon-o-document-check')->modifyQueryUsing($tax),
                'awaiting' => Tab::make('Awaiting payment')->modifyQueryUsing(fn (Builder $query): Builder => $query->where('doc_type', 'proforma')->whereIn('status', ['Sent', 'Partially received'])),
            ];
        }

        $tabs = ['all' => Tab::make('All')];
        if ($user->allowed('accounting.invoices.create')) {
            $tabs['drafts'] = Tab::make('Drafts')->icon('heroicon-o-pencil')->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'Draft'))->badge(Invoice::where('status', 'Draft')->count() ?: null);
        }
        $tabs['proforma'] = Tab::make('Proforma')->modifyQueryUsing($proforma);
        if ($user->allowed('payments.view')) {
            $tabs['pending'] = Tab::make('Payment pending')->icon('heroicon-o-clock')->modifyQueryUsing(fn (Builder $query): Builder => $query->where('doc_type', 'proforma')->whereIn('status', ['Sent', 'Partially received']));
            $tabs['received'] = Tab::make('Payment received')->icon('heroicon-o-banknotes')->modifyQueryUsing(fn (Builder $query): Builder => $query->where('doc_type', 'proforma')->whereIn('status', ['Received', 'Converted']));
        }
        if (PortalWorkflow::canAuthorizeTax($user)) {
            $count = InvoiceResource::approvalsQuery(Invoice::query())->count();
            $tabs['approvals'] = Tab::make('Approvals')->icon('heroicon-o-shield-check')->modifyQueryUsing(fn (Builder $query): Builder => InvoiceResource::approvalsQuery($query))->badge($count ?: null)->badgeColor('danger');
        }
        if ($user->allowed('accounting.tax.issue')) {
            $ready = fn (Builder $query): Builder => $query->where('doc_type', 'proforma')->where('status', 'Received')->where('tax_authorization->status', 'Approved');
            $tabs['ready'] = Tab::make('Ready for tax invoice')->icon('heroicon-o-document-check')->modifyQueryUsing($ready)->badge($ready(Invoice::query())->count() ?: null)->badgeColor('success');
        }
        $tabs['tax'] = Tab::make('Tax Invoices')->modifyQueryUsing($tax);
        $tabs['indian_sale'] = Tab::make('Indian Sale')->modifyQueryUsing(fn (Builder $query): Builder => $query->where('doc_type', 'tax')->whereHas('client', fn (Builder $client) => $client->where('client_type', 'Indian')));

        return $tabs;
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return auth()->user()->isRole(PlatformAccess::PARALEGAL) ? 'proforma' : 'all';
    }

    protected function getHeaderActions(): array
    {
        if (! auth()->user()->allowed('accounting.invoices.create') && ! auth()->user()->allowed('accounting.invoices.export')) {
            return [];
        }

        $actions = [Action::make('export')->label('Export Excel')->icon('heroicon-o-arrow-down-tray')->color('gray')->url(url('/crm/payments/export'))->visible(fn () => auth()->user()->allowed('accounting.invoices.export')), InvoiceDocumentResource::uploadAction()->color('gray')->visible(fn () => auth()->user()->allowed('accounting.invoices.create')), Action::make('word')->label('From Word file')->icon('heroicon-o-document-arrow-up')->color('gray')->url('/admin/word-upload')->visible(fn () => auth()->user()->allowed('accounting.invoices.create')), Action::make('create')->label('New proforma')->icon('heroicon-o-plus')->visible(fn () => auth()->user()->allowed('accounting.invoices.create'))->modalWidth('5xl')->schema(ProformaForm::components())->action(fn (array $data) => app(ManualProformaService::class)->create(auth()->user(), $data))];

        return $actions;
    }
}
