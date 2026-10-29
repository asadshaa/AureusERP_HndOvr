<?php

namespace Webkul\Employee\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Webkul\Employee\Filament\Appraisals\AppraisalTable;
use Webkul\Employee\Filament\Resources\PerformanceReviewResource;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\PerformanceReview;
use Webkul\Employee\Services\Appraisals\AppraisalAccess;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Enums\NavigationGroup;

/**
 * My appraisals: the signed-in employee's own appraisals in the active
 * company: the self review to fill in, and the result once it is shared.
 */
class MyAppraisals extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-star';

    protected static ?string $slug = 'my-appraisals';

    protected static ?int $navigationSort = 25;

    protected string $view = 'employees::filament.pages.my-checklist-tasks';

    public static function canAccess(): bool
    {
        $user = CurrentCompany::user();
        $companyId = $user instanceof User ? CurrentCompany::id($user) : null;

        // A login has at most one employee record; the relation is usually loaded already.
        return $companyId !== null && $user->employee instanceof Employee && (int) $user->employee->company_id === (int) $companyId;
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Dashboard;
    }

    public static function getNavigationLabel(): string
    {
        return 'My appraisals';
    }

    public function getTitle(): string
    {
        return 'My appraisals';
    }

    public function getSubheading(): ?string
    {
        return 'Fill in your self review before the deadline. Your manager\'s review and your final rating appear here once they are shared with you.';
    }

    public function table(Table $table): Table
    {
        $user = $this->user();

        return $table
            ->query(fn (): Builder => app(AppraisalAccess::class)
                ->myQuery($user, (int) CurrentCompany::id($user))
                ->with(['cycle', 'employee', 'reviewer.user']))
            ->columns(AppraisalTable::columns($user, withEmployee: false, withReviewer: true, withCycle: true))
            ->recordUrl(fn (PerformanceReview $record): string => PerformanceReviewResource::getUrl('view', ['record' => $record]))
            ->recordActions(AppraisalTable::actions())
            ->defaultSort('id', 'desc')
            ->emptyStateIcon('heroicon-o-star')
            ->emptyStateHeading('No appraisals yet')
            ->emptyStateDescription('When HR starts an appraisal cycle, your self review appears here.');
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = CurrentCompany::user();

        return $user;
    }
}
