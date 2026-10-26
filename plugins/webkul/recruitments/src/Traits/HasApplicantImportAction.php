<?php

namespace Webkul\Recruitment\Traits;

use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\JobPosting;
use Webkul\Recruitment\Models\JobQuestion;
use Webkul\Recruitment\Services\ApplicantImportService;
use Webkul\Recruitment\Support\RecruitmentCompanyScope;
use Webkul\Support\Models\UTMSource;

/**
 * "Import CVs" on the applicant list: upload a CSV/Excel export from a job
 * board, match its columns (suggested automatically) and import every row
 * through ApplicantImportService. Results, including row-by-row problems,
 * are shown and also kept in the user's notifications.
 */
trait HasApplicantImportAction
{
    protected function getApplicantImportAction(): Action
    {
        $headerOptions = fn (Get $get): array => collect((array) $get('headers'))->mapWithKeys(fn (string $header): array => [$header => $header])->all();

        return Action::make('importApplicants')
            ->label('Import CVs')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->authorize(fn (): bool => (bool) Auth::user()?->can('create', Applicant::class))
            ->modalHeading('Import applicants from a job board export')
            ->modalDescription('CSV or Excel file with one applicant per row (exported from LinkedIn, Rozee.pk, Indeed...). Existing candidates are matched by email; nobody gets two applications for the same job; importing the same file again changes nothing new.')
            ->modalSubmitActionLabel('Import')
            ->schema([
                Select::make('job_id')
                    ->label('Job position')
                    ->options(fn (): array => JobPosition::query()
                        ->tap(fn (Builder $query) => RecruitmentCompanyScope::ownCompany($query))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->required()
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('job_posting_id', null)),
                Select::make('job_posting_id')
                    ->label('These CVs came from (ad)')
                    ->options(fn (Get $get): array => JobPosting::optionsForJob((int) $get('job_id'), RecruitmentCompanyScope::companyId()))
                    ->visible(fn (Get $get): bool => filled($get('job_id')))
                    ->helperText('Pick the job ad the export belongs to, so every CV is attributed to it.'),
                Select::make('source_id')
                    ->label('Or source')
                    ->options(fn (): array => UTMSource::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->visible(fn (Get $get): bool => blank($get('job_posting_id'))),
                FileUpload::make('file')
                    ->label('CSV or Excel file')
                    ->disk('local')
                    ->directory('recruitment-imports')
                    ->acceptedFileTypes([
                        'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->maxSize(5120)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                        $file = is_array($state) ? reset($state) : $state;

                        if (! is_object($file) || ! method_exists($file, 'getRealPath')) {
                            return;
                        }

                        try {
                            $headers = app(ApplicantImportService::class)->read($file->getRealPath(), $file->getClientOriginalName())['headers'];
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title('Could not read the file')->body($e->getMessage())->send();

                            return;
                        }

                        $set('headers', $headers);

                        foreach (app(ApplicantImportService::class)->suggestMapping($headers) as $field => $header) {
                            $set("mapping.{$field}", $header);
                        }

                        // Screening question columns whose header is the question text.
                        foreach (JobQuestion::query()->where('job_id', (int) $get('job_id'))->get() as $question) {
                            $match = collect($headers)->first(fn (string $header): bool => Str::lower(trim($header)) === Str::lower(trim($question->label)));
                            $set("questions.{$question->id}", $match);
                        }
                    }),
                Hidden::make('headers'),
                Section::make('Columns')
                    ->description('Matched automatically from the file\'s headers. Change any that are wrong.')
                    ->visible(fn (Get $get): bool => filled($get('headers')))
                    ->columns(2)
                    ->schema(collect(ApplicantImportService::FIELD_LABELS)
                        ->map(fn (string $label, string $field) => Select::make("mapping.{$field}")
                            ->label($label)
                            ->options($headerOptions)
                            ->placeholder('Not in the file'))
                        ->values()
                        ->all()),
                Section::make('Screening question columns (optional)')
                    ->description('If the export contains answers to this job\'s screening questions, match them here; they are judged on import.')
                    ->visible(fn (Get $get): bool => filled($get('headers'))
                        && JobQuestion::query()->where('job_id', (int) $get('job_id'))->exists())
                    ->columns(2)
                    ->schema(fn (Get $get): array => JobQuestion::query()->where('job_id', (int) $get('job_id'))->orderBy('sort')->get()
                        ->map(fn (JobQuestion $question) => Select::make("questions.{$question->id}")
                            ->label(Str::limit($question->label, 80))
                            ->options($headerOptions)
                            ->placeholder('Not in the file'))
                        ->all()),
            ])
            ->action(function (array $data): void {
                $this->runApplicantImport($data);
            });
    }

    /** @param array<string, mixed> $data */
    protected function runApplicantImport(array $data): void
    {
        $service = app(ApplicantImportService::class);
        $path = Storage::disk('local')->path((string) $data['file']);
        $user = Auth::user();

        try {
            $job = JobPosition::query()->whereKey($data['job_id'])->tap(fn (Builder $query) => RecruitmentCompanyScope::ownCompany($query))->firstOrFail();
            $posting = filled($data['job_posting_id'] ?? null)
                ? JobPosting::query()->whereKey($data['job_posting_id'])->where('job_id', $job->id)->where('company_id', $job->company_id)->firstOrFail()
                : null;

            $file = $service->read($path, (string) $data['file']);
            $mapping = array_filter((array) ($data['mapping'] ?? [])) ?: $service->suggestMapping($file['headers']);

            $result = $service->import(
                $job,
                $file['rows'],
                $mapping,
                $user,
                $posting,
                filled($data['source_id'] ?? null) ? (int) $data['source_id'] : null,
                (array) ($data['questions'] ?? []),
                basename((string) $data['file']),
            );
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Import not started')->body($e->getMessage())->send();

            return;
        } finally {
            Storage::disk('local')->delete((string) $data['file']);
        }

        $problems = $result->problemLines();
        $body = $result->summary().($problems === [] ? '' : "\n\n".implode("\n", $problems));
        $notification = Notification::make()
            ->title("Imported {$result->total()} row(s) for {$job->name}")
            ->body(nl2br(e($body)));

        ($result->failed === [] ? $notification->success() : $notification->warning())->send();
        $notification->sendToDatabase($user);
    }
}
