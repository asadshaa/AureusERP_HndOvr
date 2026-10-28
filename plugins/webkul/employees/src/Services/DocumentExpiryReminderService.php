<?php

namespace Webkul\Employee\Services;

use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;
use Webkul\Employee\Models\EmployeeDocument;
use Webkul\Employee\Services\Attendance\TodayAttendanceDigest;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

/**
 * Daily document expiry reminders (hr:document-expiry-reminders, 09:00
 * Karachi): 30 days before, 7 days before and on expiry, to the company's
 * HR (hr_view_all_records or an HR role, plus document access) and to the
 * employee when the document type is visible to them.
 *
 * Idempotent per document and threshold: the thresholds already sent are
 * recorded on the document (expiry_reminders, cleared when the expiry date
 * changes), claimed under a row lock, so a re-run or an overlap never
 * notifies twice. A missed day catches up: a document 5 days from expiry
 * that never had the 7-day reminder gets it on the next run. Expired
 * documents are reminded once, if they expired in the last 30 days.
 */
class DocumentExpiryReminderService
{
    /** Threshold => [days left from, days left to]. */
    public const THRESHOLDS = [
        '30' => [8, 30],
        '7'  => [1, 7],
        '0'  => [-30, 0],
    ];

    /** @var array<int, Collection<int, User>> */
    private array $hrRecipients = [];

    /** @return array{documents: int, notified: int, skipped: int} */
    public function send(?int $companyId = null, ?string $date = null): array
    {
        $today = $date !== null
            ? CarbonImmutable::parse($date, EmployeeDocument::timezone())->startOfDay()
            : EmployeeDocument::today();
        $result = ['documents' => 0, 'notified' => 0, 'skipped' => 0];

        EmployeeDocument::query()
            ->with(['documentType', 'employee.user', 'company'])
            ->whereNotNull('expires_on')
            ->whereNull('replaced_by_id')
            ->whereDate('expires_on', '>=', $today->subDays(30)->toDateString())
            ->whereDate('expires_on', '<=', $today->addDays(30)->toDateString())
            ->whereHas('employee', fn (Builder $employees) => $employees->where('is_active', true)->whereNull('employees_employees.deleted_at'))
            ->whereHas('company', fn (Builder $companies) => $companies->where('is_active', true))
            ->when($companyId, fn (Builder $query) => $query->where('company_id', $companyId))
            ->orderBy('id')
            ->chunkById(200, function (Collection $documents) use ($today, &$result): void {
                foreach ($documents as $document) {
                    $threshold = $this->thresholdFor($document, $today);

                    if ($threshold === null) {
                        continue;
                    }

                    $sent = $this->remind($document, $threshold, $today);

                    if ($sent === null) {
                        $result['skipped']++;

                        continue;
                    }

                    $result['documents']++;
                    $result['notified'] += $sent;
                }
            });

        return $result;
    }

    public function thresholdFor(EmployeeDocument $document, CarbonImmutable $today): ?string
    {
        $days = $document->daysToExpiry($today);

        if ($days === null || ! $document->documentType?->has_expiry) {
            return null;
        }

        foreach (self::THRESHOLDS as $threshold => [$from, $to]) {
            if ($days >= $from && $days <= $to) {
                return (string) $threshold;
            }
        }

        return null;
    }

    /**
     * The company's HR who may see employee documents.
     *
     * @return Collection<int, User>
     */
    public function hrRecipients(int $companyId): Collection
    {
        return $this->hrRecipients[$companyId] ??= User::query()
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query
                ->where('default_company_id', $companyId)
                ->orWhereHas('allowedCompanies', fn (Builder $companies) => $companies->whereKey($companyId)))
            ->with('roles')
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => ($user->can(HrPermissions::ViewAllRecords) || $user->hasRole(TodayAttendanceDigest::HR_ROLES))
                && ($user->can(HrPermissions::ManageEmployeeDocuments) || $user->can(HrPermissions::ViewEmployeeDocuments))
                && CurrentCompany::canAccess($companyId, $user))
            ->values();
    }

    /** Claim the threshold and notify; null when it was already sent. */
    private function remind(EmployeeDocument $document, string $threshold, CarbonImmutable $today): ?int
    {
        try {
            return DB::transaction(function () use ($document, $threshold, $today): ?int {
                $locked = EmployeeDocument::query()->whereKey($document->id)->lockForUpdate()->first();

                if (! $locked || array_key_exists($threshold, (array) $locked->expiry_reminders)) {
                    return null;
                }

                $locked->forceFill([
                    'expiry_reminders' => (array) $locked->expiry_reminders + [$threshold => now()->toIso8601String()],
                ])->saveQuietly();

                return $this->notify($document, $threshold, $today);
            });
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function notify(EmployeeDocument $document, string $threshold, CarbonImmutable $today): int
    {
        $employee = $document->employee;
        $key = $document->id.':'.$threshold;
        $notified = 0;
        $hr = $this->hrRecipients((int) $document->company_id);

        foreach ($hr as $user) {
            $user->notifyNow($this->notification($document, $threshold, $today, $key, forEmployee: false)->toDatabase());
            $notified++;
        }

        $employeeUser = $employee?->user;

        if ($employeeUser instanceof User
            && $employeeUser->is_active
            && $document->documentType?->visible_to_employee
            && ! $hr->contains(fn (User $user): bool => (int) $user->id === (int) $employeeUser->id)) {
            $employeeUser->notifyNow($this->notification($document, $threshold, $today, $key, forEmployee: true)->toDatabase());
            $notified++;
        }

        return $notified;
    }

    private function notification(EmployeeDocument $document, string $threshold, CarbonImmutable $today, string $key, bool $forEmployee): Notification
    {
        $type = $document->documentType?->name ?? 'Document';
        $whose = $forEmployee ? 'Your '.$type : $type.' of '.($document->employee?->name ?? 'an employee');
        $date = $document->expires_on?->format('d M Y');
        $days = (int) $document->daysToExpiry($today);

        [$title, $color] = match ($threshold) {
            '0'     => [$days === 0 ? "{$whose} expires today" : "{$whose} has expired", 'danger'],
            '7'     => ["{$whose} expires in {$days} day".($days === 1 ? '' : 's'), 'warning'],
            default => ["{$whose} expires in {$days} days", 'warning'],
        };

        $body = "Expiry date: {$date}.".($forEmployee
            ? ' Please give HR the renewed document.'
            : ' Upload the renewed document on the employee\'s Documents tab.');

        return Notification::make()
            ->title($title)
            ->body($body)
            ->icon('heroicon-o-document-text')
            ->color($color)
            ->viewData(['employee_document_reminder' => $key])
            ->actions([
                Action::make('view')
                    ->button()
                    ->label('Open documents')
                    ->url(route('filament.admin.resources.employees.employees.view', ['record' => $document->employee_id])),
            ]);
    }
}
