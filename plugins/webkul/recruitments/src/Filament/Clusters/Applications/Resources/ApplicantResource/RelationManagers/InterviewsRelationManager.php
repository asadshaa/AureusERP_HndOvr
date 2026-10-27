<?php

namespace Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource\RelationManagers;

use Filament\Actions\ActionGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Webkul\Recruitment\Filament\Schemas\InterviewComponents;
use Webkul\Recruitment\Models\Applicant;

/**
 * "Interviews" tab of an application (Stage 2.3): schedule, reschedule,
 * cancel and close interviews, and give or read feedback. Applications of
 * another member company are view-only (the actions need the active
 * company); InterviewService checks every action again.
 */
class InterviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'interviews';

    protected static ?string $title = 'Interviews';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-calendar-days';

    /** Actions decide for themselves (view pages are read-only by default in Filament). */
    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['assignments.user', 'job:id,name', 'applicant']))
            ->defaultSort('starts_at', 'desc')
            ->emptyStateHeading(__('No interviews yet'))
            ->emptyStateDescription(__('Schedule one: the candidate and the interviewers get a calendar invitation.'))
            ->columns(InterviewComponents::columns(withApplicant: false))
            ->headerActions([
                InterviewComponents::scheduleAction(fn (): Applicant => $this->applicant()),
            ])
            ->recordActions([
                ActionGroup::make(InterviewComponents::recordActions()),
            ]);
    }

    protected function applicant(): Applicant
    {
        /** @var Applicant $applicant */
        $applicant = $this->getOwnerRecord();

        return $applicant;
    }
}
