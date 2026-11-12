<?php

namespace Webkul\Employee\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use RuntimeException;
use Webkul\Employee\Models\ReportingChange;
use Webkul\Employee\Services\Reporting\ReportingLineService;
use Webkul\Employee\Support\ReportingPurposes;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Enums\NavigationGroup;
use Webkul\Support\Filament\Actions\HelpAction;

/**
 * Employees -> Reporting history: every change of a line manager or of a
 * manager for one purpose, in the active company. Changes HR made to her own
 * line wait here for another HR person or an admin to approve or reject them.
 */
class ReportingHistory extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'employees/reporting-history';

    protected string $view = 'employees::filament.pages.reporting-history';

    public static function canAccess(): bool
    {
        return app(ReportingLineService::class)->canManage(CurrentCompany::user());
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Employee;
    }

    public static function getNavigationLabel(): string
    {
        return 'Reporting history';
    }

    public function getTitle(): string
    {
        return 'Reporting history';
    }

    public static function getNavigationBadge(): ?string
    {
        $user = CurrentCompany::user();
        $companyId = CurrentCompany::id($user);

        if (! $user instanceof User || $companyId === null || ! app(ReportingLineService::class)->canManage($user, $companyId)) {
            return null;
        }

        $count = ReportingChange::query()
            ->where('company_id', $companyId)
            ->where('status', ReportingChange::Pending)
            ->where('requested_by', '!=', $user->id)
            ->whereDoesntHave('employee', fn (Builder $employee) => $employee->where('user_id', $user->id))
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('reporting-lines'),
            Action::make('back')
                ->label('Reporting lines')
                ->icon('heroicon-o-arrows-right-left')
                ->color('gray')
                ->url(fn (): string => ReportingLines::getUrl()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => app(ReportingLineService::class)->historyQuery((int) (CurrentCompany::id() ?? 0)))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M Y, H:i')->sortable(),
                TextColumn::make('employee.name')->label('Person')->weight('medium')->searchable(),
                TextColumn::make('purpose')
                    ->label('What')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? 'Line manager' : ReportingPurposes::label($state)),
                TextColumn::make('oldManager.name')->label('From')->placeholder('Line manager / none')->formatStateUsing(fn (?string $state, ReportingChange $record): string => $state ?? ($record->purpose === null ? '—' : 'Line manager')),
                TextColumn::make('newManager.name')->label('To')->formatStateUsing(fn (?string $state, ReportingChange $record): string => $state ?? ($record->purpose === null ? '—' : 'Line manager')),
                TextColumn::make('requester.name')->label('Changed by')->placeholder('—'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        ReportingChange::Applied  => 'success',
                        ReportingChange::Pending  => 'warning',
                        ReportingChange::Rejected => 'danger',
                        default                   => 'gray',
                    }),
                TextColumn::make('reason')->label('Reason')->placeholder('—')->wrap()->limit(80),
                TextColumn::make('decider.name')->label('Decided by')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(self::statusLabels()),
                SelectFilter::make('purpose')
                    ->label('What changed')
                    ->options(['line' => 'Line manager'] + ReportingPurposes::labels())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        return match (true) {
                            blank($value)     => $query,
                            $value === 'line' => $query->whereNull('purpose'),
                            default           => $query->where('purpose', $value),
                        };
                    }),
            ])
            ->searchPlaceholder('Search by person')
            ->recordActions([
                $this->decisionAction(true),
                $this->decisionAction(false),
            ])
            ->emptyStateHeading('No changes yet')
            ->emptyStateDescription('Changes to who reports to whom will be listed here.');
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            ReportingChange::Applied  => 'Applied',
            ReportingChange::Pending  => 'Waiting for approval',
            ReportingChange::Rejected => 'Rejected',
        ];
    }

    /** Whether the signed-in user may approve or reject this waiting change (the service checks again). */
    protected function mayDecide(ReportingChange $change): bool
    {
        $user = CurrentCompany::user();

        return $user instanceof User
            && $change->status === ReportingChange::Pending
            && (int) $change->company_id === (int) CurrentCompany::id($user)
            && app(ReportingLineService::class)->canManage($user, (int) $change->company_id)
            && (int) $change->requested_by !== (int) $user->id
            && (int) ($change->employee?->user_id ?? 0) !== (int) $user->id;
    }

    protected function decisionAction(bool $approve): Action
    {
        $action = Action::make($approve ? 'approve' : 'reject')
            ->label($approve ? 'Approve' : 'Reject')
            ->icon($approve ? 'heroicon-o-check' : 'heroicon-o-x-mark')
            ->color($approve ? 'success' : 'danger')
            ->visible(fn (ReportingChange $record): bool => $this->mayDecide($record));

        if ($approve) {
            $action->requiresConfirmation()
                ->modalHeading('Approve this change?')
                ->modalDescription('The new reporting line takes effect at once and waiting requests move to the new approver.');
        } else {
            $action->modalHeading('Reject this change?')
                ->schema([Textarea::make('note')->label('Why is it rejected?')->required()->rows(2)->maxLength(500)]);
        }

        return $action->action(function (Action $action, ReportingChange $record, array $data) use ($approve): void {
            $user = CurrentCompany::user();

            try {
                abort_unless($user instanceof User && (int) $record->company_id === (int) CurrentCompany::id($user), 403);

                // The list loads only id and name of the person; the service needs the whole record.
                $change = ReportingChange::query()->where('company_id', CurrentCompany::id($user))->findOrFail($record->getKey());

                app(ReportingLineService::class)->decide($user, $change, $approve, $data['note'] ?? null);
            } catch (RuntimeException|InvalidArgumentException $exception) {
                Notification::make()->danger()->title('Not decided')->body($exception->getMessage())->send();
                $action->halt();

                return;
            }

            Notification::make()->success()->title($approve ? 'Change approved' : 'Change rejected')->send();
        });
    }
}
