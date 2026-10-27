<?php

namespace Webkul\Recruitment\Filament\Clusters\Configurations\Resources\JobPositionResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Webkul\Recruitment\Enums\ChannelPostingStatus;
use Webkul\Recruitment\Enums\JobPostingStatus;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\JobPosting;
use Webkul\Recruitment\Models\JobPostingEvent;
use Webkul\Recruitment\Services\JobPostingChannelRegistry;
use Webkul\Recruitment\Services\JobPostingLinks;
use Webkul\Recruitment\Services\JobPostingService;
use Webkul\Recruitment\Support\JobPostingChannel;
use Webkul\Recruitment\Support\JobPostingText;
use Webkul\Security\Services\CurrentCompany;

/**
 * "Job ads" tab on a job opening: the channels the job is advertised on and,
 * for ads a recruiter posts by hand, the prepared text, the "posted" and
 * "taken down" confirmations and the CVs each ad brought in. Every action is
 * authorised here (JobPostingPolicy) and again in JobPostingService.
 */
class JobPostingsRelationManager extends RelationManager
{
    protected static string $relationship = 'postings';

    protected static ?string $title = 'Job ads';

    /** @var array<int, JobPostingText> per-request cache for the Copy post modal */
    protected array $postTexts = [];

    /** @var array<string, string> */
    protected $listeners = ['job-postings-changed' => '$refresh'];

    public function isReadOnly(): bool
    {
        return ! $this->canManage();
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('view_recruitment_job::position') ?? false;
    }

    public function table(Table $table): Table
    {
        $links = app(JobPostingLinks::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['postedBy:id,name', 'removedBy:id,name'])
                ->withCount(['applicants', 'applicants as hired_count' => fn (Builder $applicants): Builder => $applicants->whereNotNull('date_closed')])
                ->orderByRaw("FIELD(status, 'removal_pending', 'to_post', 'failed', 'live', 'waiting', 'planned', 'closed')")
                ->orderBy('id'))
            ->description(fn (): ?string => $links->notLiveReason($this->job()))
            ->emptyStateHeading('No channels yet')
            ->emptyStateDescription('Add the websites and groups this job should be advertised on.')
            ->columns([
                TextColumn::make('channel')
                    ->label('Channel')
                    ->state(fn (JobPosting $record): string => $record->displayName())
                    ->badge()
                    ->color(fn (JobPosting $record): string => $record->definition()->tier->getColor())
                    ->description(fn (JobPosting $record): string => $record->definition()->tier->getLabel()),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->description(fn (JobPosting $record): ?string => $this->statusNote($record)),
                TextColumn::make('live_url')
                    ->label('Ad link')
                    ->url(fn (JobPosting $record): ?string => $record->live_url)
                    ->openUrlInNewTab()
                    ->limit(40)
                    ->placeholder('—'),
                TextColumn::make('applicants_count')
                    ->label('CVs')
                    ->numeric(),
                TextColumn::make('hired_count')
                    ->label('Hired')
                    ->numeric(),
                TextColumn::make('posted_at')
                    ->label('Posted')
                    ->dateTime()
                    ->placeholder('—')
                    ->description(fn (JobPosting $record): ?string => $record->postedBy?->name),
                TextColumn::make('apply_link')
                    ->label('Apply link')
                    ->state(fn (JobPosting $record): string => $links->applyUrl($record).($links->linksAreLive($record) ? '' : ' (not active yet)'))
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('hide_removed')
                    ->label('Hide channels removed from the job')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->whereNull('dropped_at')),
            ])
            ->headerActions([
                $this->addChannelsAction(),
                $this->addPlacementAction(),
            ])
            ->recordActions([
                ActionGroup::make([
                    $this->copyPostAction(),
                    $this->markLiveAction(),
                    $this->markRemovedAction(),
                    $this->reportProblemAction(),
                    $this->postingAction('retry', 'Problem fixed / retry', 'heroicon-o-arrow-path', fn (JobPosting $record): bool => $record->status === ChannelPostingStatus::FAILED)
                        ->requiresConfirmation(),
                    $this->postingAction('repost', 'Post again', 'heroicon-o-arrow-up-tray', fn (JobPosting $record): bool => $record->status === ChannelPostingStatus::CLOSED
                        && $record->dropped_at === null
                        && $this->job()->postingStatus() === JobPostingStatus::PUBLISHED)
                        ->requiresConfirmation(),
                    $this->reportStillUpAction(),
                    $this->postingAction('removeChannel', 'Remove channel', 'heroicon-o-x-mark', fn (JobPosting $record): bool => $record->dropped_at === null
                        && in_array($record->status, [ChannelPostingStatus::PLANNED, ChannelPostingStatus::TO_POST, ChannelPostingStatus::WAITING, ChannelPostingStatus::FAILED], true))
                        ->color('danger')
                        ->requiresConfirmation(),
                    $this->historyAction(),
                ]),
            ]);
    }

    protected function addChannelsAction(): Action
    {
        return Action::make('addChannels')
            ->label('Add channels')
            ->icon('heroicon-o-plus')
            ->visible(fn (): bool => $this->job()->postingStatus() !== JobPostingStatus::CLOSED)
            ->authorize(fn (): bool => $this->canManage())
            ->schema([
                CheckboxList::make('channels')
                    ->label('Advertise this job on')
                    ->options(fn (): array => $this->channelOptions(withPlacement: false))
                    ->descriptions(fn (): array => $this->channelDescriptions(withPlacement: false))
                    ->required(),
            ])
            ->action(function (array $data): void {
                $this->run(function () use ($data): void {
                    $added = app(JobPostingService::class)->addChannels(
                        $this->job(),
                        collect($data['channels'] ?? [])->map(fn (string $key): array => ['channel' => $key])->all(),
                        Auth::user(),
                    );

                    if ($added->isEmpty()) {
                        throw new RuntimeException('Those channels are already on this job.');
                    }
                }, 'Channels added');
            });
    }

    protected function addPlacementAction(): Action
    {
        return Action::make('addPlacement')
            ->label('Add group or website')
            ->icon('heroicon-o-user-group')
            ->color('gray')
            ->visible(fn (): bool => $this->job()->postingStatus() !== JobPostingStatus::CLOSED)
            ->authorize(fn (): bool => $this->canManage())
            ->schema([
                Select::make('channel')
                    ->label('Channel')
                    ->options(fn (): array => $this->channelOptions(withPlacement: true))
                    ->required()
                    ->live(),
                TextInput::make('placement')
                    ->label(fn (Get $get): string => $this->registryChannel($get('channel'))?->placementLabel ?? 'Group or website')
                    ->required(fn (Get $get): bool => (bool) $this->registryChannel($get('channel'))?->requiresPlacement)
                    ->maxLength(120),
            ])
            ->action(function (array $data): void {
                $this->run(function () use ($data): void {
                    $added = app(JobPostingService::class)->addChannels(
                        $this->job(),
                        [['channel' => (string) $data['channel'], 'placement' => $data['placement'] ?? null]],
                        Auth::user(),
                    );

                    if ($added->isEmpty()) {
                        throw new RuntimeException('That channel and group/website is already on this job.');
                    }
                }, 'Channel added');
            });
    }

    protected function copyPostAction(): Action
    {
        return Action::make('copyPost')
            ->label('Copy post')
            ->icon('heroicon-o-clipboard-document')
            ->visible(fn (JobPosting $record): bool => $record->isManual()
                && in_array($record->status, [ChannelPostingStatus::TO_POST, ChannelPostingStatus::LIVE, ChannelPostingStatus::FAILED], true))
            ->authorize(fn (JobPosting $record): bool => Gate::allows('view', $record))
            ->modalHeading(fn (JobPosting $record): string => "Post for {$record->displayName()}")
            ->modalDescription(fn (JobPosting $record): ?string => app(JobPostingLinks::class)->linksAreLive($record) ? null : app(JobPostingLinks::class)->notLiveReason($this->job()))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->schema(fn (JobPosting $record): array => [
                TextEntry::make('post_title')->label('Title')->state(fn (): string => $this->postText($record)->title)->copyable(),
                TextEntry::make('post_apply')->label('How to apply')->state(fn (): string => $this->postText($record)->applyLine)->copyable(),
                Textarea::make('post_full_text')
                    ->label('Full post (select and copy)')
                    ->default(fn (): string => $this->postText($record)->fullText)
                    ->rows(14)
                    ->readOnly()
                    ->dehydrated(false),
            ]);
    }

    protected function markLiveAction(): Action
    {
        return Action::make('markLive')
            ->label(fn (JobPosting $record): string => $record->status === ChannelPostingStatus::LIVE ? 'Update link' : 'Mark as posted')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (JobPosting $record): bool => $record->isManual() && (
                in_array($record->status, [ChannelPostingStatus::TO_POST, ChannelPostingStatus::FAILED, ChannelPostingStatus::LIVE], true)
                || ($record->status === ChannelPostingStatus::REMOVAL_PENDING && $this->job()->postingStatus() === JobPostingStatus::PUBLISHED)
            ))
            ->authorize(fn (JobPosting $record): bool => $this->canUpdatePosting($record))
            ->fillForm(fn (JobPosting $record): array => ['live_url' => $record->live_url, 'posted_at' => $record->posted_at ?? now()])
            ->schema(fn (JobPosting $record): array => [
                TextInput::make('live_url')
                    ->label('Link to the ad')
                    ->url()
                    ->maxLength(2048)
                    ->required($record->definition()->requiresLiveUrl)
                    ->helperText($record->definition()->liveUrlHosts === [] ? null : 'Expected a '.implode(' or ', $record->definition()->liveUrlHosts).' link.'),
                DateTimePicker::make('posted_at')
                    ->label('Posted on')
                    ->maxDate(now())
                    ->native(false)
                    ->visible($record->posted_at === null),
            ])
            ->action(function (JobPosting $record, array $data): void {
                $this->run(fn () => app(JobPostingService::class)->markLive(
                    $record,
                    Auth::user(),
                    $data['live_url'] ?? null,
                    filled($data['posted_at'] ?? null) ? Carbon::parse($data['posted_at']) : null,
                ), 'Ad marked as posted');
            });
    }

    protected function markRemovedAction(): Action
    {
        return $this->postingAction('markRemoved', 'Confirm taken down', 'heroicon-o-trash', fn (JobPosting $record): bool => $record->isManual() && $record->status->isOutThere())
            ->label(fn (JobPosting $record): string => $record->channel === 'whatsapp' ? 'Confirm "position filled" sent' : 'Confirm taken down')
            ->modalDescription(fn (JobPosting $record): ?string => $record->definition()->removalHint)
            ->color('danger')
            ->requiresConfirmation();
    }

    protected function reportProblemAction(): Action
    {
        return Action::make('reportProblem')
            ->label('Report problem')
            ->icon('heroicon-o-exclamation-triangle')
            ->color('warning')
            ->visible(fn (JobPosting $record): bool => $record->isManual() && $record->status === ChannelPostingStatus::TO_POST)
            ->authorize(fn (JobPosting $record): bool => $this->canUpdatePosting($record))
            ->schema([
                Textarea::make('message')->label('What went wrong?')->required()->maxLength(1000),
            ])
            ->action(function (JobPosting $record, array $data): void {
                $this->run(fn () => app(JobPostingService::class)->reportProblem($record, Auth::user(), (string) $data['message']), 'Problem recorded');
            });
    }

    protected function reportStillUpAction(): Action
    {
        return Action::make('reportStillUp')
            ->label('Ad is still up')
            ->icon('heroicon-o-eye')
            ->color('warning')
            ->visible(fn (JobPosting $record): bool => $record->isManual()
                && $record->status === ChannelPostingStatus::CLOSED
                && $record->posted_at === null
                && $record->dropped_at === null
                && $this->job()->postingStatus() === JobPostingStatus::CLOSED)
            ->authorize(fn (JobPosting $record): bool => $this->canUpdatePosting($record))
            ->schema([
                TextInput::make('live_url')->label('Link to the ad (optional)')->url()->maxLength(2048),
            ])
            ->action(function (JobPosting $record, array $data): void {
                $this->run(fn () => app(JobPostingService::class)->reportStillUp($record, Auth::user(), $data['live_url'] ?? null), 'Ad flagged for take-down');
            });
    }

    protected function historyAction(): Action
    {
        return Action::make('history')
            ->label('History')
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->authorize(fn (JobPosting $record): bool => Gate::allows('view', $record))
            ->modalHeading(fn (JobPosting $record): string => "History: {$record->displayName()}")
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->schema([
                RepeatableEntry::make('events')
                    ->label('')
                    ->state(fn (JobPosting $record): array => $record->events()->with('actor:id,name')->latest('id')->get()
                        ->map(fn (JobPostingEvent $event): array => [
                            'when'    => $event->created_at?->format('d M Y H:i'),
                            'what'    => Str::headline($event->event).($event->to_status ? ' → '.(ChannelPostingStatus::tryFrom($event->to_status)?->getLabel() ?? $event->to_status) : ''),
                            'who'     => $event->actor?->name ?? 'System ('.$event->origin.')',
                            'details' => $event->details ? collect($event->details)->map(fn ($value, $key): string => Str::headline((string) $key).': '.(is_scalar($value) ? $value : json_encode($value)))->implode(' · ') : null,
                        ])->all())
                    ->schema([
                        TextEntry::make('when')->label('When'),
                        TextEntry::make('what')->label('What'),
                        TextEntry::make('who')->label('Who'),
                        TextEntry::make('details')->label('Details')->placeholder('—'),
                    ])
                    ->columns(4),
            ]);
    }

    /** A confirmation-style row action that calls the JobPostingService method of the same name. */
    protected function postingAction(string $method, string $label, string $icon, callable $visible): Action
    {
        return Action::make($method)
            ->label($label)
            ->icon($icon)
            ->visible($visible)
            ->authorize(fn (JobPosting $record): bool => $this->canUpdatePosting($record))
            ->action(function (JobPosting $record) use ($method, $label): void {
                $this->run(fn () => app(JobPostingService::class)->{$method}($record, Auth::user()), $label.': done');
            });
    }

    /** Runs a service call, turning refusals and validation errors into a message. */
    protected function run(callable $call, string $successTitle): void
    {
        try {
            $call();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Please check the details')->body(collect($e->errors())->flatten()->implode(' '))->send();

            return;
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Could not update the job ad')->body($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($successTitle)->send();
        $this->dispatch('job-postings-changed');
    }

    protected function statusNote(JobPosting $record): ?string
    {
        return match ($record->status) {
            ChannelPostingStatus::WAITING, ChannelPostingStatus::PLANNED => app(JobPostingChannelRegistry::class)->availabilityNote($record->channel, $this->job()),
            ChannelPostingStatus::FAILED                                 => $record->last_error,
            ChannelPostingStatus::REMOVAL_PENDING                        => $record->removal_requested_at ? 'Take down requested '.$record->removal_requested_at->diffForHumans() : null,
            ChannelPostingStatus::CLOSED                                 => $record->closedReasonLabel(),
            default                                                      => null,
        };
    }

    /** @return array<string, string> */
    protected function channelOptions(bool $withPlacement): array
    {
        $active = $this->job()->activePostings()->pluck('channel')->all();

        return collect(app(JobPostingChannelRegistry::class)->all())
            ->filter(fn (JobPostingChannel $channel): bool => $withPlacement
                ? $channel->allowsPlacement()
                : ! $channel->requiresPlacement && ! in_array($channel->key, $active, true))
            ->map(fn (JobPostingChannel $channel): string => $channel->label)
            ->all();
    }

    /** @return array<string, string> */
    protected function channelDescriptions(bool $withPlacement): array
    {
        $registry = app(JobPostingChannelRegistry::class);
        $job = $this->job();

        return collect($this->channelOptions($withPlacement))
            ->mapWithKeys(fn (string $label, string $key): array => [
                $key => trim($registry->resolve($key)->tier->getLabel().'. '.($registry->availabilityNote($key, $job) ?? '')),
            ])
            ->all();
    }

    protected function registryChannel(?string $key): ?JobPostingChannel
    {
        $registry = app(JobPostingChannelRegistry::class);

        return $key && $registry->has($key) ? $registry->resolve($key) : null;
    }

    protected function postText(JobPosting $record): JobPostingText
    {
        return $this->postTexts[$record->getKey()] ??= app(JobPostingService::class)->postText($record, Auth::user());
    }

    /** Ads of another member company's job are view-only (switch company to change them). */
    protected function canManage(): bool
    {
        return $this->isActiveCompanyJob() && (bool) Auth::user()?->can('create', [JobPosting::class, $this->job()]);
    }

    protected function canUpdatePosting(JobPosting $record): bool
    {
        return $this->isActiveCompanyJob() && Gate::allows('update', $record);
    }

    protected function isActiveCompanyJob(): bool
    {
        return (int) $this->job()->company_id === CurrentCompany::id();
    }

    protected function job(): JobPosition
    {
        /** @var JobPosition $job */
        $job = $this->getOwnerRecord();

        return $job;
    }
}
