<?php

namespace Webkul\Employee\Filament\Appraisals;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Webkul\Employee\Filament\Resources\PerformanceReviewResource;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\PerformanceReview;
use Webkul\Employee\Services\Appraisals\AppraisalAccess;
use Webkul\Employee\Services\Appraisals\AppraisalScoring;
use Webkul\Employee\Services\PerformanceService;
use Webkul\Employee\Support\AppraisalKinds;
use Webkul\Security\Models\User;

/**
 * Columns and row actions shared by My appraisals, Team appraisals and the
 * HR cycle overview. Scores, bands and calibration are only written for the
 * rows the signed-in user may see them on (AppraisalAccess); every action
 * is re-checked by PerformanceService.
 */
final class AppraisalTable
{
    /** @return array<int, TextColumn> */
    public static function columns(User $user, bool $withEmployee, bool $withReviewer, bool $withCycle): array
    {
        $access = app(AppraisalAccess::class);

        return array_values(array_filter([
            $withCycle ? TextColumn::make('cycle.name')
                ->label('Cycle')
                ->description(fn (PerformanceReview $record): string => (string) $record->cycle?->periodLabel())
                ->searchable() : null,
            $withEmployee ? TextColumn::make('employee.name')
                ->label('Employee')
                ->description(fn (PerformanceReview $record): ?string => $record->employee?->department?->name)
                ->searchable()
                ->sortable() : null,
            $withReviewer ? TextColumn::make('reviewer_name')
                ->label('Reviewer')
                ->state(fn (PerformanceReview $record): string => $access->hasManager($record)
                    ? (string) $record->reviewer?->name
                    : 'HR'.($record->reviewer ? ' ('.$record->reviewer->name.' has no login)' : ' (no line manager)'))
                ->color(fn (PerformanceReview $record): ?string => $access->hasManager($record) ? null : 'warning') : null,
            TextColumn::make('status')
                ->badge()
                ->formatStateUsing(fn (?string $state): string => AppraisalKinds::statusLabel($state))
                ->color(fn (?string $state): string => AppraisalKinds::statusColor($state))
                ->description(fn (PerformanceReview $record): ?string => self::dueText($record)),
            TextColumn::make('self_rating')
                ->label('Self')
                ->state(fn (PerformanceReview $record): ?string => $access->canSeeSelfReview($user, $record) && $record->self_rating !== null
                    ? number_format((float) $record->self_rating, 2)
                    : null)
                ->placeholder('—'),
            TextColumn::make('final_rating')
                ->label('Final')
                ->state(fn (PerformanceReview $record): ?string => $access->canSeeManagerReview($user, $record) && $record->final_rating !== null
                    ? number_format((float) $record->final_rating, 2)
                    : null)
                ->placeholder('—')
                ->sortable(),
            TextColumn::make('final_band')
                ->label('Rating')
                ->badge()
                ->state(fn (PerformanceReview $record): ?string => $access->canSeeManagerReview($user, $record) ? $record->final_band : null)
                ->color(fn (PerformanceReview $record): string => AppraisalScoring::bandColor($record->cycle, $record->final_band))
                ->placeholder(fn (PerformanceReview $record): string => $record->isReviewed() ? 'Shared when HR closes the cycle' : '—'),
            TextColumn::make('calibrated')
                ->label('Calibrated')
                ->badge()
                ->color('warning')
                ->state(fn (PerformanceReview $record): ?string => $access->canSeeCalibration($user, $record) && $record->calibrated_at !== null
                    ? 'from '.number_format((float) $record->manager_rating, 2)
                    : null)
                ->toggleable(isToggledHiddenByDefault: ! $withReviewer),
            TextColumn::make('acknowledged_at')
                ->label('Acknowledged')
                ->date('j M Y')
                ->placeholder('—')
                ->toggleable(),
        ]));
    }

    /** @return array<int, Action> */
    public static function actions(): array
    {
        return [
            Action::make('open')
                ->label('Open')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn (PerformanceReview $record): string => PerformanceReviewResource::getUrl('view', ['record' => $record])),
            Action::make('calibrate')
                ->label('Calibrate')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('warning')
                ->visible(fn (PerformanceReview $record): bool => app(AppraisalAccess::class)->canCalibrate(self::user(), $record))
                ->modalDescription(fn (PerformanceReview $record): string => 'Manager score '.number_format((float) $record->manager_rating, 2).'. The final rating and band change; the manager score is kept.')
                ->fillForm(fn (PerformanceReview $record): array => ['rating' => $record->final_rating, 'reason' => $record->calibration_reason])
                ->schema(self::calibrationSchema())
                ->action(fn (PerformanceReview $record, array $data) => self::run(
                    fn () => app(PerformanceService::class)->calibrate($record, self::user(), (float) $data['rating'], (string) $data['reason']),
                    'Final rating calibrated',
                )),
            Action::make('skip_self_review')
                ->label('Skip self review')
                ->icon('heroicon-o-forward')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('The appraisal moves on to the manager review without a self review. The reviewer is told.')
                ->visible(fn (PerformanceReview $record): bool => $record->status === AppraisalKinds::StatusSelfReview
                    && app(AppraisalAccess::class)->canManageReview(self::user(), $record))
                ->action(fn (PerformanceReview $record) => self::run(
                    fn () => app(PerformanceService::class)->skipSelfReview($record, self::user()),
                    'Moved to the manager review',
                )),
            Action::make('change_reviewer')
                ->label('Change reviewer')
                ->icon('heroicon-o-user-circle')
                ->color('gray')
                ->visible(fn (PerformanceReview $record): bool => app(AppraisalAccess::class)->canManageReview(self::user(), $record))
                ->fillForm(fn (PerformanceReview $record): array => ['reviewer_id' => $record->reviewer_id])
                ->schema([
                    Select::make('reviewer_id')
                        ->label('Reviewer')
                        ->helperText('Leave empty for HR to do the manager review.')
                        ->options(fn (PerformanceReview $record): array => Employee::query()
                            ->where('company_id', $record->company_id)
                            ->where('is_active', true)
                            ->whereKeyNot($record->employee_id)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable(),
                ])
                ->action(fn (PerformanceReview $record, array $data) => self::run(
                    fn () => app(PerformanceService::class)->reassignReviewer($record, self::user(), filled($data['reviewer_id'] ?? null) ? (int) $data['reviewer_id'] : null),
                    'Reviewer changed',
                )),
            Action::make('pdf')
                ->label('PDF')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->visible(fn (PerformanceReview $record): bool => app(AppraisalAccess::class)->canDownloadPdf(self::user(), $record))
                ->url(fn (PerformanceReview $record): string => route('employees.appraisals.pdf', ['review' => $record->getKey()]))
                ->openUrlInNewTab(),
        ];
    }

    /** @return array<int, TextInput|Textarea> */
    public static function calibrationSchema(): array
    {
        return [
            TextInput::make('rating')
                ->label('Final rating (1-5)')
                ->numeric()
                ->minValue(1)
                ->maxValue(5)
                ->step(0.01)
                ->required(),
            Textarea::make('reason')
                ->label('Reason')
                ->helperText('Kept with the appraisal; not shown to the employee.')
                ->rows(3)
                ->minLength(5)
                ->maxLength(2000)
                ->required(),
        ];
    }

    public static function dueText(PerformanceReview $record): ?string
    {
        $cycle = $record->cycle;

        $due = match ($record->status) {
            AppraisalKinds::StatusSelfReview    => $cycle?->self_review_due_on,
            AppraisalKinds::StatusManagerReview => $cycle?->manager_review_due_on,
            default                             => null,
        };

        if ($cycle?->isClosed() && ! $record->isReviewed()) {
            return 'Not finished';
        }

        if ($due === null || ! $cycle?->isActive()) {
            return null;
        }

        return ($due->toDateString() < AppraisalKinds::today()->toDateString() ? 'Overdue since ' : 'Due ').$due->format('j M');
    }

    /** Runs a service call and reports the outcome as a notification. */
    public static function run(callable $callback, string $success): void
    {
        try {
            $callback();
            Notification::make()->success()->title($success)->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();
        }
    }

    public static function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
