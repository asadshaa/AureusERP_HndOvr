<?php

namespace Webkul\Employee\Filament\Checklists;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use Webkul\Employee\Filament\Resources\EmployeeRequestResource;
use Webkul\Employee\Filament\Resources\EmployeeResource;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeChecklistTask;
use Webkul\Employee\Policies\EmployeeChecklistTaskPolicy;
use Webkul\Employee\Policies\EmployeeDocumentPolicy;
use Webkul\Employee\Services\Checklists\EmployeeChecklistService;
use Webkul\Employee\Support\ChecklistKinds;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

/**
 * Columns and row actions of checklist tasks, shared by the employee's
 * "Onboarding & exit" tab and the My tasks page. Every action calls
 * EmployeeChecklistService, which checks EmployeeChecklistTaskPolicy again.
 */
final class ChecklistTaskTable
{
    /** @return array<int, TextColumn> */
    public static function columns(bool $withEmployee): array
    {
        return array_values(array_filter([
            TextColumn::make('title')
                ->label('Task')
                ->weight('medium')
                ->wrap()
                ->searchable()
                ->description(fn (EmployeeChecklistTask $record): ?string => self::taskDescription($record)),
            $withEmployee
                ? TextColumn::make('employee.name')
                    ->label('Employee')
                    ->searchable()
                    ->description(fn (EmployeeChecklistTask $record): string => ChecklistKinds::typeLabel($record->checklist?->type).' · '.$record->checklist?->anchorLabel())
                : null,
            TextColumn::make('responsible_role')
                ->label('Who')
                ->formatStateUsing(fn (?string $state): string => ChecklistKinds::roleLabel($state))
                ->description(fn (EmployeeChecklistTask $record): string => $record->assignee?->name ?? 'HR team'),
            TextColumn::make('due_date')
                ->label('Due')
                ->sortable()
                ->formatStateUsing(fn (EmployeeChecklistTask $record): string => $record->dueLabel())
                ->color(fn (EmployeeChecklistTask $record): ?string => match (true) {
                    $record->isOverdue()                                                                                   => 'danger',
                    $record->isPending() && $record->due_date?->toDateString() === ChecklistKinds::today()->toDateString() => 'warning',
                    default                                                                                                => null,
                })
                ->placeholder('—'),
            TextColumn::make('status')
                ->label('Status')
                ->badge()
                ->formatStateUsing(fn (EmployeeChecklistTask $record): string => $record->scheduled_for !== null && $record->isPending()
                    ? 'Scheduled '.$record->scheduled_for->format('d M')
                    : (ChecklistKinds::taskStatuses()[$record->status] ?? ucfirst((string) $record->status)))
                ->color(fn (EmployeeChecklistTask $record): string => $record->isOverdue() ? 'danger' : ChecklistKinds::taskStatusColor($record->status))
                ->tooltip(fn (EmployeeChecklistTask $record): ?string => self::completionTooltip($record)),
        ]));
    }

    /** @return array<int, Action|ActionGroup> */
    public static function actions(bool $withEmployeeLink): array
    {
        return [
            Action::make('complete')
                ->label('Done')
                ->icon('heroicon-m-check')
                ->color('success')
                ->button()
                ->size('sm')
                ->visible(fn (EmployeeChecklistTask $record): bool => $record->isPending() && self::can('update', $record))
                ->modalHeading(fn (EmployeeChecklistTask $record): string => 'Mark "'.$record->title.'" done')
                ->modalSubmitActionLabel('Mark done')
                ->schema([
                    Textarea::make('note')->label('Note (optional)')->rows(2)->maxLength(2000),
                ])
                ->action(fn (EmployeeChecklistTask $record, array $data) => self::run(
                    fn () => app(EmployeeChecklistService::class)->complete($record, self::user(), $data['note'] ?? null),
                    'Task done',
                )),
            ActionGroup::make([
                Action::make('skip')
                    ->label('Skip')
                    ->icon('heroicon-o-forward')
                    ->visible(fn (EmployeeChecklistTask $record): bool => $record->isPending() && self::can('update', $record))
                    ->modalHeading(fn (EmployeeChecklistTask $record): string => 'Skip "'.$record->title.'"')
                    ->schema([
                        Textarea::make('note')->label('Why is it skipped?')->required()->rows(2)->maxLength(2000),
                    ])
                    ->action(fn (EmployeeChecklistTask $record, array $data) => self::run(
                        fn () => app(EmployeeChecklistService::class)->skip($record, self::user(), $data['note'] ?? null),
                        'Task skipped',
                    )),
                Action::make('upload_document')
                    ->label(fn (EmployeeChecklistTask $record): string => 'Upload '.($record->documentType?->name ?? 'document'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->visible(fn (EmployeeChecklistTask $record): bool => $record->isPending() && $record->document_type_id !== null
                        && $record->employee instanceof Employee && self::user() instanceof User
                        && app(EmployeeDocumentPolicy::class)->upload(self::user(), $record->employee, $record->documentType))
                    ->url(fn (EmployeeChecklistTask $record): string => EmployeeResource::getUrl('view', ['record' => $record->employee_id])),
                Action::make('schedule_user_disable')
                    ->label('Disable user on last working day')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->visible(fn (EmployeeChecklistTask $record): bool => $record->action_key === ChecklistKinds::ActionDisableUser
                        && $record->isPending() && $record->scheduled_for === null && $record->employee?->user_id !== null
                        && self::can('update', $record))
                    ->requiresConfirmation()
                    ->modalHeading('Disable the login on the last working day?')
                    ->modalDescription(fn (EmployeeChecklistTask $record): string => 'The user account of '.$record->employee?->name.' will be switched off in the evening of '
                        .($record->checklist?->last_working_day ?? $record->checklist?->anchor_date)?->format('l, d M Y')
                        .'. Until then they can keep working. You can cancel this until it runs.')
                    ->action(fn (EmployeeChecklistTask $record) => self::run(
                        fn () => app(EmployeeChecklistService::class)->scheduleUserDisable($record, self::user()),
                        'Scheduled for the last working day',
                    )),
                Action::make('unschedule_user_disable')
                    ->label('Cancel the scheduled disable')
                    ->icon('heroicon-o-lock-open')
                    ->visible(fn (EmployeeChecklistTask $record): bool => $record->action_key === ChecklistKinds::ActionDisableUser
                        && $record->isPending() && $record->scheduled_for !== null && self::can('update', $record))
                    ->requiresConfirmation()
                    ->action(fn (EmployeeChecklistTask $record) => self::run(
                        fn () => app(EmployeeChecklistService::class)->unscheduleUserDisable($record, self::user()),
                        'Scheduled disable cancelled',
                    )),
                Action::make('open_advances')
                    ->label('Open loans and advances')
                    ->icon('heroicon-o-banknotes')
                    ->visible(fn (EmployeeChecklistTask $record): bool => $record->action_key === ChecklistKinds::ActionOpenAdvances
                        && $record->employee instanceof Employee && self::can('manage', $record))
                    ->modalHeading(fn (EmployeeChecklistTask $record): string => 'Open loans and advances of '.$record->employee?->name)
                    ->modalContent(fn (EmployeeChecklistTask $record) => view('employees::filament.checklists.open-advances', [
                        'requests'    => app(EmployeeChecklistService::class)->openAdvanceRequests($record->employee),
                        'requestsUrl' => EmployeeRequestResource::canViewAny() ? EmployeeRequestResource::getUrl('index') : null,
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Action::make('hr_letters')
                    ->label('HR letter requests')
                    ->icon('heroicon-o-envelope-open')
                    ->visible(fn (EmployeeChecklistTask $record): bool => $record->action_key === ChecklistKinds::ActionExperienceLetter
                        && self::can('update', $record) && EmployeeRequestResource::canViewAny())
                    ->url(fn (): string => EmployeeRequestResource::getUrl('index')),
                Action::make('reassign')
                    ->label('Reassign')
                    ->icon('heroicon-o-user')
                    ->visible(fn (EmployeeChecklistTask $record): bool => $record->isPending() && self::can('manage', $record))
                    ->schema([
                        Select::make('assignee_id')
                            ->label('Assign to')
                            ->placeholder('HR team')
                            ->options(fn (EmployeeChecklistTask $record): array => CurrentCompany::scopeUsersOf(User::query(), (int) $record->company_id)
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable(),
                    ])
                    ->fillForm(fn (EmployeeChecklistTask $record): array => ['assignee_id' => $record->assignee_id])
                    ->action(fn (EmployeeChecklistTask $record, array $data) => self::run(
                        fn () => app(EmployeeChecklistService::class)->reassign($record, self::user(), filled($data['assignee_id'] ?? null) ? (int) $data['assignee_id'] : null),
                        'Task reassigned',
                    )),
                Action::make('reopen')
                    ->label('Reopen')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn (EmployeeChecklistTask $record): bool => in_array($record->status, [ChecklistKinds::TaskDone, ChecklistKinds::TaskSkipped], true)
                        && $record->checklist?->status !== ChecklistKinds::ChecklistCancelled
                        && self::can('manage', $record))
                    ->requiresConfirmation()
                    ->action(fn (EmployeeChecklistTask $record) => self::run(
                        fn () => app(EmployeeChecklistService::class)->reopen($record, self::user()),
                        'Task reopened',
                    )),
                Action::make('employee')
                    ->label('Open employee')
                    ->icon('heroicon-o-identification')
                    ->visible(fn (EmployeeChecklistTask $record): bool => $withEmployeeLink && $record->employee instanceof Employee && EmployeeResource::canView($record->employee))
                    ->url(fn (EmployeeChecklistTask $record): string => EmployeeResource::getUrl('view', ['record' => $record->employee_id])),
            ])->tooltip('More'),
        ];
    }

    protected static function taskDescription(EmployeeChecklistTask $record): ?string
    {
        $parts = [];

        if ($record->documentType !== null && $record->isPending()) {
            $parts[] = 'Completes when a '.$record->documentType->name.' is uploaded.';
        }

        if (filled($record->note)) {
            $parts[] = $record->note;
        } elseif (filled($record->description) && $record->isPending()) {
            $parts[] = $record->description;
        }

        return $parts === [] ? null : Str::limit(implode(' ', $parts), 220);
    }

    protected static function completionTooltip(EmployeeChecklistTask $record): ?string
    {
        if ($record->isPending() || $record->completed_at === null) {
            return null;
        }

        $by = match ($record->completion_source) {
            ChecklistKinds::SourceDocument => 'by the document upload',
            ChecklistKinds::SourceSystem   => 'automatically',
            default                        => $record->completer?->name ? 'by '.$record->completer->name : null,
        };

        return trim((ChecklistKinds::taskStatuses()[$record->status] ?? '').' '.$record->completed_at->timezone(ChecklistKinds::timezone())->format('d M Y H:i').' '.($by ?? ''));
    }

    protected static function can(string $ability, EmployeeChecklistTask $record): bool
    {
        $user = self::user();

        return $user instanceof User && app(EmployeeChecklistTaskPolicy::class)->{$ability}($user, $record);
    }

    protected static function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /** Run a service call and show its outcome; refusals and validation errors become a danger notification. */
    protected static function run(callable $callback, string $success): void
    {
        try {
            $callback();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Not done')->body(collect($e->errors())->flatten()->first())->send();

            return;
        } catch (Throwable $e) {
            if (! $e instanceof AuthorizationException) {
                report($e);
            }

            Notification::make()->danger()->title('Not done')->body($e instanceof AuthorizationException ? $e->getMessage() : 'Something went wrong; please try again.')->send();

            return;
        }

        Notification::make()->success()->title($success)->send();
    }
}
