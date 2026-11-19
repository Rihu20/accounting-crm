<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ExpenseResource\BankFormA2;
use App\Models\Expense;
use App\Models\RemittanceBank;
use App\Models\RemittanceForm;
use App\Services\Money;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/** Bank Form A2: our saved bank accounts, and every foreign-currency vendor bill with its form. */
class RemittanceForms extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\UnitEnum|null $navigationGroup = 'Expenses';

    protected static ?string $navigationLabel = 'Bank Form A2';

    protected static ?int $navigationSort = 3;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $title = 'Bank Form A2';

    protected static ?string $slug = 'bank-form-a2';

    protected string $view = 'filament.pages.remittance-forms';

    public static function canAccess(): bool
    {
        return (bool) (auth()->user()?->allowed('accounting.expenses.pay') || auth()->user()?->allowed('accounting.expenses.edit_all'));
    }

    /** @return Collection<int, RemittanceBank> */
    public function banks(): Collection
    {
        return RemittanceBank::where('is_active', true)->orderBy('bank_name')->orderBy('currency')->get();
    }

    /** @return Collection<int, RemittanceForm> */
    public function preparedForms(): Collection
    {
        return RemittanceForm::with(['expense', 'preparer', 'debitBank', 'chargesBank'])->latest('id')->limit(10)->get();
    }

    protected function getHeaderActions(): array
    {
        return [BankFormA2::newAction(), $this->addBankAction()->color('gray')];
    }

    public function addBankAction(): Action
    {
        return Action::make('addBank')->label('Add our bank account')->icon('heroicon-o-plus')
            ->modalHeading('Add our bank account')->modalDescription('Saved for every Form A2. Pick it as the account for the remittance or for the charges.')
            ->schema([Grid::make(2)->schema(BankFormA2::bankFields())])
            ->action(function (array $data): void {
                RemittanceBank::create($data + ['is_active' => true]);
                Notification::make()->title('Bank account saved')->success()->send();
            });
    }

    public function editBankAction(): Action
    {
        return Action::make('editBank')->label('Edit')->icon('heroicon-o-pencil-square')->link()->size('sm')
            ->modalHeading('Edit bank account')
            ->fillForm(fn (array $arguments) => RemittanceBank::findOrFail($arguments['bank'])->only(['bank_name', 'branch', 'account_no', 'currency', 'account_type', 'notes']))
            ->schema([Grid::make(2)->schema(BankFormA2::bankFields())])
            ->action(function (array $data, array $arguments): void {
                RemittanceBank::findOrFail($arguments['bank'])->update($data);
                Notification::make()->title('Bank account saved')->success()->send();
            });
    }

    public function removeBankAction(): Action
    {
        return Action::make('removeBank')->label('Remove')->icon('heroicon-o-trash')->color('danger')->link()->size('sm')
            ->requiresConfirmation()->modalHeading('Remove this bank account?')
            ->modalDescription('It will no longer be offered on new forms. Forms already prepared keep it.')
            ->action(function (array $arguments): void {
                RemittanceBank::findOrFail($arguments['bank'])->update(['is_active' => false]);
                Notification::make()->title('Bank account removed')->success()->send();
            });
    }

    public function table(Table $table): Table
    {
        return $table->query(Expense::query()->with('vendor')->where('expense_type', 'vendor')->where('currency', '!=', 'INR'))->columns([
            TextColumn::make('invoice_date')->label('Bill date')->date('d.m.Y')->sortable(),
            TextColumn::make('payee_name')->label('Beneficiary')->state(fn (Expense $record) => $record->payee_name ?: $record->vendor?->name)->searchable()->wrap()->weight('semibold'),
            TextColumn::make('vendor_invoice_no')->label('Invoice No')->searchable()->placeholder('-'),
            TextColumn::make('amount')->formatStateUsing(fn ($state, Expense $record) => Money::format((string) $state, $record->currency))->alignEnd()->sortable(),
            TextColumn::make('status')->badge()->color(fn ($state) => $state === 'Paid' ? 'success' : 'warning'),
            TextColumn::make('a2_prepared_on')->label('Form A2')->date('d.m.Y')->placeholder('Not prepared')->badge()->color(fn ($state) => $state ? 'success' : 'gray')->sortable(),
            TextColumn::make('form_status')->label('15CA/CB')->placeholder('-')->toggleable(),
        ])->filters([
            TernaryFilter::make('a2_prepared_on')->label('Form A2')->nullable()->trueLabel('Prepared')->falseLabel('Not prepared'),
            SelectFilter::make('status')->options(['Unpaid' => 'Unpaid', 'Paid' => 'Paid']),
            SelectFilter::make('currency')->options(fn () => collect(config('crm.expense_currencies'))->reject(fn ($c) => $c === 'INR')->mapWithKeys(fn ($c) => [$c => $c])->all()),
        ])->recordActions([BankFormA2::action()->button()->label('Prepare Form A2')->color('primary')])
            ->defaultSort('invoice_date', 'desc')->defaultPaginationPageOption(25)
            ->emptyStateHeading('No foreign-currency vendor bills yet')->emptyStateIcon('heroicon-o-building-library');
    }

    /** Shown above the table when the user has not saved any bank account yet. */
    public function bankHint(): ?string
    {
        return $this->banks()->isEmpty() ? 'Add the bank accounts you remit from and pay charges from. They stay saved for every form.' : null;
    }
}
