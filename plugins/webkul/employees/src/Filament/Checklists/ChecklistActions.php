<?php

namespace Webkul\Employee\Filament\Checklists;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Webkul\Employee\Models\ChecklistTemplate;
use Webkul\Employee\Models\DepartureReason;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Policies\EmployeeChecklistTaskPolicy;
use Webkul\Employee\Services\Checklists\EmployeeChecklistService;
use Webkul\Employee\Support\ChecklistKinds;
use Webkul\Security\Models\User;

/**
 * HR's "Start onboarding", "Start exit", "Undo exit" and "Cancel
 * onboarding" buttons on the employee page. $employee resolves the record
 * of the page they sit on; the service checks HR rights again.
 */
final class ChecklistActions
{
    /**
     * @param  Closure(): Employee  $employee
     * @return array<int, Action>
     */
    public static function make(Closure $employee): array
    {
        return [
            self::startOnboarding($employee),
            self::startExit($employee),
            self::undoExit($employee),
            self::cancelOnboarding($employee),
        ];
    }

    /** @param  Closure(): Employee  $employee */
    public static function startOnboarding(Closure $employee): Action
    {
        return Action::make('start_onboarding')
            ->label('Start onboarding')
            ->icon('heroicon-o-user-plus')
            ->color('gray')
            ->visible(fn (): bool => self::isHr($employee()) && ! self::service()->openChecklist($employee(), ChecklistKinds::Onboarding))
            ->modalHeading('Start onboarding')
            ->modalDescription(fn (): string => 'Creates the onboarding tasks of '.$employee()->name.', dated from the joining date'
                .($employee()->joining_date ? ' ('.$employee()->joining_date->format('d M Y').')' : ' (none set: today)')
                .', and tells each person responsible.')
            ->schema(fn (): array => self::templateField($employee(), ChecklistKinds::Onboarding))
            ->action(function (array $data) use ($employee): void {
                static::run(fn () => static::service()->startOnboarding($employee(), static::user(), filled($data['template_id'] ?? null) ? (int) $data['template_id'] : null), 'Onboarding started');
            });
    }

    /** @param  Closure(): Employee  $employee */
    public static function startExit(Closure $employee): Action
    {
        return Action::make('start_exit')
            ->label('Start exit')
            ->icon('heroicon-o-arrow-right-start-on-rectangle')
            ->color('danger')
            ->visible(fn (): bool => self::isHr($employee()) && ! self::service()->openChecklist($employee(), ChecklistKinds::Offboarding))
            ->modalHeading(fn (): string => 'Start exit of '.$employee()->name)
            ->modalDescription('Records the leaving details on the employee and starts the exit checklist. The login stays active: the checklist offers "Disable user on last working day".')
            ->modalSubmitActionLabel('Start exit')
            ->fillForm(fn (): array => [
                'notice_period_days' => 30,
                'last_working_day'   => ChecklistKinds::today()->addDays(30)->toDateString(),
                'leaving_date'       => ChecklistKinds::today()->addDays(30)->toDateString(),
            ])
            ->schema(fn (): array => [
                TextInput::make('notice_period_days')
                    ->label('Notice period (days)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(365)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        if (is_numeric($state)) {
                            $day = ChecklistKinds::today()->addDays((int) $state)->toDateString();
                            $set('last_working_day', $day);
                            $set('leaving_date', $day);
                        }
                    }),
                DatePicker::make('last_working_day')
                    ->label('Last working day')
                    ->required()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn (Set $set, Get $get, ?string $state) => $state && (! $get('leaving_date') || $get('leaving_date') < $state) ? $set('leaving_date', $state) : null),
                DatePicker::make('leaving_date')
                    ->label('Leaving date')
                    ->helperText('Employment ends on this day. The exit can be undone until then.')
                    ->required()
                    ->native(false)
                    ->afterOrEqual('last_working_day'),
                Select::make('departure_reason_id')
                    ->label('Departure reason')
                    ->options(fn (): array => DepartureReason::query()->orderBy('sort')->pluck('name', 'id')->all())
                    ->searchable(),
                Textarea::make('departure_description')
                    ->label('Details')
                    ->rows(2)
                    ->maxLength(2000),
                ...self::templateField($employee(), ChecklistKinds::Offboarding),
            ])
            ->action(function (array $data) use ($employee): void {
                static::run(fn () => static::service()->startExit($employee(), static::user(), $data), 'Exit started');
            });
    }

    /** @param  Closure(): Employee  $employee */
    public static function undoExit(Closure $employee): Action
    {
        return Action::make('undo_exit')
            ->label('Undo exit')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(function () use ($employee): bool {
                $checklist = static::service()->openChecklist($employee(), ChecklistKinds::Offboarding);

                return $checklist !== null && static::isHr($employee()) && static::service()->canUndoExit($checklist);
            })
            ->modalHeading('Undo exit')
            ->modalDescription('The resignation is withdrawn or the person stays: the exit checklist is cancelled and the previous leaving details come back. Possible until the leaving date.')
            ->schema([
                Textarea::make('reason')->label('Reason')->rows(2)->maxLength(255),
            ])
            ->action(function (array $data) use ($employee): void {
                $checklist = static::service()->openChecklist($employee(), ChecklistKinds::Offboarding);

                if ($checklist !== null) {
                    static::run(fn () => static::service()->cancel($checklist, static::user(), $data['reason'] ?? null), 'Exit undone');
                }
            });
    }

    /** @param  Closure(): Employee  $employee */
    public static function cancelOnboarding(Closure $employee): Action
    {
        return Action::make('cancel_onboarding')
            ->label('Cancel onboarding')
            ->icon('heroicon-o-x-mark')
            ->color('gray')
            ->visible(fn (): bool => self::isHr($employee()) && self::service()->openChecklist($employee(), ChecklistKinds::Onboarding) !== null)
            ->requiresConfirmation()
            ->schema([
                Textarea::make('reason')->label('Reason')->rows(2)->maxLength(255),
            ])
            ->action(function (array $data) use ($employee): void {
                $checklist = static::service()->openChecklist($employee(), ChecklistKinds::Onboarding);

                if ($checklist !== null) {
                    static::run(fn () => static::service()->cancel($checklist, static::user(), $data['reason'] ?? null), 'Onboarding cancelled');
                }
            });
    }

    /** A template picker, only when the company has more than one active template of the type. */
    protected static function templateField(Employee $employee, string $type): array
    {
        $templates = ChecklistTemplate::query()
            ->where('company_id', $employee->company_id)
            ->where('type', $type)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->pluck('name', 'id');

        if ($templates->count() < 2) {
            return [];
        }

        return [
            Select::make('template_id')
                ->label('Checklist template')
                ->options($templates->all())
                ->default($templates->keys()->first())
                ->required(),
        ];
    }

    protected static function isHr(Employee $employee): bool
    {
        $user = self::user();

        return $user instanceof User && app(EmployeeChecklistTaskPolicy::class)->manageEmployee($user, $employee);
    }

    protected static function service(): EmployeeChecklistService
    {
        return app(EmployeeChecklistService::class);
    }

    protected static function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    protected static function run(callable $callback, string $success): void
    {
        try {
            $callback();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Not done')->body(collect($e->errors())->flatten()->first())->send();

            return;
        } catch (AuthorizationException $e) {
            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($success)->send();
    }
}
