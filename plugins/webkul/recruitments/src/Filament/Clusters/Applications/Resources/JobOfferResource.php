<?php

namespace Webkul\Recruitment\Filament\Clusters\Applications\Resources;

use Filament\Actions\ActionGroup;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Recruitment\Enums\JobOfferStatus;
use Webkul\Recruitment\Filament\Clusters\Applications;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\JobOfferResource\Pages\ListJobOffers;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\JobOfferResource\Pages\ViewJobOffer;
use Webkul\Recruitment\Filament\Schemas\JobOfferComponents;
use Webkul\Recruitment\Models\JobOffer;
use Webkul\Recruitment\Models\JobOfferEvent;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Services\JobOfferService;
use Webkul\Recruitment\Services\OfferLetterRenderer;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Services\ApprovalEngine;

/**
 * Recruitment > Applications > Offers (HR parity 3.6): the active
 * company's job offers with their status. Recruitment users see all of
 * them; anyone else only the offers they approve (scoped in the query).
 * Offers are made from an application ("Make offer"); everything else is
 * done from here or the offer page, through JobOfferService.
 */
class JobOfferResource extends Resource
{
    protected static ?string $model = JobOffer::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $cluster = Applications::class;

    protected static ?int $navigationSort = 5;

    protected static bool $isGloballySearchable = false;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getModelLabel(): string
    {
        return __('Offer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Offers');
    }

    public static function getNavigationLabel(): string
    {
        return __('Offers');
    }

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        $query = CurrentCompany::scope(parent::getEloquentQuery());

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        if (app(JobOfferService::class)->seesAllOffers($user)) {
            return $query;
        }

        return $query->where(fn (Builder $inner): Builder => $inner
            ->where('hiring_manager_id', $user->id)
            ->orWhereHas('approvalRequest.decisions', fn (Builder $decisions): Builder => $decisions->where('actor_id', $user->id))
            ->orWhere(fn (Builder $awaiting): Builder => $awaiting->awaitingDecisionBy($user, static::isHrApprover($user))));
    }

    protected static function isHrApprover(User $user): bool
    {
        $companyId = CurrentCompany::id($user);

        return $companyId !== null && app(ApprovalEngine::class)->isCompanyHrApprover($user, $companyId);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(JobOfferComponents::tableRelations()))
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading(__('No offers yet'))
            ->emptyStateDescription(__('Make an offer from an application (Make offer).'))
            ->columns(JobOfferComponents::columns(withApplicant: true))
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->multiple()
                    ->options(JobOfferStatus::options()),
                Filter::make('open')
                    ->label(__('Open offers only'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->open()),
                Filter::make('awaiting_me')
                    ->label(__('Waiting for my approval'))
                    ->toggle()
                    ->query(function (Builder $query): Builder {
                        $user = Auth::user();

                        return $user instanceof User ? $query->awaitingDecisionBy($user, static::isHrApprover($user)) : $query->whereRaw('1 = 0');
                    }),
                Filter::make('expiring_soon')
                    ->label(__('Sent, expiring within 3 days'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', JobOfferStatus::SENT->value)
                        ->whereBetween('expiry_date', [now(OfferLetterRenderer::timezone())->toDateString(), now(OfferLetterRenderer::timezone())->addDays(3)->toDateString()])),
                SelectFilter::make('job_id')
                    ->label(__('Job position'))
                    ->options(fn (): array => JobPosition::query()->where('company_id', CurrentCompany::id())->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
            ])
            ->recordUrl(fn (JobOffer $record): string => static::getUrl('view', ['record' => $record]))
            ->recordActions([
                ActionGroup::make(JobOfferComponents::recordActions(withView: false)),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $service = fn (): JobOfferService => app(JobOfferService::class);
        $user = fn (): ?User => Auth::user() instanceof User ? Auth::user() : null;

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])
                ->columnSpanFull()
                ->schema([
                    Group::make()
                        ->columnSpan(['lg' => 2])
                        ->schema([
                            Section::make(__('Offer'))
                                ->columns(2)
                                ->schema([
                                    TextEntry::make('status')->label(__('Status'))->badge(),
                                    TextEntry::make('reference')->label(__('Reference')),
                                    TextEntry::make('candidate_name')
                                        ->label(__('Candidate'))
                                        ->state(fn (JobOffer $record): string => $record->candidateName())
                                        ->url(fn (JobOffer $record): ?string => $record->applicant && ($user()?->can('view', $record->applicant) ?? false)
                                            ? ApplicantResource::getUrl('view', ['record' => $record->applicant])
                                            : null),
                                    TextEntry::make('position')->label(__('Position'))->state(fn (JobOffer $record): string => $record->positionLabel()),
                                    TextEntry::make('job.name')->label(__('Job position'))->placeholder('—'),
                                    TextEntry::make('department.name')->label(__('Department'))->placeholder('—'),
                                    TextEntry::make('reportingManager.name')->label(__('Reporting manager'))->placeholder('—'),
                                    TextEntry::make('hiringManager.name')->label(__('Hiring manager (approver)'))->placeholder(__('HR (no hiring manager)')),
                                    TextEntry::make('joining_date')->label(__('Joining date'))->date('d F Y'),
                                    TextEntry::make('expiry_date')->label(__('Offer valid until'))->date('d F Y'),
                                    TextEntry::make('creator.name')->label(__('Drafted by'))->placeholder('—'),
                                    TextEntry::make('template.name')->label(__('Letter template'))->placeholder(__('Company default')),
                                ]),
                            Section::make(__('Salary'))
                                ->description(__('Visible only to HR, hiring managers and the approvers of the offer.'))
                                ->visible(fn (JobOffer $record): bool => $service()->canSeeSalary($user(), $record))
                                ->columns(2)
                                ->schema([
                                    TextEntry::make('salary_gross')
                                        ->label(__('Monthly gross'))
                                        ->state(fn (JobOffer $record): string => OfferLetterRenderer::money($record->salary_gross, $record->currencyCode()))
                                        ->weight('bold'),
                                    IconEntry::make('requires_ceo_approval')
                                        ->label(__('Needs CEO approval'))
                                        ->boolean(),
                                    TextEntry::make('breakdown')
                                        ->label(__('Breakdown'))
                                        ->state(fn (JobOffer $record): array => collect($record->breakdown())
                                            ->map(fn ($amount, string $key): string => __(JobOffer::BREAKDOWN_COMPONENTS[$key]).': '.OfferLetterRenderer::money($amount, $record->currencyCode()))
                                            ->values()
                                            ->all())
                                        ->listWithLineBreaks()
                                        ->placeholder(__('Not broken down'))
                                        ->columnSpanFull(),
                                ]),
                            Section::make(__('Terms'))
                                ->columns(2)
                                ->schema([
                                    TextEntry::make('probation_months')->label(__('Probation (months)'))->placeholder('—'),
                                    TextEntry::make('working_hours')->label(__('Working hours'))->placeholder('—'),
                                    TextEntry::make('benefits')->label(__('Benefits'))->placeholder('—')->columnSpanFull(),
                                    TextEntry::make('internal_notes')->label(__('Internal notes'))->placeholder('—')->columnSpanFull(),
                                ]),
                            Section::make(__('History'))
                                ->schema([
                                    RepeatableEntry::make('events')
                                        ->hiddenLabel()
                                        ->state(fn (JobOffer $record): array => $record->events()->with('actor')->get()
                                            ->map(fn (JobOfferEvent $event): array => [
                                                'when'  => $event->created_at?->timezone(OfferLetterRenderer::timezone())->format('d M Y H:i'),
                                                'what'  => $event->label(),
                                                'who'   => $event->actor?->name ?? ($event->event === 'accepted' || $event->event === 'declined' ? __('Candidate') : __('System')),
                                                'note'  => $event->note,
                                            ])
                                            ->reverse()
                                            ->values()
                                            ->all())
                                        ->columns(4)
                                        ->schema([
                                            TextEntry::make('when')->hiddenLabel()->color('gray'),
                                            TextEntry::make('what')->hiddenLabel()->weight('bold'),
                                            TextEntry::make('who')->hiddenLabel(),
                                            TextEntry::make('note')->hiddenLabel()->placeholder(''),
                                        ])
                                        ->placeholder(__('No history yet')),
                                ]),
                        ]),
                    Group::make()
                        ->columnSpan(['lg' => 1])
                        ->schema([
                            Section::make(__('Approval'))
                                ->schema([
                                    TextEntry::make('awaiting')
                                        ->label(__('Waiting for'))
                                        ->state(fn (JobOffer $record): ?string => $record->status === JobOfferStatus::PENDING_APPROVAL && $record->approvalRequest
                                            ? app(ApprovalEngine::class)->describeCurrentApprover($record->approvalRequest)
                                            : null)
                                        ->placeholder('—'),
                                    TextEntry::make('submitted_at')->label(__('Submitted'))->dateTime('d M Y H:i', OfferLetterRenderer::timezone())->placeholder('—'),
                                    TextEntry::make('approved_at')->label(__('Approved'))->dateTime('d M Y H:i', OfferLetterRenderer::timezone())->placeholder('—'),
                                    TextEntry::make('rejection_reason')->label(__('Rejection reason'))->placeholder('—')
                                        ->visible(fn (JobOffer $record): bool => $record->status === JobOfferStatus::REJECTED),
                                ]),
                            Section::make(__('Letter and response'))
                                ->schema([
                                    TextEntry::make('letter_generated_at')->label(__('Letter generated'))->dateTime('d M Y H:i', OfferLetterRenderer::timezone())->placeholder(__('Not yet')),
                                    TextEntry::make('sent_at')
                                        ->label(__('Sent'))
                                        ->state(fn (JobOffer $record): ?string => $record->sent_at
                                            ? $record->sent_at->timezone(OfferLetterRenderer::timezone())->format('d M Y H:i').' · '.$record->sent_to.($record->sender ? ' · '.$record->sender->name : '')
                                            : null)
                                        ->placeholder(__('Not sent')),
                                    TextEntry::make('responded_at')->label(__('Candidate answered'))->dateTime('d M Y H:i', OfferLetterRenderer::timezone())->placeholder('—'),
                                    TextEntry::make('response_reason')->label(__('Candidate\'s message'))->placeholder('—'),
                                    TextEntry::make('expired_at')->label(__('Expired'))->dateTime('d M Y H:i', OfferLetterRenderer::timezone())
                                        ->visible(fn (JobOffer $record): bool => $record->status === JobOfferStatus::EXPIRED),
                                    TextEntry::make('withdraw_reason')->label(__('Withdrawn'))
                                        ->state(fn (JobOffer $record): ?string => $record->status === JobOfferStatus::WITHDRAWN ? ($record->withdraw_reason ?: __('No reason given')) : null)
                                        ->visible(fn (JobOffer $record): bool => $record->status === JobOfferStatus::WITHDRAWN),
                                    TextEntry::make('hiredEmployee.name')->label(__('Employee'))->placeholder('—'),
                                ]),
                        ]),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJobOffers::route('/'),
            'view'  => ViewJobOffer::route('/{record}'),
        ];
    }
}
