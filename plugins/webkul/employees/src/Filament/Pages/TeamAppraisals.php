<?php

namespace Webkul\Employee\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Webkul\Employee\Filament\Appraisals\AppraisalTable;
use Webkul\Employee\Filament\Resources\PerformanceReviewResource;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\PerformanceReview;
use Webkul\Employee\Services\Appraisals\AppraisalAccess;
use Webkul\Employee\Support\AppraisalKinds;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Enums\NavigationGroup;

/**
 * Team appraisals: the appraisals the signed-in line manager reviews (and
 * their direct reports'), plus, for HR, those without a line manager.
 * Manager reviews waiting for the user come first.
 */
class TeamAppraisals extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $slug = 'team-appraisals';

    protected static ?int $navigationSort = 26;

    protected string $view = 'employees::filament.pages.my-checklist-tasks';

    public static function canAccess(): bool
    {
        $user = CurrentCompany::user();
        $companyId = $user instanceof User ? CurrentCompany::id($user) : null;

        if ($companyId === null) {
            return false;
        }

        if (app(AppraisalAccess::class)->isFallbackHr($user, (int) $companyId)) {
            return true;
        }

        // A login has at most one employee record; the relation is usually loaded already.
        $employee = $user->employee;

        if (! $employee instanceof Employee || (int) $employee->company_id !== (int) $companyId) {
            return false;
        }

        // Has direct reports, or reviews someone: one query.
        return Employee::query()
            ->where('company_id', $companyId)
            ->where('parent_id', $employee->getKey())
            ->toBase()
            ->select('id')
            ->union(PerformanceReview::query()->where('company_id', $companyId)->where('reviewer_id', $employee->getKey())->toBase()->select('id'))
            ->limit(1)
            ->exists();
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Dashboard;
    }

    public static function getNavigationLabel(): string
    {
        return 'Team appraisals';
    }

    public function getTitle(): string
    {
        return 'Team appraisals';
    }

    public function getSubheading(): ?string
    {
        return 'Your reports\' appraisals. Open one to write the manager review once the self review is in.';
    }

    public function table(Table $table): Table
    {
        $user = $this->user();

        return $table
            ->query(fn (): Builder => app(AppraisalAccess::class)
                ->teamQuery($user, (int) CurrentCompany::id($user))
                ->with(['cycle', 'employee.department', 'employee.parent', 'reviewer.user', 'employee.user']))
            ->columns(AppraisalTable::columns($user, withEmployee: true, withReviewer: true, withCycle: true))
            ->filters([
                SelectFilter::make('status')
                    ->options(AppraisalKinds::statuses()),
                SelectFilter::make('cycle_id')
                    ->label('Cycle')
                    ->relationship('cycle', 'name', fn (Builder $query): Builder => $query->where('company_id', CurrentCompany::id() ?? 0)),
            ])
            ->recordUrl(fn (PerformanceReview $record): string => PerformanceReviewResource::getUrl('view', ['record' => $record]))
            ->recordActions(AppraisalTable::actions())
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw('case when status = ? then 0 when status = ? then 1 else 2 end', [AppraisalKinds::StatusManagerReview, AppraisalKinds::StatusSelfReview])
                ->orderByDesc('id'))
            ->paginated([25, 50, 100])
            ->emptyStateIcon('heroicon-o-user-group')
            ->emptyStateHeading('No team appraisals')
            ->emptyStateDescription('When HR starts an appraisal cycle, your reports\' appraisals appear here.');
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = CurrentCompany::user();

        return $user;
    }
}
