<?php

namespace Webkul\Employee\Filament\Pages;

use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use InvalidArgumentException;
use RuntimeException;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\ReportingChange;
use Webkul\Employee\Services\Reporting\ReportingLineService;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Enums\NavigationGroup;
use Webkul\Support\Models\Company;

/**
 * Employees -> Team board: the line-manager tree as cards. HR drags a person
 * (their whole team comes along) onto a new manager, or picks "Move" and taps
 * the new manager on a touch screen. A person who manages several companies
 * switches company at the top without leaving the page. Every move goes
 * through ReportingLineService, which re-checks permission, company, loops
 * and logins, so the page adds no rules of its own.
 */
class TeamBoard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'employees/team-board';

    protected string $view = 'employees::filament.pages.team-board';

    public ?int $companyId = null;

    public static function canAccess(): bool
    {
        return static::manageableCompanies() !== [];
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Employee;
    }

    public static function getNavigationLabel(): string
    {
        return 'Team board';
    }

    public function getTitle(): string
    {
        return 'Team board';
    }

    public function getSubheading(): ?string
    {
        return 'Drag a person onto their new manager. Their team moves with them.';
    }

    /** @return array<int, string> company id => name, only companies this user may edit reporting lines in */
    public static function manageableCompanies(): array
    {
        $user = CurrentCompany::user();
        if (! $user instanceof User) {
            return [];
        }

        $service = app(ReportingLineService::class);

        return Company::query()
            ->whereIn('id', CurrentCompany::membershipIds($user))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->filter(fn (string $name, int|string $id): bool => $service->canManage($user, (int) $id))
            ->all();
    }

    public function mount(): void
    {
        $companies = static::manageableCompanies();
        $current = CurrentCompany::id();

        $this->companyId = isset($companies[$current]) ? $current : (array_key_first($companies) ?: null);
    }

    public function selectCompany(int $companyId): void
    {
        abort_unless(isset(static::manageableCompanies()[$companyId]), 403);

        $this->companyId = $companyId;
    }

    /** Moves one person under a new line manager (null: top of the company). */
    public function movePerson(int $employeeId, ?int $managerId): void
    {
        $user = CurrentCompany::user();
        abort_unless($user instanceof User && $this->companyId !== null && isset(static::manageableCompanies()[$this->companyId]), 403);

        $employee = Employee::query()->where('company_id', $this->companyId)->where('is_active', true)->find($employeeId);
        if (! $employee) {
            Notification::make()->danger()->title('Not moved')->body('That person could not be found in this company.')->send();

            return;
        }

        if ($managerId !== null && ! Employee::query()->where('company_id', $this->companyId)->where('is_active', true)->whereKey($managerId)->exists()) {
            Notification::make()->danger()->title('Not moved')->body('That manager is not in this company.')->send();

            return;
        }

        try {
            $change = app(ReportingLineService::class)->change($user, $employee, null, $managerId, 'Moved on the team board');
        } catch (RuntimeException|InvalidArgumentException $exception) {
            Notification::make()->danger()->title('Not moved')->body($exception->getMessage())->send();

            return;
        }

        if ($change->status === ReportingChange::Pending) {
            Notification::make()->info()->title('Sent for approval')->body('A change to your own line needs another HR person or an admin to approve it.')->send();

            return;
        }

        $to = $managerId !== null ? (string) Employee::query()->whereKey($managerId)->value('name') : 'the top of the company';
        Notification::make()->success()->title($employee->name.' now reports to '.$to)->send();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $companies = static::manageableCompanies();

        if ($this->companyId === null || ! isset($companies[$this->companyId])) {
            return ['companies' => $companies, 'people' => [], 'children' => [], 'roots' => [], 'parents' => (object) []];
        }

        $people = Employee::query()
            ->where('company_id', $this->companyId)
            ->where('is_active', true)
            ->with('department:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'job_title', 'parent_id', 'department_id']);

        $ids = $people->pluck('id')->flip();
        $children = [];
        $roots = [];

        foreach ($people as $person) {
            // A manager outside the active list (left the company) leaves the person at the top.
            if ($person->parent_id !== null && $ids->has($person->parent_id)) {
                $children[$person->parent_id][] = $person->id;
            } else {
                $roots[] = $person->id;
            }
        }

        // People who lead a team come first, so the real structure shows before the loose names.
        usort($roots, fn (int $a, int $b): int => [isset($children[$b]) ? 1 : 0, $a] <=> [isset($children[$a]) ? 1 : 0, $b]);

        $byId = $people->keyBy('id');
        $parents = $people->mapWithKeys(fn (Employee $person): array => [
            $person->id => ($person->parent_id !== null && $ids->has($person->parent_id)) ? (int) $person->parent_id : null,
        ])->all();

        return [
            'companies' => $companies,
            'people'    => $byId,
            'children'  => $children,
            'roots'     => $roots,
            'parents'   => (object) $parents,
        ];
    }
}
