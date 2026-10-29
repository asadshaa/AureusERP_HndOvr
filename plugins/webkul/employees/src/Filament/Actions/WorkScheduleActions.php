<?php

namespace Webkul\Employee\Filament\Actions;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\WorkSchedule;
use Webkul\Employee\Services\Attendance\WorkScheduleService;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

/**
 * The work schedule form and the employee list's "Assign schedule" bulk
 * action (HR parity plan 2.2). Every save goes through WorkScheduleService /
 * the WorkSchedule model, which re-check HR scope, overlap and locked months.
 */
final class WorkScheduleActions
{
    /** @return array<int, Component|Field> */
    public static function formSchema(): array
    {
        $defaultWeekend = array_map('intval', (array) config('attendance.monthly_report.weekend_days', [6, 7]));

        return [
            TextInput::make('name')
                ->label('Name')
                ->placeholder('e.g. Ramadan timing, Warehouse shift')
                ->maxLength(120),
            TimePicker::make('start_time')
                ->label('Start')
                ->seconds(false)
                ->default((string) config('attendance.monthly_report.office_start', '10:00'))
                ->required(),
            TimePicker::make('end_time')
                ->label('End')
                ->seconds(false)
                ->default((string) config('attendance.monthly_report.office_end', '18:00'))
                ->required(),
            TextInput::make('required_minutes')
                ->label('Required minutes per day')
                ->integer()
                ->minValue(1)
                ->maxValue(1440)
                ->default((int) config('attendance.monthly_report.daily_required_minutes', 480))
                ->required(),
            TextInput::make('flexible_minutes')
                ->label('Flexible window (minutes)')
                ->helperText('The start may be this much later without counting as late; the end moves with it. 0 = fixed hours.')
                ->integer()
                ->minValue(0)
                ->maxValue(WorkSchedule::MaxFlexibleMinutes)
                ->default(0),
            CheckboxList::make('working_days')
                ->label('Working days')
                ->options(WorkSchedule::weekdayOptions())
                ->default(array_values(array_diff([1, 2, 3, 4, 5, 6, 7], $defaultWeekend)))
                ->columns(4)
                ->required()
                ->columnSpanFull(),
            DatePicker::make('effective_from')
                ->label('Effective from')
                ->native(false)
                ->required(),
            DatePicker::make('effective_to')
                ->label('Effective to')
                ->native(false)
                ->afterOrEqual('effective_from')
                ->helperText('Leave empty for no end date.'),
            TextInput::make('notes')
                ->label('Notes')
                ->maxLength(255)
                ->columnSpanFull(),
        ];
    }

    /** Employee list: the same schedule for every selected employee of the active company. */
    public static function bulkAssign(): BulkAction
    {
        return BulkAction::make('assign_work_schedule')
            ->label('Assign schedule')
            ->icon('heroicon-o-clock')
            ->color('primary')
            ->visible(fn (): bool => app(WorkScheduleService::class)->canManageAny(Auth::user() instanceof User ? Auth::user() : null))
            ->modalHeading('Assign a work schedule')
            ->modalDescription('Each selected employee of the active company gets this schedule from the start date. A schedule without an end date that started earlier is ended the day before; history is kept.')
            ->schema(self::formSchema())
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data): void {
                $user = Auth::user();
                if (! $user instanceof User) {
                    return;
                }

                $companyId = CurrentCompany::id($user);
                $employees = $records->filter(fn ($record): bool => $record instanceof Employee && (int) $record->company_id === (int) $companyId);
                $outside = $records->count() - $employees->count();

                $result = app(WorkScheduleService::class)->assignMany($user, $employees->values(), $data);
                $skipped = collect($result['skipped'])->map(fn (string $reason, string $name): string => "{$name}: {$reason}")->values();
                if ($outside > 0) {
                    $skipped->push("{$outside} employee(s) of another company");
                }

                Notification::make()
                    ->{$result['assigned'] > 0 ? 'success' : 'warning'}()
                    ->title($result['assigned'] > 0 ? "Schedule assigned to {$result['assigned']} employee(s)" : 'No schedule was assigned')
                    ->body($skipped->isNotEmpty() ? 'Skipped: '.$skipped->take(5)->implode('; ').($skipped->count() > 5 ? ' …' : '') : null)
                    ->send();
            });
    }

    /** Shows a refused save (overlap, locked month, scope) as a notification and keeps the modal open. */
    public static function guarded(Action $action, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Schedule not saved')->body((string) collect($e->errors())->flatten()->first())->send();
        } catch (AuthorizationException|\RuntimeException $e) {
            Notification::make()->danger()->title('Schedule not saved')->body($e->getMessage())->send();
        }

        $action->halt();

        return null;
    }
}
