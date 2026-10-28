<?php

namespace Webkul\Recruitment\Filament\Clusters\Configurations\Pages;

use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Webkul\Recruitment\Enums\ScreeningStatus;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource;
use Webkul\Recruitment\Filament\Clusters\Configurations;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\IntakeFailure;
use Webkul\Recruitment\Models\JobPosting;
use Webkul\Recruitment\Models\WebsiteConnection;
use Webkul\Recruitment\Services\Website\WebsiteConnectionService;
use Webkul\Recruitment\Services\Website\WebsiteIntakeService;
use Webkul\Recruitment\Support\WebsiteIntakeResult;
use Webkul\Security\Services\CurrentCompany;

/**
 * Recruitment → Configurations → Website intake: the active company's
 * website applications that could not be imported (automation plan §6 A4),
 * with Retry and Dismiss, plus "Check for new applications now". Same
 * permission as the Careers website page, checked again in the service.
 */
class WebsiteIntake extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'recruitments::filament.clusters.configurations.pages.website-intake';

    protected static ?string $cluster = Configurations::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationLabel = 'Website intake';

    protected static ?string $title = 'Website intake';

    protected static ?string $slug = 'website-intake';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Website';
    }

    public static function canAccess(): bool
    {
        return WebsiteConnectionService::canManage(CurrentCompany::user(), CurrentCompany::id());
    }

    public static function getNavigationBadge(): ?string
    {
        $companyId = CurrentCompany::id();
        $count = $companyId ? IntakeFailure::query()->forCompany($companyId)->open()->count() : 0;

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => IntakeFailure::query()
                ->forCompany((int) CurrentCompany::id())
                ->open())
            ->heading('Applications that could not be imported')
            ->description('They stay on the website until they are imported or dismissed. Each is tried again automatically on the next checks (up to '.max(1, (int) config('recruitment.website.auto_retry_attempts', 3)).' times), then waits here.')
            ->emptyStateHeading('Nothing waiting')
            ->emptyStateDescription('Every application from the careers website was imported.')
            ->columns([
                TextColumn::make('created_at')
                    ->label('First failed')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('website_id')
                    ->label('Website id')
                    ->state(fn (IntakeFailure $record): string => (string) ($record->applicationId() ?? '—')),
                TextColumn::make('candidate')
                    ->label('Applicant')
                    ->state(fn (IntakeFailure $record): string => $record->candidateLabel()),
                TextColumn::make('job')
                    ->label('Job')
                    ->state(fn (IntakeFailure $record): string => $this->jobName($record)),
                TextColumn::make('error')
                    ->label('Problem')
                    ->wrap()
                    ->limit(160)
                    ->tooltip(fn (IntakeFailure $record): string => (string) $record->error),
                TextColumn::make('attempts')
                    ->label('Tries')
                    ->numeric(),
                TextColumn::make('last_attempt_at')
                    ->label('Last try')
                    ->since()
                    ->sortable(),
            ])
            ->headerActions([
                Action::make('pullNow')
                    ->label('Check for new applications now')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => WebsiteConnection::activeFor(CurrentCompany::id()) !== null)
                    ->authorize(fn (): bool => static::canAccess())
                    ->action(function (): void {
                        $this->run(fn (): WebsiteIntakeResult => app(WebsiteIntakeService::class)->pullForCompany(CurrentCompany::user(), (int) CurrentCompany::id()));
                    }),
            ])
            ->recordActions([
                Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalDescription('Imports this application now with what the website sent (the CV is downloaded again).')
                    ->authorize(fn (IntakeFailure $record): bool => WebsiteConnectionService::canManage(CurrentCompany::user(), $record->company_id))
                    ->action(function (IntakeFailure $record): void {
                        $this->run(fn (): WebsiteIntakeResult => app(WebsiteIntakeService::class)->retry($record, CurrentCompany::user()));
                    }),
                Action::make('dismiss')
                    ->label('Dismiss')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The application is not imported. Aureus tells the website it was handled, so the website stops offering it and deletes its copy of the CV.')
                    ->authorize(fn (IntakeFailure $record): bool => WebsiteConnectionService::canManage(CurrentCompany::user(), $record->company_id))
                    ->action(function (IntakeFailure $record): void {
                        try {
                            $warning = app(WebsiteIntakeService::class)->dismiss($record, CurrentCompany::user());
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Could not dismiss it')->body(e($e->getMessage()))->send();

                            return;
                        }

                        $warning
                            ? Notification::make()->warning()->title('Dismissed')->body(e($warning))->send()
                            : Notification::make()->success()->title('Dismissed')->send();
                    }),
                Action::make('details')
                    ->label('What the website sent')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->color('gray')
                    ->authorize(fn (IntakeFailure $record): bool => WebsiteConnectionService::canManage(CurrentCompany::user(), $record->company_id))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema([
                        TextEntry::make('payload')
                            ->label('')
                            ->state(fn (IntakeFailure $record): string => (string) json_encode($record->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                            ->fontFamily('mono'),
                    ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /** The job named by the application's token, only when it is a job of this company. */
    protected function jobName(IntakeFailure $record): string
    {
        $token = strtolower((string) ($record->payload['job_token'] ?? ''));

        if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
            return '—';
        }

        $posting = JobPosting::query()
            ->where('token', $token)
            ->where('company_id', $record->company_id)
            ->with('job:id,name')
            ->first();

        return $posting?->job?->name ?? 'Not a job of this company';
    }

    /** @param callable(): WebsiteIntakeResult $call */
    protected function run(callable $call): void
    {
        try {
            $result = $call();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Could not import')->body(e($e->getMessage()))->send();

            return;
        }

        $failed = $result->error !== null || $result->busy || $result->counts[WebsiteIntakeResult::FAILED] > 0;
        $created = $result->created->loadMissing(['candidate', 'job']);
        $lines = $created->take(5)->map(fn (Applicant $applicant): string => e(($applicant->candidate?->name ?? 'Applicant').' — '.($applicant->job?->name ?? 'no job')));

        if ($result->counts[WebsiteIntakeResult::ALREADY_APPLIED] > 0) {
            $lines->push(e($result->counts[WebsiteIntakeResult::ALREADY_APPLIED].' had already applied to the same job (one application per person per job), so no new application was made.'));
        }

        $title = match (true) {
            $created->isNotEmpty() => $created->count().' new application'.($created->count() === 1 ? '' : 's').' imported',
            $failed                => 'Done, with problems',
            default                => 'No new applications',
        };

        $notification = Notification::make()
            ->title($title)
            ->body($lines->isNotEmpty() ? $lines->implode('<br>').'<br><span style="opacity:.7">'.e($result->summary()).'</span>' : e($result->summary()))
            ->actions($created->take(3)->map(fn (Applicant $applicant) => Action::make('open'.$applicant->id)
                ->label('Open '.($applicant->candidate?->name ?? 'applicant'))
                ->url(ApplicantResource::getUrl('view', ['record' => $applicant]))
                ->button())
                ->all())
            ->persistent();

        ($failed ? $notification->warning() : $notification->success())->send();
    }

    /**
     * The latest applications imported from the careers website, so HR can see
     * what arrived (the table above lists only what could not be imported).
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $companyId = (int) CurrentCompany::id();

        return [
            'recent' => Applicant::query()
                ->where('company_id', $companyId)
                ->where('applicant_properties->source_provenance->adapter', 'company_website')
                ->with(['candidate', 'job', 'jobPosting'])
                ->latest('id')
                ->limit(15)
                ->get()
                ->map(fn (Applicant $applicant): array => [
                    'url'       => ApplicantResource::getUrl('view', ['record' => $applicant]),
                    'name'      => $applicant->candidate?->name ?? 'Applicant',
                    'email'     => $applicant->candidate?->email_from,
                    'job'       => $applicant->job?->name ?? '—',
                    'via'       => $applicant->jobPosting?->displayName() ?? 'Company website',
                    'received'  => $applicant->created_at?->diffForHumans(),
                    'cv'        => filled($applicant->candidate?->resume_path),
                    'screening' => ScreeningStatus::tryFrom((string) $applicant->screening_status)?->getLabel(),
                    'score'     => $applicant->screening_score !== null ? (int) round((float) $applicant->screening_score) : null,
                ]),
        ];
    }
}
