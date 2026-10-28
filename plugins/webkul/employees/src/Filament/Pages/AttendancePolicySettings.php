<?php

namespace Webkul\Employee\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Webkul\Employee\Models\AttendancePolicy;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Enums\NavigationGroup;

/**
 * Attendance -> Attendance Policy: the active company's time-bank policy
 * (monthly grace allowance, daily extra-credit cap, carry-over). HR of that
 * company only (manage attendance + view all records); line managers, who
 * also hold manage attendance, cannot change it. Checked again on save.
 */
class AttendancePolicySettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'attendance-policy';

    protected string $view = 'employees::filament.pages.attendance-policy-settings';

    /** @var array{monthly_grace_minutes?: int, daily_extra_credit_cap_minutes?: int, carry_over?: bool} */
    public ?array $data = [];

    public static function canManage(?User $user, ?int $companyId): bool
    {
        return $user !== null
            && $companyId !== null
            && $user->can(HrPermissions::ManageAttendance)
            && $user->can(HrPermissions::ViewAllRecords)
            && CurrentCompany::canAccess($companyId, $user);
    }

    public static function canAccess(): bool
    {
        $user = CurrentCompany::user();

        return static::canManage($user, CurrentCompany::id($user));
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Attendance;
    }

    public static function getNavigationLabel(): string
    {
        return 'Attendance Policy';
    }

    public function getTitle(): string
    {
        return 'Attendance policy (grace balance)';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $policy = AttendancePolicy::forCompany((int) CurrentCompany::id());

        $this->form->fill([
            'monthly_grace_minutes'          => (int) $policy->monthly_grace_minutes,
            'daily_extra_credit_cap_minutes' => (int) $policy->daily_extra_credit_cap_minutes,
            'carry_over'                     => (bool) $policy->carry_over,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Grace balance')
                ->description('Each employee starts the month with the allowance. Extra time before 10:00 / after 18:00 (capped per day) is added; late arrival, leaving early and time away without approval are deducted. A negative balance is the month\'s shortfall (Delta / Penalty).')
                ->schema([
                    TextInput::make('monthly_grace_minutes')
                        ->label('Monthly grace allowance (minutes)')
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->maxValue(1440)
                        ->required(),
                    TextInput::make('daily_extra_credit_cap_minutes')
                        ->label('Daily extra-credit cap (minutes)')
                        ->helperText('Most minutes worked outside 10:00-18:00 that count per day.')
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->maxValue(480)
                        ->required(),
                    Toggle::make('carry_over')
                        ->label('Carry unused earned extra over to the next month')
                        ->helperText('Off: the balance resets every month. On: the previous month\'s unused earned extra (not the allowance) opens the next month.'),
                ])
                ->columns(['default' => 1, 'md' => 3]),
        ]);
    }

    /** Saves the policy of the active company only; refused for anyone but that company's HR. */
    public function save(): void
    {
        $user = CurrentCompany::user();
        $companyId = CurrentCompany::id($user);
        abort_unless(static::canManage($user, $companyId), 403);

        $state = $this->form->getState();

        AttendancePolicy::query()->updateOrCreate(
            ['company_id' => $companyId],
            [
                'monthly_grace_minutes'          => max(0, min(1440, (int) $state['monthly_grace_minutes'])),
                'daily_extra_credit_cap_minutes' => max(0, min(480, (int) $state['daily_extra_credit_cap_minutes'])),
                'carry_over'                     => (bool) ($state['carry_over'] ?? false),
                'updated_by'                     => $user->id,
            ],
        );

        Notification::make()->success()->title('Attendance policy saved.')->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->action(fn () => $this->save()),
        ];
    }
}
