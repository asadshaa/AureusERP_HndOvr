<?php

namespace Webkul\Recruitment\Filament\Schemas;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Webkul\Recruitment\Jobs\AnalyseApplicantCv;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Services\CvAnalysis\CvAnalyser;

/**
 * Applicant list, view and job page pieces for the CV analyser (automation
 * plan A5): score column with the reasons as tooltip, filters, the analysis
 * panel, and "Analyse CV" buttons. Buttons need update rights on the
 * application (or job) and are checked again in the action.
 */
class CvAnalysisComponents
{
    public static function scoreColumn(): TextColumn
    {
        return TextColumn::make('screening_score')
            ->label('CV score')
            ->badge()
            ->numeric(0)
            ->color(fn ($state): string => self::scoreColor($state))
            ->icon(fn (Applicant $record): ?string => self::hasFlag($record, 'remote_only') ? 'heroicon-m-exclamation-triangle' : null)
            ->tooltip(fn (Applicant $record): ?string => self::reasonsText($record))
            ->placeholder('—')
            ->sortable()
            ->toggleable();
    }

    /** @return array<int, Filter|TernaryFilter> */
    public static function filters(): array
    {
        return [
            Filter::make('cv_score_at_least')
                ->label('CV score at least')
                ->schema([
                    TextInput::make('min')->label('CV score at least')->numeric()->minValue(0)->maxValue(100),
                ])
                ->query(fn (Builder $query, array $data): Builder => $query->when(
                    filled($data['min'] ?? null),
                    fn (Builder $query) => $query->where('screening_score', '>=', (float) $data['min']),
                ))
                ->indicateUsing(fn (array $data): ?string => filled($data['min'] ?? null) ? "CV score ≥ {$data['min']}" : null),
            TernaryFilter::make('cv_remote_only')
                ->label('CV asks for remote only')
                ->trueLabel('Remote-only CVs (on-site/hybrid jobs)')
                ->falseLabel('On-site OK')
                ->queries(
                    true: fn (Builder $query): Builder => $query->whereJsonContains('applicant_properties->cv_analysis->flags', 'remote_only'),
                    false: fn (Builder $query): Builder => $query->where(fn (Builder $inner) => $inner
                        ->whereNull('applicant_properties->cv_analysis')
                        ->orWhereJsonDoesntContain('applicant_properties->cv_analysis->flags', 'remote_only')),
                ),
        ];
    }

    public static function infolistSection(): Section
    {
        return Section::make('CV analysis')
            ->icon('heroicon-o-document-magnifying-glass')
            ->description('Ranks and flags only. It never rejects an applicant.')
            ->schema([
                TextEntry::make('screening_score')
                    ->label('CV score')
                    ->badge()
                    ->color(fn ($state): string => self::scoreColor($state))
                    ->placeholder(fn (Applicant $record): string => self::analysis($record) ? 'Not scored' : 'Not analysed yet'),
                TextEntry::make('cv_flags')
                    ->label('Flags')
                    ->badge()
                    ->color('danger')
                    ->state(fn (Applicant $record): array => collect(self::analysis($record)['flags'] ?? [])
                        ->map(fn (string $flag): string => $flag === 'remote_only' ? 'Asks for remote only' : $flag)->all())
                    ->placeholder('None'),
                TextEntry::make('cv_matched')
                    ->label('Keywords found')
                    ->badge()
                    ->color('success')
                    ->state(fn (Applicant $record): array => self::analysis($record)['matched'] ?? [])
                    ->placeholder('—'),
                TextEntry::make('cv_missing')
                    ->label('Keywords not found')
                    ->badge()
                    ->color('gray')
                    ->state(fn (Applicant $record): array => self::analysis($record)['missing'] ?? [])
                    ->placeholder('—'),
                TextEntry::make('cv_reasons')
                    ->label('Why')
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->state(fn (Applicant $record): array => self::analysis($record)['reasons'] ?? [])
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('cv_analysed_at')
                    ->label('Analysed')
                    ->state(fn (Applicant $record): ?string => CvAnalyser::analysedAt($record)?->diffForHumans())
                    ->placeholder('—'),
            ])
            ->columns(2);
    }

    public static function analyseAction(): Action
    {
        return Action::make('analyseCv')
            ->label('Analyse CV')
            ->icon('heroicon-o-document-magnifying-glass')
            ->color('gray')
            ->visible(fn (Applicant $record): bool => (bool) $record->job_id && (bool) Auth::user()?->can('update', $record))
            ->action(function (Applicant $record): void {
                if (! Auth::user()?->can('update', $record)) {
                    Notification::make()->danger()->title('You cannot analyse this application.')->send();

                    return;
                }

                $analysis = app(CvAnalyser::class)->analyse($record);

                $notification = Notification::make()->title($analysis === null
                    ? 'This application has no job to score against.'
                    : ($analysis['score'] === null ? 'CV analysed, no score' : "CV score: {$analysis['score']}"));

                ($analysis['score'] ?? null) === null ? $notification->warning() : $notification->success();

                $notification->body(implode("\n", array_slice($analysis['reasons'] ?? [], 0, 3)))->send();
            });
    }

    public static function bulkAnalyseAction(): BulkAction
    {
        return BulkAction::make('analyseCvs')
            ->label('Analyse CVs')
            ->icon('heroicon-o-document-magnifying-glass')
            ->requiresConfirmation()
            ->modalDescription('The selected CVs are scored in the background; refresh the list in a minute.')
            ->action(function (Collection $records): void {
                $queued = $records
                    ->filter(fn (Applicant $applicant): bool => $applicant->job_id && (bool) Auth::user()?->can('update', $applicant))
                    ->each(fn (Applicant $applicant) => AnalyseApplicantCv::dispatch($applicant->id))
                    ->count();

                Notification::make()->success()
                    ->title("{$queued} CV(s) queued for analysis")
                    ->body($queued < $records->count() ? ($records->count() - $queued).' skipped (no job, or not yours to change).' : null)
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /** Job page: score every application again, e.g. after changing the CV keywords. */
    public static function rescoreJobAction(): Action
    {
        return Action::make('rescoreCvs')
            ->label('Re-score CVs')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->visible(fn (JobPosition $record): bool => (bool) Auth::user()?->can('update', $record))
            ->requiresConfirmation()
            ->modalDescription('Every application for this job is scored again in the background, using the current skills, CV keywords, minimum experience and work location.')
            ->action(function (JobPosition $record): void {
                if (! Auth::user()?->can('update', $record)) {
                    return;
                }

                $count = Applicant::query()
                    ->where('job_id', $record->id)
                    ->where('company_id', $record->company_id)
                    ->pluck('id')
                    ->each(fn ($id) => AnalyseApplicantCv::dispatch((int) $id))
                    ->count();

                Notification::make()->success()->title("{$count} application(s) queued for CV scoring")->send();
            });
    }

    /** @return array<string, mixed>|null */
    public static function analysis(Applicant $record): ?array
    {
        $analysis = $record->applicant_properties['cv_analysis'] ?? null;

        return is_array($analysis) ? $analysis : null;
    }

    public static function hasFlag(Applicant $record, string $flag): bool
    {
        return in_array($flag, self::analysis($record)['flags'] ?? [], true);
    }

    public static function reasonsText(Applicant $record): ?string
    {
        $reasons = self::analysis($record)['reasons'] ?? [];

        return $reasons ? implode("\n", $reasons) : null;
    }

    public static function scoreColor($state): string
    {
        if ($state === null || $state === '') {
            return 'gray';
        }

        return match (true) {
            (float) $state >= 70 => 'success',
            (float) $state >= 40 => 'warning',
            default              => 'danger',
        };
    }
}
