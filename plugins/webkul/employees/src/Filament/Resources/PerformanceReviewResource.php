<?php

namespace Webkul\Employee\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Filament\Appraisals\AppraisalTable;
use Webkul\Employee\Filament\Resources\PerformanceReviewResource\Pages\ManagePerformanceReviews;
use Webkul\Employee\Filament\Resources\PerformanceReviewResource\Pages\ViewAppraisal;
use Webkul\Employee\Models\PerformanceReview;
use Webkul\Employee\Services\Appraisals\AppraisalAccess;
use Webkul\Employee\Support\AppraisalKinds;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Enums\NavigationGroup;

/**
 * Every appraisal the signed-in user may see in the active company
 * (AppraisalAccess::visibleQuery()). In the menu for HR only; employees and
 * line managers use My appraisals / Team appraisals, which open the same
 * appraisal page. The self / manager review is filled in on that page; the
 * generic edit form is HR's correction tool.
 */
class PerformanceReviewResource extends Resource
{
    protected static ?string $model = PerformanceReview::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-star';

    protected static ?int $navigationSort = 12;

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Employee;
    }

    public static function getNavigationLabel(): string
    {
        return 'Performance Reviews';
    }

    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();
        $companyId = $user instanceof User ? CurrentCompany::id($user) : null;

        return $companyId !== null && app(AppraisalAccess::class)->canViewCompany($user, (int) $companyId);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('cycle_id')->relationship('cycle', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('company_id', CurrentCompany::id() ?? 0))->disabled()->dehydrated(),
            Select::make('employee_id')->relationship('employee', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('company_id', CurrentCompany::id() ?? 0))->disabled()->dehydrated(),
            Select::make('reviewer_id')->relationship('reviewer', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('company_id', CurrentCompany::id() ?? 0))->searchable()->preload(),
            TextInput::make('self_rating')->numeric()->minValue(0)->maxValue(5),
            TextInput::make('manager_rating')->numeric()->minValue(0)->maxValue(5),
            Select::make('status')->options(AppraisalKinds::statuses())->required(),
            KeyValue::make('competency_ratings')->columnSpanFull(),
            Textarea::make('self_comments')->columnSpanFull(),
            Textarea::make('manager_comments')->columnSpanFull(),
            Textarea::make('improvement_plan')->columnSpanFull(),
            Textarea::make('promotion_recommendation')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        $user = Auth::user();

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['cycle', 'employee.department', 'employee.parent', 'reviewer.user']))
            ->columns($user instanceof User ? AppraisalTable::columns($user, withEmployee: true, withReviewer: true, withCycle: true) : [
                TextColumn::make('employee.name'),
            ])
            ->filters([
                SelectFilter::make('status')->options(AppraisalKinds::statuses()),
                SelectFilter::make('cycle_id')->label('Cycle')->relationship('cycle', 'name', fn (Builder $query): Builder => $query->where('company_id', CurrentCompany::id() ?? 0)),
            ])
            ->recordUrl(fn (PerformanceReview $record): string => static::getUrl('view', ['record' => $record]))
            ->recordActions([
                // The self / manager review is filled in on the appraisal page.
                Action::make('submit_self_review')
                    ->label('Self review')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary')
                    ->visible(fn (PerformanceReview $record): bool => $user instanceof User && app(AppraisalAccess::class)->canEditSelfReview($user, $record))
                    ->url(fn (PerformanceReview $record): string => static::getUrl('view', ['record' => $record])),
                Action::make('complete_manager_review')
                    ->label('Manager review')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (PerformanceReview $record): bool => $user instanceof User && app(AppraisalAccess::class)->canEditManagerReview($user, $record))
                    ->url(fn (PerformanceReview $record): string => static::getUrl('view', ['record' => $record])),
                EditAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    /**
     * Own appraisals, the user's reports' (as reviewer or line manager), the
     * ones without a line manager for HR approvers, and the whole active
     * company for HR (company-wide scope plus hr_manage_performance or
     * hr_view_performance). A line manager's hr_manage_performance alone no
     * longer opens the whole company.
     */
    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return app(AppraisalAccess::class)->visibleQuery($user, CurrentCompany::id($user));
    }

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user !== null && (
            $user->can(HrPermissions::ManagePerformance)
            || $user->can(HrPermissions::ViewPerformance)
            || $user->employee !== null
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePerformanceReviews::route('/'),
            'view'  => ViewAppraisal::route('/{record}'),
        ];
    }
}
