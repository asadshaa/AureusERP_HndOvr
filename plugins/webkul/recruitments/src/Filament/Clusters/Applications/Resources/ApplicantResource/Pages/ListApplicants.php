<?php

namespace Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Webkul\Recruitment\Enums\RecruitmentState;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\CandidateResource;
use Webkul\Recruitment\Filament\Widgets\JobPositionStatsWidget;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\JobPosting;
use Webkul\Recruitment\Services\CandidateApplicationService;
use Webkul\Recruitment\Support\RecruitmentCompanyScope;
use Webkul\TableViews\Filament\Components\PresetView;
use Webkul\TableViews\Filament\Concerns\HasTableViews;

class ListApplicants extends ListRecords
{
    use HasTableViews;

    protected static string $resource = ApplicantResource::class;

    public ?string $lastApplicationOutcome = null;

    public function getHeaderWidgets(): array
    {
        return [
            JobPositionStatsWidget::make(),
        ];
    }

    public function getPresetTableViews(): array
    {
        return [
            'my_applications' => PresetView::make(__('recruitments::filament/clusters/applications/resources/applicant/pages/list-applicant.tabs.my-applicants'))
                ->icon('heroicon-s-user-circle')
                ->favorite()
                ->modifyQueryUsing(function (Builder $query) {
                    return $query
                        ->where('recruiter_id', Auth::id());
                }),

            'un_assigned' => PresetView::make(__('recruitments::filament/clusters/applications/resources/applicant/pages/list-applicant.tabs.un-assigned'))
                ->icon('heroicon-s-user-minus')
                ->favorite()
                ->modifyQueryUsing(function (Builder $query) {
                    return $query
                        ->whereNull('recruiter_id');
                }),

            'in_progress' => PresetView::make(__('recruitments::filament/clusters/applications/resources/applicant/pages/list-applicant.tabs.in-progress'))
                ->icon('heroicon-s-arrow-path')
                ->favorite()
                ->modifyQueryUsing(function (Builder $query) {
                    return $query
                        ->whereNull('deleted_at')
                        ->where('is_active', true)
                        ->whereNull('refuse_reason_id')
                        ->whereNull('date_closed');
                }),

            'hired' => PresetView::make(__('recruitments::filament/clusters/applications/resources/applicant/pages/list-applicant.tabs.hired'))
                ->icon('heroicon-s-check-badge')
                ->modifyQueryUsing(function (Builder $query) {
                    return $query
                        ->whereNull('deleted_at')
                        ->where('is_active', true)
                        ->whereNotNull('date_closed');
                }),

            'refused' => PresetView::make(__('recruitments::filament/clusters/applications/resources/applicant/pages/list-applicant.tabs.refused'))
                ->icon('heroicon-s-no-symbol')
                ->modifyQueryUsing(function (Builder $query) {
                    return $query
                        ->whereNull('deleted_at')
                        ->where('is_active', true)
                        ->whereNotNull('refuse_reason_id');
                }),

            'archived' => PresetView::make(__('recruitments::filament/clusters/applications/resources/applicant/pages/list-applicant.tabs.archived'))
                ->icon('heroicon-s-archive-box')
                ->favorite()
                ->modifyQueryUsing(function (Builder $query) {
                    return $query
                        ->withoutGlobalScope(SoftDeletingScope::class)
                        ->where(function (Builder $subQuery) {
                            $subQuery
                                ->whereNotNull('deleted_at')
                                ->orWhere('is_active', false);
                        });
                }),

            'blocked' => PresetView::make(__('recruitments::filament/clusters/applications/resources/applicant/pages/list-applicant.tabs.blocked'))
                ->icon('heroicon-s-shield-exclamation')
                ->modifyQueryUsing(function (Builder $query) {
                    return $query
                        ->where(function (Builder $subQuery) {
                            $subQuery
                                ->where('is_active', false)
                                ->orWhere('state', RecruitmentState::BLOCKED->value);
                        });
                }),

            'directly_available' => PresetView::make(__('recruitments::filament/clusters/applications/resources/applicant/pages/list-applicant.tabs.directly-available'))
                ->icon('heroicon-s-clock')
                ->modifyQueryUsing(function (Builder $query) {
                    return $query
                        ->join('recruitments_candidates as candidates', 'recruitments_applicants.candidate_id', '=', 'candidates.id')
                        ->where(function (Builder $subQuery) {
                            $subQuery
                                ->where('candidates.availability_date', '<=', now()->format('Y-m-d'))
                                ->orWhere('candidates.availability_date', false);
                        });
                }),

            'created_recently' => PresetView::make(__('recruitments::filament/clusters/applications/resources/applicant/pages/list-applicant.tabs.created-recently'))
                ->icon('heroicon-s-sparkles')
                ->modifyQueryUsing(function (Builder $query) {
                    return $query->where('create_date', '>=', now()->subDays(30)->toDateString());
                }),

            'stage_updated_recently' => PresetView::make(__('recruitments::filament/clusters/applications/resources/applicant/pages/list-applicant.tabs.stage-updated-recently'))
                ->icon('heroicon-s-arrows-pointing-out')
                ->modifyQueryUsing(function (Builder $query) {
                    return $query->where('date_last_stage_updated', '>=', now()->subDays(30));
                }),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->icon('heroicon-o-plus-circle')
                ->label(__('recruitments::filament/clusters/applications/resources/applicant/pages/list-applicant.header-actions.create-applicant.label'))
                ->modalHeading(__('recruitments::filament/clusters/applications/resources/applicant/pages/list-applicant.header-actions.create-applicant.modal-title'))
                ->modalIcon('heroicon-s-user')
                ->schema([
                    Group::make()
                        ->schema([
                            Select::make('candidate_id')
                                ->relationship('candidate', 'name', modifyQueryUsing: fn (Builder $query): Builder => RecruitmentCompanyScope::ownCompany($query))
                                ->required()
                                ->searchable()
                                ->preload()
                                ->label(__('recruitments::filament/clusters/applications/resources/applicant/pages/list-applicant.header-actions.create-applicant.form.fields.candidate'))
                                ->createOptionForm(fn (Schema $schema) => CandidateResource::form($schema))
                                ->createOptionAction(function (Action $action) {
                                    return $action
                                        ->modalIcon('heroicon-s-user-plus')
                                        ->modalWidth(Width::SixExtraLarge);
                                }),
                            // One application per candidate per job: the same candidate can
                            // apply to several jobs (CandidateApplicationService).
                            Select::make('job_id')
                                ->label('Job position')
                                ->relationship('job', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where(
                                    fn (Builder $query): Builder => $query
                                        ->where($query->qualifyColumn('company_id'), RecruitmentCompanyScope::companyId())
                                        ->orWhereNull($query->qualifyColumn('company_id'))
                                ))
                                ->required()
                                ->searchable()
                                ->preload()
                                ->live()
                                ->afterStateUpdated(fn (Set $set) => $set('job_posting_id', null)),
                            // Which job ad brought this CV (attribution while CVs are entered by hand).
                            Select::make('job_posting_id')
                                ->label('Came from (ad)')
                                ->options(fn (Get $get): array => JobPosting::optionsForJob((int) $get('job_id'), RecruitmentCompanyScope::companyId()))
                                ->visible(fn (Get $get): bool => filled($get('job_id')))
                                ->searchable(),
                        ])->columns(2),
                ])
                ->using(function (array $data): Model {
                    $candidate = Candidate::query()->whereKey($data['candidate_id'])->tap(fn (Builder $query) => RecruitmentCompanyScope::ownCompany($query))->firstOrFail();
                    $job = JobPosition::query()->findOrFail($data['job_id']);
                    $posting = filled($data['job_posting_id'] ?? null)
                        ? JobPosting::query()
                            ->whereKey($data['job_posting_id'])
                            ->where('job_id', $job->id)
                            ->where('company_id', RecruitmentCompanyScope::companyId())
                            ->firstOrFail()
                        : null;

                    $result = app(CandidateApplicationService::class)->applyToJob($candidate, $job, Auth::user(), $posting);
                    $this->lastApplicationOutcome = $result['outcome'];

                    return $result['applicant'];
                })
                ->createAnother(false)
                ->after(function ($record) {
                    return redirect(
                        static::$resource::getUrl('edit', ['record' => $record]),
                    );
                })
                ->successNotification(fn (): Notification => match ($this->lastApplicationOutcome) {
                    CandidateApplicationService::OUTCOME_EXISTING => Notification::make()
                        ->info()
                        ->title('Already applied')
                        ->body('This candidate already has an application for this job. It has been opened instead of creating a duplicate.'),
                    CandidateApplicationService::OUTCOME_REOPENED => Notification::make()
                        ->success()
                        ->title('Application reopened')
                        ->body('The candidate\'s earlier application for this job was reopened.'),
                    default => Notification::make()
                        ->success()
                        ->title(__('recruitments::filament/clusters/applications/resources/applicant/pages/list-applicant.notification.title'))
                        ->body(__('recruitments::filament/clusters/applications/resources/applicant/pages/list-applicant.notification.body')),
                }),
        ];
    }
}
