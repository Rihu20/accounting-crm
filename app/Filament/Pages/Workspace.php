<?php

namespace App\Filament\Pages;

use App\Filament\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\DashboardService;
use App\Services\InvoiceService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;

class Workspace extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $title = 'Dashboard';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = -2;

    protected string $view = 'filament.pages.workspace';

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->allowed('platform.staff.access');
    }

    public function getHeading(): string
    {
        return '';
    }

    /** The payment reminder for one proforma on the "Follow up today" card: copy the email, then record that it was sent. */
    public function reminderAction(): Action
    {
        return Action::make('reminder')->label('Reminder')->icon('heroicon-o-envelope')->button()->outlined()->size('sm')
            ->visible(fn () => auth()->user()->allowed('payments.view'))
            ->modalHeading(fn (array $arguments) => 'Reminder for '.Invoice::find($arguments['invoice'] ?? 0)?->number)->modalWidth('2xl')
            ->modalContent(function (array $arguments) {
                $invoice = Invoice::with('client')->findOrFail($arguments['invoice']);

                return view('filament.parts.reminder', ['invoice' => $invoice, 'text' => InvoiceResource::reminderText($invoice)]);
            })
            ->modalSubmitActionLabel('Mark reminder sent')
            ->action(function (array $arguments): void {
                app(InvoiceService::class)->markReminderSent(Invoice::findOrFail($arguments['invoice']), auth()->user());
                Notification::make()->title('Reminder recorded')->body('The next one is due after the repeat days in Settings.')->success()->send();
            });
    }

    protected function getViewData(): array
    {
        return ['d' => DashboardService::for(auth()->user()), 'user' => auth()->user()];
    }
}
