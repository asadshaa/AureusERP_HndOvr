<?php

namespace Webkul\Recruitment\Filament\Schemas;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Webkul\Employee\Filament\Resources\EmployeeResource;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Recruitment\Enums\JobOfferStatus;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\JobOfferResource;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\JobOffer;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\OfferLetterTemplate;
use Webkul\Recruitment\Services\JobOfferService;
use Webkul\Recruitment\Services\OfferLetterRenderer;
use Webkul\Security\Models\User;

/**
 * Job offer form, columns and actions (HR parity 3.6), shared by the
 * application's "Offers" tab and header, the Offers list and the offer page.
 * Visibility only hides buttons and the salary; JobOfferService checks
 * every action again (rights, company, status, dates, salary visibility).
 */
class JobOfferComponents
{
    /**
     * The offer's terms. Salary fields only for those who may see it.
     *
     * @return array<int, mixed>
     */
    public static function formFields(int $companyId, bool $withSalary): array
    {
        $currency = (string) (config('recruitment.offers.currency', 'PKR'));

        return [
            Section::make(__('Position'))
                ->columns(2)
                ->schema([
                    Select::make('job_id')
                        ->label(__('Job position'))
                        ->options(fn (): array => JobPosition::query()->where('company_id', $companyId)->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable(),
                    TextInput::make('designation')
                        ->label(__('Designation (as written in the letter)'))
                        ->maxLength(255),
                    Select::make('department_id')
                        ->label(__('Department'))
                        ->options(fn (): array => Department::query()->where('company_id', $companyId)->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable(),
                    Select::make('reporting_manager_id')
                        ->label(__('Reporting manager'))
                        ->options(fn (): array => Employee::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable(),
                ]),
            Section::make(__('Dates'))
                ->columns(2)
                ->schema([
                    DatePicker::make('joining_date')
                        ->label(__('Joining date'))
                        ->required()
                        ->minDate(fn (): string => app(JobOfferService::class)->today()),
                    DatePicker::make('expiry_date')
                        ->label(__('Offer valid until'))
                        ->helperText(__('The candidate can accept until the end of this day. Not after the joining date.'))
                        ->required()
                        ->minDate(fn (): string => app(JobOfferService::class)->today()),
                ]),
            Section::make(__('Salary (:currency, monthly)', ['currency' => $currency]))
                ->description(__('Visible only to HR, hiring managers and the approvers of the offer.'))
                ->visible($withSalary)
                ->schema([
                    TextInput::make('salary_gross')
                        ->label(__('Monthly gross salary'))
                        ->numeric()
                        ->minValue(1)
                        ->prefix($currency)
                        ->helperText(__('Above the company threshold (:amount) the CEO also approves.', ['amount' => OfferLetterRenderer::money(app(JobOfferService::class)->ceoThreshold($companyId), $currency)]))
                        ->required($withSalary),
                    Fieldset::make(__('Breakdown (optional; must add up to the gross)'))
                        ->columns(4)
                        ->schema(collect(JobOffer::BREAKDOWN_COMPONENTS)
                            ->map(fn (string $label, string $key): TextInput => TextInput::make("salary_breakdown.{$key}")
                                ->label(__($label))
                                ->numeric()
                                ->minValue(0))
                            ->values()
                            ->all()),
                ]),
            Section::make(__('Terms'))
                ->columns(2)
                ->schema([
                    TextInput::make('probation_months')
                        ->label(__('Probation (months)'))
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->maxValue(24),
                    TextInput::make('working_hours')
                        ->label(__('Working hours'))
                        ->maxLength(255),
                    Textarea::make('benefits')
                        ->label(__('Benefits'))
                        ->placeholder(__('e.g. Medical cover for self and family, provident fund, 30 days annual leave, fuel allowance'))
                        ->rows(3)
                        ->maxLength(5000)
                        ->columnSpanFull(),
                    Select::make('template_id')
                        ->label(__('Letter template'))
                        ->options(fn (): array => OfferLetterTemplate::query()->where('company_id', $companyId)->active()->orderByDesc('is_default')->orderBy('name')->pluck('name', 'id')->all()),
                    Textarea::make('internal_notes')
                        ->label(__('Internal notes (never shown to the candidate)'))
                        ->rows(2)
                        ->maxLength(5000),
                ]),
        ];
    }

    /**
     * What the columns and the row actions read (approval routing included),
     * loaded once per page instead of once per row.
     *
     * @return array<int, string>
     */
    public static function tableRelations(): array
    {
        return [
            'candidate', 'applicant.candidate', 'department', 'hiringManager', 'company.currency', 'currency', 'hiredEmployee',
            'job.manager.user', 'job.recruiter.employee.parent.user', 'applicant.recruiter.employee.parent.user', 'creator.employee.parent.user',
            'approvalRequest.workflow.steps',
            'approvalRequest.requester.employee.parent.user',
            'approvalRequest.requester.employee.department.manager.user',
            'approvalRequest.requester.employee.team.manager.user',
        ];
    }

    /** @return array<int, TextColumn> */
    public static function columns(bool $withApplicant): array
    {
        return array_values(array_filter([
            TextColumn::make('reference')
                ->label(__('Offer'))
                ->searchable()
                ->sortable(),
            $withApplicant
                ? TextColumn::make('candidate.name')
                    ->label(__('Candidate'))
                    ->url(fn (JobOffer $record): ?string => $record->applicant && (static::user()?->can('view', $record->applicant) ?? false)
                        ? ApplicantResource::getUrl('view', ['record' => $record->applicant])
                        : null)
                    ->searchable()
                : null,
            TextColumn::make('position')
                ->label(__('Position'))
                ->state(fn (JobOffer $record): string => $record->positionLabel())
                ->description(fn (JobOffer $record): ?string => $record->department?->name),
            TextColumn::make('status')
                ->label(__('Status'))
                ->badge()
                ->sortable(),
            TextColumn::make('salary')
                ->label(__('Monthly gross'))
                ->state(fn (JobOffer $record): string => static::service()->canSeeSalary(static::user(), $record)
                    ? OfferLetterRenderer::money($record->salary_gross, $record->currencyCode())
                    : __('Restricted'))
                ->color(fn (JobOffer $record): ?string => $record->requires_ceo_approval ? 'warning' : null)
                ->tooltip(fn (JobOffer $record): ?string => $record->requires_ceo_approval ? __('Above the CEO approval threshold') : null),
            TextColumn::make('joining_date')
                ->label(__('Joining'))
                ->date('d M Y')
                ->sortable(),
            TextColumn::make('expiry_date')
                ->label(__('Valid until'))
                ->date('d M Y')
                ->color(fn (JobOffer $record): ?string => $record->status === JobOfferStatus::SENT && $record->expiry_date->toDateString() <= now(OfferLetterRenderer::timezone())->addDays(2)->toDateString() ? 'danger' : null)
                ->sortable(),
            TextColumn::make('hiringManager.name')
                ->label(__('Hiring manager'))
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('sent_at')
                ->label(__('Sent'))
                ->dateTime('d M Y H:i', OfferLetterRenderer::timezone())
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('updated_at')
                ->label(__('Updated'))
                ->since()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ]));
    }

    /** "Make offer" on an application (page header and Offers tab). */
    public static function makeOfferAction(Closure $applicant, string $name = 'makeOffer'): Action
    {
        return Action::make($name)
            ->label(__('Make offer'))
            ->icon('heroicon-o-document-text')
            ->color('success')
            ->visible(function () use ($applicant): bool {
                $record = $applicant();

                return $record instanceof Applicant
                    && ! $record->refuse_reason_id
                    && $record->is_active
                    && ! $record->trashed()
                    && static::service()->canManage(static::user(), $record)
                    && ! JobOffer::query()->where('applicant_id', $record->id)->open()->exists();
            })
            ->modalHeading(__('Make an offer'))
            ->modalDescription(__('Saved as a draft. Submit it for approval from the offer when it is ready.'))
            ->modalSubmitActionLabel(__('Save draft'))
            ->modalWidth('4xl')
            ->fillForm(fn (): array => static::service()->defaults($applicant(), static::user()))
            ->schema(fn (): array => static::formFields((int) $applicant()->company_id, static::service()->canSeeSalaryFor(static::user(), (int) $applicant()->company_id)))
            ->action(function (array $data, Action $action) use ($applicant) {
                $offer = static::run($action, fn () => static::service()->createDraft($applicant(), $data, static::user()), __('Offer drafted'));

                return redirect()->to(JobOfferResource::getUrl('view', ['record' => $offer]));
            });
    }

    /**
     * Everything that can be done to an offer, in workflow order.
     *
     * @return array<int, Action>
     */
    public static function recordActions(bool $withView = true): array
    {
        return array_values(array_filter([
            $withView ? static::viewAction() : null,
            static::editAction(),
            static::submitAction(),
            static::approveAction(),
            static::returnAction(),
            static::rejectAction(),
            static::generateLetterAction(),
            static::downloadLetterAction(),
            static::sendAction(),
            static::createEmployeeAction(),
            static::openEmployeeAction(),
            static::withdrawAction(),
        ]));
    }

    public static function viewAction(): Action
    {
        return Action::make('openOffer')
            ->label(__('Open'))
            ->icon('heroicon-o-eye')
            ->url(fn (JobOffer $record): string => JobOfferResource::getUrl('view', ['record' => $record]));
    }

    public static function editAction(): Action
    {
        return Action::make('editOffer')
            ->label(__('Edit'))
            ->icon('heroicon-o-pencil-square')
            ->visible(fn (JobOffer $record): bool => static::service()->canEdit(static::user(), $record))
            ->modalHeading(fn (JobOffer $record): string => __('Edit offer :reference', ['reference' => $record->reference]))
            ->modalWidth('4xl')
            ->fillForm(function (JobOffer $record): array {
                $data = $record->only(['job_id', 'designation', 'department_id', 'reporting_manager_id', 'probation_months', 'working_hours', 'benefits', 'template_id', 'internal_notes']);
                $data['joining_date'] = $record->joining_date?->toDateString();
                $data['expiry_date'] = $record->expiry_date?->toDateString();

                if (static::service()->canSeeSalary(static::user(), $record)) {
                    $data['salary_gross'] = $record->salary_gross;
                    $data['salary_breakdown'] = (array) $record->salary_breakdown;
                }

                return $data;
            })
            ->schema(fn (JobOffer $record): array => static::formFields((int) $record->company_id, static::service()->canSeeSalary(static::user(), $record)))
            ->action(function (JobOffer $record, array $data, Action $action): void {
                static::run($action, fn () => static::service()->update($record, $data, static::user()), __('Offer saved'));
            });
    }

    public static function submitAction(): Action
    {
        return Action::make('submitOffer')
            ->label(fn (JobOffer $record): string => $record->status === JobOfferStatus::RETURNED ? __('Resubmit for approval') : __('Submit for approval'))
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->visible(fn (JobOffer $record): bool => static::service()->canSubmit(static::user(), $record))
            ->modalHeading(fn (JobOffer $record): string => $record->status === JobOfferStatus::RETURNED ? __('Resubmit the offer') : __('Submit the offer for approval'))
            ->modalDescription(__('Hiring manager, then HR, then the CEO when the salary is above the company threshold.'))
            ->schema([
                Textarea::make('note')->label(__('Note for the approvers (optional)'))->rows(2)->maxLength(1000),
            ])
            ->action(function (JobOffer $record, array $data, Action $action): void {
                static::run($action, fn () => static::service()->submit($record, static::user(), $data['note'] ?? null), __('Offer submitted for approval'));
            });
    }

    public static function approveAction(): Action
    {
        return Action::make('approveOffer')
            ->label(__('Approve'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (JobOffer $record): bool => static::service()->canDecide(static::user(), $record))
            ->schema([
                Textarea::make('comment')->label(__('Comment (optional)'))->rows(2)->maxLength(1000),
            ])
            ->action(function (JobOffer $record, array $data, Action $action): void {
                static::run($action, fn () => static::service()->approve($record, static::user(), $data['comment'] ?? null), __('Offer approved'));
            });
    }

    public static function returnAction(): Action
    {
        return Action::make('returnOffer')
            ->label(__('Return for changes'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (JobOffer $record): bool => static::service()->canDecide(static::user(), $record))
            ->modalDescription(__('The requester changes the offer and resubmits it; it then starts again from the first approval step.'))
            ->schema([
                Textarea::make('comment')->label(__('What needs to change'))->required()->rows(3)->maxLength(1000),
            ])
            ->action(function (JobOffer $record, array $data, Action $action): void {
                static::run($action, fn () => static::service()->returnForChanges($record, static::user(), (string) $data['comment']), __('Offer returned for changes'));
            });
    }

    public static function rejectAction(): Action
    {
        return Action::make('rejectOffer')
            ->label(__('Reject'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (JobOffer $record): bool => static::service()->canDecide(static::user(), $record))
            ->schema([
                Textarea::make('reason')->label(__('Reason'))->required()->rows(3)->maxLength(1000),
            ])
            ->action(function (JobOffer $record, array $data, Action $action): void {
                static::run($action, fn () => static::service()->reject($record, static::user(), (string) $data['reason']), __('Offer rejected'));
            });
    }

    public static function generateLetterAction(): Action
    {
        return Action::make('generateLetter')
            ->label(fn (JobOffer $record): string => filled($record->letter_path) ? __('Regenerate letter') : __('Generate letter'))
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('Renders the offer into the company letter template (PDF).'))
            ->visible(fn (JobOffer $record): bool => in_array($record->status, [JobOfferStatus::APPROVED, JobOfferStatus::SENT], true)
                && static::service()->canManage(static::user(), $record)
                && static::service()->canSeeSalary(static::user(), $record))
            ->action(function (JobOffer $record, Action $action): void {
                static::run($action, fn () => static::service()->generateLetter($record, static::user()), __('Offer letter generated'));
            });
    }

    public static function downloadLetterAction(): Action
    {
        return Action::make('downloadLetter')
            ->label(__('Download letter'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (JobOffer $record): bool => filled($record->letter_path) && static::service()->canSeeSalary(static::user(), $record))
            ->url(fn (JobOffer $record): ?string => static::service()->letterDownloadUrl($record), shouldOpenInNewTab: true);
    }

    public static function sendAction(): Action
    {
        return Action::make('sendOffer')
            ->label(fn (JobOffer $record): string => $record->status === JobOfferStatus::SENT ? __('Re-send to candidate') : __('Send to candidate'))
            ->icon('heroicon-o-envelope')
            ->color('success')
            ->visible(fn (JobOffer $record): bool => static::service()->canSend(static::user(), $record))
            ->modalHeading(__('Send the offer'))
            ->modalDescription(fn (JobOffer $record): string => $record->status === JobOfferStatus::SENT
                ? __('A new link is emailed; the link in the earlier email stops working.')
                : __('The candidate gets the letter (PDF) and a link to accept or decline until the end of :date.', ['date' => OfferLetterRenderer::date($record->expiry_date)]))
            ->fillForm(fn (JobOffer $record): array => ['email' => $record->sent_to ?? $record->candidate?->email_from ?? $record->applicant?->candidate?->email_from])
            ->schema([
                TextInput::make('email')->label(__('Candidate email'))->email()->required()->maxLength(255),
            ])
            ->modalSubmitActionLabel(__('Send'))
            ->action(function (JobOffer $record, array $data, Action $action): void {
                static::run($action, fn () => static::service()->send($record, static::user(), $data['email'] ?? null), __('Offer sent to the candidate'));
            });
    }

    public static function createEmployeeAction(): Action
    {
        return Action::make('createEmployeeFromOffer')
            ->label(__('Create employee'))
            ->icon('heroicon-o-user-plus')
            ->color('success')
            ->visible(fn (JobOffer $record): bool => static::service()->canCreateEmployee(static::user(), $record))
            ->modalHeading(__('Create the employee'))
            ->modalDescription(__('From the accepted offer; onboarding starts automatically.'))
            ->fillForm(fn (JobOffer $record): array => [
                'joining_date'  => $record->joining_date?->toDateString(),
                'department_id' => $record->department_id,
                'parent_id'     => $record->reporting_manager_id,
            ])
            ->schema(fn (JobOffer $record): array => [
                TextEntry::make('summary')
                    ->hiddenLabel()
                    ->state(fn (): string => $record->candidateName().' · '.$record->positionLabel()
                        .(static::service()->canSeeSalary(static::user(), $record) ? ' · '.OfferLetterRenderer::money($record->salary_gross, $record->currencyCode()).' '.__('per month') : '')),
                DatePicker::make('joining_date')->label(__('Joining date'))->required(),
                Select::make('department_id')
                    ->label(__('Department'))
                    ->options(fn (): array => Department::query()->where('company_id', $record->company_id)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Select::make('parent_id')
                    ->label(__('Line manager'))
                    ->options(fn (): array => Employee::query()->where('company_id', $record->company_id)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
            ])
            ->modalSubmitActionLabel(__('Create employee'))
            ->action(function (JobOffer $record, array $data, Action $action) {
                $employee = static::run($action, fn () => static::service()->createEmployee($record, static::user(), $data), __('Employee created'));

                return redirect()->to(EmployeeResource::getUrl('view', ['record' => $employee]));
            });
    }

    public static function openEmployeeAction(): Action
    {
        return Action::make('openEmployee')
            ->label(__('Open employee'))
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('gray')
            ->visible(fn (JobOffer $record): bool => $record->employee_id !== null && $record->hiredEmployee && (static::user()?->can('view', $record->hiredEmployee) ?? false))
            ->url(fn (JobOffer $record): ?string => $record->hiredEmployee ? EmployeeResource::getUrl('view', ['record' => $record->hiredEmployee]) : null);
    }

    public static function withdrawAction(): Action
    {
        return Action::make('withdrawOffer')
            ->label(__('Withdraw'))
            ->icon('heroicon-o-archive-box-x-mark')
            ->color('danger')
            ->visible(fn (JobOffer $record): bool => static::service()->canWithdraw(static::user(), $record))
            ->modalHeading(__('Withdraw the offer'))
            ->modalDescription(fn (JobOffer $record): string => $record->status === JobOfferStatus::SENT
                ? __('The candidate\'s link stops working. Tell the candidate yourself if needed.')
                : __('Nobody needs to decide it any more.'))
            ->schema([
                Textarea::make('reason')->label(__('Reason'))->rows(2)->maxLength(1000),
            ])
            ->action(function (JobOffer $record, array $data, Action $action): void {
                static::run($action, fn () => static::service()->withdraw($record, static::user(), $data['reason'] ?? null), __('Offer withdrawn'));
            });
    }

    /** The application's latest offer, for the application page's right column. */
    public static function applicantInfolistSection(): Section
    {
        $latest = fn (Applicant $record): ?JobOffer => $record->offers()->latest('id')->first();

        return Section::make(__('Offer'))
            ->visible(fn (Applicant $record): bool => $latest($record) !== null)
            ->schema([
                TextEntry::make('latest_offer_status')
                    ->label(__('Status'))
                    ->badge()
                    ->state(fn (Applicant $record): ?JobOfferStatus => $latest($record)?->status)
                    ->url(fn (Applicant $record): ?string => ($offer = $latest($record)) && (static::user()?->can('view', $offer) ?? false)
                        ? JobOfferResource::getUrl('view', ['record' => $offer])
                        : null),
                TextEntry::make('latest_offer_dates')
                    ->label(__('Joining / valid until'))
                    ->state(fn (Applicant $record): ?string => ($offer = $latest($record))
                        ? OfferLetterRenderer::date($offer->joining_date).' / '.OfferLetterRenderer::date($offer->expiry_date)
                        : null),
                TextEntry::make('latest_offer_sent')
                    ->label(__('Sent'))
                    ->state(fn (Applicant $record): ?string => $latest($record)?->sent_at?->timezone(OfferLetterRenderer::timezone())->format('d M Y H:i'))
                    ->placeholder(__('Not sent yet')),
            ]);
    }

    /**
     * Runs a service call; refusals and validation problems become a message
     * and keep the modal open.
     */
    protected static function run(Action $action, Closure $call, string $successTitle): mixed
    {
        $result = null;

        try {
            $result = $call();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title(__('Please check the offer'))->body(collect($e->errors())->flatten()->implode(' '))->send();
            $action->halt();
        } catch (AuthorizationException|RuntimeException $e) {
            Notification::make()->danger()->title(__('Could not update the offer'))->body($e->getMessage())->send();
            $action->halt();
        }

        Notification::make()->success()->title($successTitle)->send();

        return $result;
    }

    protected static function service(): JobOfferService
    {
        return app(JobOfferService::class);
    }

    protected static function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
