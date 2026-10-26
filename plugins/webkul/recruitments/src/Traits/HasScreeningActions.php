<?php

namespace Webkul\Recruitment\Traits;

use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Webkul\Recruitment\Enums\ScreeningQuestionType;
use Webkul\Recruitment\Enums\ScreeningStatus;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\ApplicantAnswer;
use Webkul\Recruitment\Models\JobQuestion;
use Webkul\Recruitment\Services\ScreeningService;

/**
 * Applicant page buttons: record the job's screening answers (e.g. from a
 * phone screen) and accept a flagged applicant anyway, with a reason.
 * ScreeningService authorises both again on the server.
 */
trait HasScreeningActions
{
    /** @return array<int, Action> */
    protected function getScreeningActions(): array
    {
        return [
            Action::make('screeningAnswers')
                ->label('Screening answers')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('gray')
                ->visible(fn (Applicant $record): bool => app(ScreeningService::class)->questionsFor($record)->isNotEmpty()
                    && (bool) Auth::user()?->can('update', $record))
                ->fillForm(fn (Applicant $record): array => [
                    'answers' => ApplicantAnswer::query()->where('applicant_id', $record->id)->whereNotNull('job_question_id')
                        ->get()
                        ->mapWithKeys(fn (ApplicantAnswer $answer): array => [$answer->job_question_id => $answer->answer])
                        ->all(),
                ])
                ->schema(fn (Applicant $record): array => app(ScreeningService::class)->questionsFor($record)
                    ->map(fn (JobQuestion $question) => $this->screeningAnswerField($question))
                    ->all())
                ->action(function (Applicant $record, array $data): void {
                    try {
                        $status = app(ScreeningService::class)->recordAnswers($record, (array) ($data['answers'] ?? []), Auth::user());
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Could not save the answers')->body($e->getMessage())->send();

                        return;
                    }

                    Notification::make()
                        ->{$status === ScreeningStatus::FLAGGED ? 'warning' : 'success'}()
                        ->title('Screening: '.($status?->getLabel() ?? 'not complete yet'))
                        ->send();
                }),
            Action::make('acceptDespiteScreening')
                ->label('Accept anyway')
                ->icon('heroicon-o-hand-thumb-up')
                ->color('warning')
                ->visible(fn (Applicant $record): bool => $record->screening_status === ScreeningStatus::FLAGGED->value
                    && (bool) Auth::user()?->can('update', $record))
                ->modalDescription('This applicant did not meet a must-have answer. Accepting keeps them in the pipeline and records your reason.')
                ->schema([
                    Textarea::make('reason')->label('Reason')->required()->maxLength(1000),
                ])
                ->action(function (Applicant $record, array $data): void {
                    try {
                        app(ScreeningService::class)->override($record, Auth::user(), (string) $data['reason']);
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Could not accept the applicant')->body($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title('Applicant accepted despite screening')->send();
                }),
        ];
    }

    protected function screeningAnswerField(JobQuestion $question): mixed
    {
        $name = "answers.{$question->id}";
        $options = collect((array) $question->options)->mapWithKeys(fn (string $option): array => [$option => $option])->all();

        $field = match ($question->type) {
            ScreeningQuestionType::YES_NO        => ToggleButtons::make($name)->options(['yes' => 'Yes', 'no' => 'No'])->inline(),
            ScreeningQuestionType::NUMBER        => TextInput::make($name)->numeric(),
            ScreeningQuestionType::SINGLE_CHOICE => Select::make($name)->options($options),
            ScreeningQuestionType::MULTI_CHOICE  => CheckboxList::make($name)->options($options),
            ScreeningQuestionType::TEXT          => Textarea::make($name)->rows(2),
        };

        return $field->label($question->label)->helperText($question->help_text);
    }
}
