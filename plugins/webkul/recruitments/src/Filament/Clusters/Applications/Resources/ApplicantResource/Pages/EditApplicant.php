<?php

namespace Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Webkul\Chatter\Filament\Actions\ChatterAction;
use Webkul\Recruitment\Enums\ApplicationStatus;
use Webkul\Recruitment\Enums\RecruitmentState;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource;
use Webkul\Recruitment\Filament\Schemas\CvAnalysisComponents;
use Webkul\Recruitment\Mail\ApplicantRefuseMail;
use Webkul\Recruitment\Mail\InterviewerAssignedMail;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\RefuseReason;
use Webkul\Recruitment\Services\RecruitmentMailer;
use Webkul\Recruitment\Traits\HandlesEmployeeConversion;
use Webkul\Recruitment\Traits\HasScreeningActions;
use Webkul\Security\Models\User;
use Webkul\Support\Traits\HasRecordNavigationTabs;

class EditApplicant extends EditRecord
{
    use HandlesEmployeeConversion;
    use HasRecordNavigationTabs;
    use HasScreeningActions;

    protected static string $resource = ApplicantResource::class;

    protected array $notificationData = [];

    protected array $interviewerChanges = [];

    /** @var array<string, mixed> the candidate's email, phone and LinkedIn as submitted */
    protected array $candidateContact = [];

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title(__('recruitments::filament/clusters/applications/resources/applicant/pages/edit-applicant.notification.title'))
            ->body(__('recruitments::filament/clusters/applications/resources/applicant/pages/edit-applicant.notification.body'));
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->getScreeningActions(),
            CvAnalysisComponents::analyseAction(),
            Action::make('state')
                ->hiddenLabel()
                ->icon(function ($record) {
                    if ($record->state == RecruitmentState::DONE->value) {
                        return RecruitmentState::DONE->getIcon();
                    } elseif ($record->state == RecruitmentState::BLOCKED->value) {
                        return RecruitmentState::BLOCKED->getIcon();
                    } elseif ($record->state == RecruitmentState::NORMAL->value) {
                        return RecruitmentState::NORMAL->getIcon();
                    }
                })
                ->iconButton()
                ->color(function ($record) {
                    if ($record->state == RecruitmentState::DONE->value) {
                        return RecruitmentState::DONE->getColor();
                    } elseif ($record->state == RecruitmentState::BLOCKED->value) {
                        return RecruitmentState::BLOCKED->getColor();
                    } elseif ($record->state == RecruitmentState::NORMAL->value) {
                        return RecruitmentState::NORMAL->getColor();
                    }
                })
                ->schema([
                    ToggleButtons::make('state')
                        ->inline()
                        ->options(RecruitmentState::class),
                ])
                ->fillForm(fn ($record) => [
                    'state' => $record->state,
                ])
                ->tooltip(function ($record) {
                    if ($record->state == RecruitmentState::DONE->value) {
                        return RecruitmentState::DONE->getLabel();
                    } elseif ($record->state == RecruitmentState::BLOCKED->value) {
                        return RecruitmentState::BLOCKED->getLabel();
                    } elseif ($record->state == RecruitmentState::NORMAL->value) {
                        return RecruitmentState::NORMAL->getLabel();
                    }
                })
                ->action(function (Applicant $record, $data) {
                    $record->update($data);
                    $record->refresh();

                    Notification::make()
                        ->success()
                        ->title(__('recruitments::filament/clusters/applications/resources/applicant/pages/edit-applicant.header-actions.state.notification.title'))
                        ->body(__('recruitments::filament/clusters/applications/resources/applicant/pages/edit-applicant.header-actions.state.notification.body'))
                        ->send();
                }),
            Action::make('gotoEmployee')
                ->tooltip(__('recruitments::filament/clusters/applications/resources/applicant/pages/edit-applicant.goto-employee'))
                ->visible(fn ($record) => $record->candidate->employee_id
                    || ($record->application_status->value == ApplicationStatus::HIRED->value && static::userCanConvert()))
                ->icon('heroicon-s-arrow-top-right-on-square')
                ->iconButton()
                ->action(fn (Applicant $record) => $this->convertAndRedirect(fn () => $record->createEmployee(), 'view')),
            ChatterAction::make()
                ->resource(static::$resource)
                ->activityPlans($this->getRecord()->activityPlans()),
            Action::make('createEmployee')
                ->label(__('recruitments::filament/clusters/applications/resources/applicant/pages/edit-applicant.create-employee'))
                ->hidden(fn ($record) => $record->application_status->value == ApplicationStatus::HIRED->value || $record->candidate->employee_id || $record->refuse_reason_id)
                ->visible(fn (): bool => static::userCanConvert())
                ->action(fn (Applicant $record) => $this->convertAndRedirect(fn () => $record->createEmployee(), 'edit')),
            DeleteAction::make()
                ->successNotification(
                    Notification::make()
                        ->success()
                        ->title(__('recruitments::filament/clusters/applications/resources/applicant/pages/edit-applicant.header-actions.delete.notification.title'))
                        ->body(__('recruitments::filament/clusters/applications/resources/applicant/pages/edit-applicant.header-actions.delete.notification.body'))
                ),
            ForceDeleteAction::make()
                ->successNotification(
                    Notification::make()
                        ->success()
                        ->title(__('recruitments::filament/clusters/applications/resources/applicant/pages/edit-applicant.header-actions.force-delete.notification.title'))
                        ->body(__('recruitments::filament/clusters/applications/resources/applicant/pages/edit-applicant.header-actions.force-delete.notification.body'))
                ),
            RestoreAction::make()
                ->successNotification(
                    Notification::make()
                        ->info()
                        ->title(__('recruitments::filament/clusters/applications/resources/applicant/pages/edit-applicant.header-actions.restore.notification.title'))
                        ->body(__('recruitments::filament/clusters/applications/resources/applicant/pages/edit-applicant.header-actions.restore.notification.body'))
                ),
            Action::make('refuse')
                ->modalIcon('heroicon-s-bug-ant')
                ->hidden(fn ($record) => $record->refuse_reason_id || $record->application_status->value === ApplicationStatus::ARCHIVED->value)
                ->modalHeading(__('recruitments::filament/clusters/applications/resources/applicant/pages/edit-applicant.header-actions.refuse.title'))
                ->schema(function (Schema $schema, $record) {
                    return $schema->components([
                        ToggleButtons::make('refuse_reason_id')
                            ->hiddenLabel()
                            ->inline()
                            ->live()
                            ->required()
                            ->options(fn (): array => RefuseReason::optionsFor($record->company_id ? (int) $record->company_id : null))
                            ->in(fn (): array => array_keys(RefuseReason::optionsFor($record->company_id ? (int) $record->company_id : null))),
                        Toggle::make('notify')
                            ->inline()
                            ->live()
                            ->default(true)
                            ->visible(fn (Get $get) => $get('refuse_reason_id'))
                            ->label(__('recruitments::filament/clusters/applications/resources/applicant/pages/edit-applicant.header-actions.refuse.form.fields.notify')),
                        TextInput::make('email')
                            ->visible(fn (Get $get) => $get('notify') && $get('refuse_reason_id'))
                            ->default($record->candidate?->email_from)
                            ->email()
                            ->required(fn (Get $get): bool => (bool) $get('notify') && filled($get('refuse_reason_id')))
                            ->maxLength(255)
                            ->label(__('recruitments::filament/clusters/applications/resources/applicant/pages/edit-applicant.header-actions.refuse.form.fields.email-to')),
                    ]);
                })
                ->action(function (array $data, Applicant $record) {
                    // Only a reason of the application's company (or a shared one), whatever was posted.
                    $refuseReason = RefuseReason::query()->availableTo($record->company_id ? (int) $record->company_id : null)->find($data['refuse_reason_id'] ?? null);

                    if (! $refuseReason) {
                        return null;
                    }

                    $record->setAsRefused($refuseReason?->id);

                    if (isset($data['notify']) && $data['notify']) {
                        $data = $this->prepareApplicantRefuseNotificationPayload($data);

                        app(RecruitmentMailer::class)->queue(
                            ApplicantRefuseMail::class,
                            "recruitments::mails.{$refuseReason?->template}",
                            $data,
                            $record->company_id,
                        );
                    }

                    Notification::make()
                        ->info()
                        ->title(__('recruitments::filament/clusters/applications/resources/applicant/pages/edit-applicant.header-actions.refuse.notification.title'))
                        ->body(__('recruitments::filament/clusters/applications/resources/applicant/pages/edit-applicant.header-actions.refuse.notification.body'))
                        ->send();
                }),
            Action::make('restore')
                ->hidden(fn ($record) => ! $record->refuse_reason_id)
                ->modalHeading(__('recruitments::filament/clusters/applications/resources/applicant/pages/edit-applicant.header-actions.reopen.title'))
                ->requiresConfirmation()
                ->color('gray')
                ->action(function (Applicant $record) {
                    $record->reopen();

                    Notification::make()
                        ->info()
                        ->title(__('recruitments::filament/clusters/applications/resources/applicant/pages/edit-applicant.header-actions.reopen.notification.title'))
                        ->body(__('recruitments::filament/clusters/applications/resources/applicant/pages/edit-applicant.header-actions.reopen.notification.body'))
                        ->send();
                }),
        ];
    }

    protected function afterSave(): void
    {
        $this->saveCandidateContact();

        // handleApplicationUpdation() on the model computes these onto
        // $this->record (a transient, non-persisted property -- see
        // Applicant::$notificationData/$interviewerChanges), not onto this
        // page. This page's own same-named properties are a separate,
        // always-empty object and were never actually populated.
        $this->notificationData = $this->record->notificationData;
        $this->interviewerChanges = $this->record->interviewerChanges;

        if (! empty($this->notificationData)) {
            $this->sendApplicationConfirmationNotification();
        }

        if (! empty($this->interviewerChanges)) {
            $this->record->interviewer()->sync($this->interviewerChanges['new']);

            $this->sendInterviewerAssignmentNotification();
        }
    }

    /**
     * The candidate's email, phone and LinkedIn on the application form are
     * the candidate's own fields: saved on the candidate the application now
     * points to (same company, scoped candidate picker).
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Not applicant columns: kept for the candidate (saveCandidateContact()).
        $this->candidateContact = [];

        foreach (['email_from', 'phone', 'linkedin_profile'] as $field) {
            if (array_key_exists("candidate_{$field}", $data)) {
                $this->candidateContact[$field] = $data["candidate_{$field}"];
            }

            unset($data["candidate_{$field}"]);
        }

        return $data;
    }

    protected function saveCandidateContact(): void
    {
        $contact = $this->candidateContact;
        $candidate = $this->record->refresh()->candidate;

        if ($contact === [] || ! $candidate || (int) $candidate->company_id !== (int) $this->record->company_id) {
            return;
        }

        $candidate->fill(array_map(fn ($value): ?string => filled($value) ? trim((string) $value) : null, $contact));

        if ($candidate->isDirty()) {
            $candidate->save();
        }
    }

    protected function sendApplicationConfirmationNotification(): void
    {
        // Queued, and only once per application (also when it came in through intake).
        if (! app(RecruitmentMailer::class)->confirmApplication($this->record)) {
            return;
        }

        $data = $this->prepareCandidateNotificationPayload();
        $data['from'] = app(RecruitmentMailer::class)->from($this->record->company, Auth::user());
        $viewName = 'recruitments::mails.application-confirm';

        $messageData = [
            'from' => [
                'company' => Auth::user()->defaultCompany->toArray(),
            ],
            'body' => view($viewName, ['payload' => $data])->render(),
            'type' => 'comment',
        ];

        $this->record->addMessage($messageData, Auth::user()->id);
    }

    protected function sendInterviewerAssignmentNotification(): void
    {
        $oldInterviewers = collect($this->interviewerChanges['old']);
        $newInterviewers = collect($this->interviewerChanges['new']);

        $addedInterviewers = $newInterviewers->diff($oldInterviewers);

        $addedInterviewers = $addedInterviewers->reject(fn ($id) => $id == Auth::id());

        if ($addedInterviewers->isNotEmpty()) {
            foreach ($addedInterviewers as $interviewerId) {
                $interviewer = User::find($interviewerId);

                if ($interviewer) {
                    $data = $this->prepareInterviewerNotificationPayload($interviewer);

                    app(RecruitmentMailer::class)->queue(
                        InterviewerAssignedMail::class,
                        'recruitments::mails.interviewer-assigned',
                        $data,
                        $this->record->company_id,
                    );
                }
            }
        }
    }

    private function prepareCandidateNotificationPayload(): array
    {
        return [
            'record_name'  => $this->record->candidate->name,
            'job_position' => $jobPosition = $this->record->job?->name,
            'subject'      => __('recruitments::filament/clusters/applications/resources/applicant/pages/edit-applicant.mail.application-confirm.subject', [
                'job_position' => $jobPosition,
            ]),
            'to' => [
                'address' => $this->record->candidate->email_from,
                'name'    => $this->record->candidate->name,
            ],
        ];
    }

    private function prepareInterviewerNotificationPayload($interviewer): array
    {
        return [
            'record_name' => $candidateName = $this->record->candidate->name,
            'record_url'  => $this->getRedirectUrl(),
            'subject'     => __('recruitments::filament/clusters/applications/resources/applicant/pages/edit-applicant.mail.interviewer-assigned.subject', [
                'applicant' => $candidateName,
            ]),
            'to' => [
                'address' => $interviewer->email,
                'name'    => $interviewer->name,
            ],
        ];
    }

    private function prepareApplicantRefuseNotificationPayload(array $data): array
    {
        return [
            'applicant_name' => $this->record->candidate->name,
            'subject'        => __('recruitments::filament/clusters/applications/resources/applicant/pages/edit-applicant.mail.application-refused.subject', [
                'application' => $this->record->job?->name,
            ]),
            'to' => [
                'address' => $data['email'] ?? $this->record?->candidate?->email_from,
                'name'    => $this->record?->candidate?->name,
            ],
        ];
    }
}
