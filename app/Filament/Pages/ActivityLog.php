<?php

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/** Who changed what and when. The CRM already records every change; this page only shows it. */
class ActivityLog extends Page implements HasTable
{
    use InteractsWithTable;

    /** Bookkeeping columns that change on every save and say nothing to the reader. */
    private const QUIET = ['updated_at', 'created_at', 'lock_version', 'remember_token', 'password'];

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $title = 'Activity log';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.pages.activity-log';

    public static function canAccess(): bool
    {
        return auth()->user()->allowed('accounting.reports.view');
    }

    public function table(Table $table): Table
    {
        $subjects = Activity::query()->whereNotNull('subject_type')->distinct()->pluck('subject_type')->mapWithKeys(fn ($type) => [$type => class_basename($type)])->sort()->all();

        return $table->query(Activity::query()->with('causer'))->columns([
            TextColumn::make('created_at')->label('When')->dateTime('d.m.Y H:i')->sortable(),
            TextColumn::make('causer.name')->label('Who')->placeholder('System')->searchable(),
            TextColumn::make('description')->label('What')->searchable()->wrap(),
            TextColumn::make('subject_type')->label('Record')->state(fn (Activity $record) => $record->subject_type ? class_basename($record->subject_type).' #'.$record->subject_id : null)->placeholder('-'),
            TextColumn::make('details')->label('Details')->state(fn (Activity $record) => self::details($record))->wrap()->limit(160)->placeholder('-'),
        ])->filters([
            SelectFilter::make('subject_type')->label('Record type')->options($subjects),
            SelectFilter::make('causer_id')->label('Who')->options(fn () => User::orderBy('name')->pluck('name', 'id')->all()),
            Filter::make('dates')->schema([DatePicker::make('from'), DatePicker::make('until')])->query(fn (Builder $query, array $data) => $query->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))),
        ])->defaultSort('id', 'desc')->defaultPaginationPageOption(25)->emptyStateHeading('Nothing recorded yet');
    }

    /** "status: Unpaid → Paid; paid_ref: SWIFT-1" for changes, or the extra notes kept with the entry. */
    public static function details(Activity $activity): ?string
    {
        $changes = collect($activity->attribute_changes ?? []);
        $new = collect($changes->get('attributes', []))->except(self::QUIET);
        $old = collect($changes->get('old', []));
        $show = fn ($v) => is_scalar($v) || $v === null ? (string) ($v ?? '—') : json_encode($v);
        $parts = $new->map(fn ($value, $key) => $old->has($key) ? $key.': '.$show($old->get($key)).' → '.$show($value) : $key.': '.$show($value));
        if ($activity->event === 'created') {
            $parts = $new->filter(fn ($v) => $v !== null && $v !== '')->keys()->take(8)->map(fn ($k) => $k.': '.$show($new->get($k)));
        }
        $extra = collect($activity->properties ?? [])->except(['attributes', 'old'])->map(fn ($v, $k) => $k.': '.$show($v));

        return $parts->merge($extra)->values()->implode('; ') ?: null;
    }
}
