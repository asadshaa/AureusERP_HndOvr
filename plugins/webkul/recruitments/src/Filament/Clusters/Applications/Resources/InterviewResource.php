<?php

namespace Webkul\Recruitment\Filament\Clusters\Applications\Resources;

use Filament\Actions\ActionGroup;
use Filament\Resources\Resource;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Recruitment\Enums\InterviewStatus;
use Webkul\Recruitment\Filament\Clusters\Applications;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\InterviewResource\Pages\ListInterviews;
use Webkul\Recruitment\Filament\Schemas\InterviewComponents;
use Webkul\Recruitment\Models\Interview;
use Webkul\Recruitment\Services\InterviewService;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

/**
 * Recruitment > Applications > Interviews: the active company's interviews
 * (Stage 2.3). HR sees all of them; anyone else only the interviews they are
 * an interviewer on or organised (scoped in the query, not just hidden), and
 * gives their feedback here without needing rights on the applications.
 * Interviews are scheduled from an application's Interviews tab.
 */
class InterviewResource extends Resource
{
    protected static ?string $model = Interview::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $cluster = Applications::class;

    protected static ?int $navigationSort = 4;

    protected static bool $isGloballySearchable = false;

    public static function getModelLabel(): string
    {
        return __('Interview');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Interviews');
    }

    public static function getNavigationLabel(): string
    {
        return __('Interviews');
    }

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        $query = CurrentCompany::scope(parent::getEloquentQuery())->whereHas('applicant');

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return app(InterviewService::class)->seesAllInterviews($user)
            ? $query
            : $query->withParticipant($user);
    }

    /** How many of the signed-in user's interviews still need their feedback. */
    public static function getNavigationBadge(): ?string
    {
        $userId = Auth::id();

        if (! $userId || CurrentCompany::id() === null) {
            return null;
        }

        $count = static::getEloquentQuery()->awaitingFeedbackFrom((int) $userId)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Interviews waiting for your feedback');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['assignments.user', 'job:id,name', 'applicant.candidate']))
            ->defaultSort('starts_at', 'desc')
            ->emptyStateHeading(__('No interviews'))
            ->emptyStateDescription(__('Interviews are scheduled from an application (Interviews tab).'))
            ->columns(InterviewComponents::columns(withApplicant: true))
            ->filters([
                Filter::make('mine')
                    ->label(__('Mine (I am an interviewer)'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereHas('assignments', fn (Builder $assignments): Builder => $assignments->where('user_id', (int) Auth::id()))),
                Filter::make('upcoming')
                    ->label(__('Upcoming'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->upcoming()),
                Filter::make('needs_my_feedback')
                    ->label(__('Needs my feedback'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->awaitingFeedbackFrom((int) Auth::id())),
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->multiple()
                    ->options(InterviewStatus::options()),
            ])
            ->recordActions([
                ActionGroup::make(InterviewComponents::recordActions()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInterviews::route('/'),
        ];
    }
}
