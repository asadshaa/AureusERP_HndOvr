<?php

namespace Webkul\Employee\Services;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Webkul\Employee\Models\Announcement;
use Webkul\Employee\Models\AnnouncementRead;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

/**
 * Company announcements (HR Parity 2.6). HR with hr_manage_announcements
 * posts them in the active company; everyone else only ever sees the live
 * ones (published, not expired) of their active company whose audience
 * includes them: the whole company, or their department or a parent of it.
 * The body is sanitised on save and again on display.
 *
 * Bound as a scoped service: the home dashboard asks for the feed in the
 * widget's canView() and again for its view, one query in total.
 */
class AnnouncementService
{
    /** @var array<string, Collection<int, Announcement>> */
    private array $feedCache = [];

    public function canManage(User $user, ?int $companyId = null): bool
    {
        $active = CurrentCompany::id($user);

        return $user->can(HrPermissions::ManageAnnouncements)
            && $active !== null
            && ($companyId === null || $companyId === $active);
    }

    /** @param  array<string, mixed>  $data */
    public function create(User $actor, array $data): Announcement
    {
        $companyId = CurrentCompany::id($actor);

        if ($companyId === null || ! $this->canManage($actor, $companyId)) {
            throw new AuthorizationException('Only HR can post announcements.');
        }

        $data = $this->validated($data, $companyId);

        return DB::transaction(function () use ($actor, $companyId, $data): Announcement {
            $announcement = Announcement::query()->create(Arr::except($data, ['department_ids']) + [
                'company_id' => $companyId,
                'creator_id' => $actor->getKey(),
            ]);
            $announcement->departments()->sync($data['audience'] === Announcement::AudienceDepartments ? $data['department_ids'] : []);
            $this->forgetFeeds();

            return $announcement;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Announcement $announcement, User $actor, array $data): Announcement
    {
        if (! $this->canManage($actor, (int) $announcement->company_id)) {
            throw new AuthorizationException('Only HR of this company can change the announcement.');
        }

        $data = $this->validated($data, (int) $announcement->company_id);

        return DB::transaction(function () use ($announcement, $data): Announcement {
            $announcement->update(Arr::except($data, ['department_ids']));
            $announcement->departments()->sync($data['audience'] === Announcement::AudienceDepartments ? $data['department_ids'] : []);
            $this->forgetFeeds();

            return $announcement->fresh();
        });
    }

    public function delete(Announcement $announcement, User $actor): void
    {
        if (! $this->canManage($actor, (int) $announcement->company_id)) {
            throw new AuthorizationException('Only HR of this company can remove the announcement.');
        }

        $announcement->delete();
        $this->forgetFeeds();
    }

    /**
     * Live announcements of the company that the user is in the audience of.
     * $employeeKnown: $employee is already the user's employee record in the
     * company (or null: they have none), so it is not looked up again.
     *
     * @return Builder<Announcement>
     */
    public function visibleQuery(User $user, int $companyId, ?Employee $employee = null, bool $employeeKnown = false): Builder
    {
        $query = Announcement::query()->where('employees_announcements.company_id', $companyId);

        if (! CurrentCompany::canAccess($companyId, $user)) {
            return $query->whereRaw('1 = 0');
        }

        if (! $employeeKnown) {
            $employee ??= Employee::query()->where('company_id', $companyId)->where('user_id', $user->getKey())->first(['id', 'department_id']);
        }
        $departmentId = $employee?->department_id ? (int) $employee->department_id : null;
        $now = now();

        return $query
            ->where(fn (Builder $live) => $live->whereNull('publish_at')->orWhere('publish_at', '<=', $now))
            ->where(fn (Builder $live) => $live->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->where(function (Builder $audience) use ($departmentId): void {
                $audience->where('audience', Announcement::AudienceAll);

                if ($departmentId === null) {
                    return;
                }

                $audience->orWhere(fn (Builder $departments) => $departments
                    ->where('audience', Announcement::AudienceDepartments)
                    ->whereExists(fn (QueryBuilder $match) => $match
                        ->selectRaw('1')
                        ->from('employees_announcement_departments as audience_departments')
                        ->join('employees_departments as viewer_department', 'viewer_department.id', '=', DB::raw((string) $departmentId))
                        ->whereColumn('audience_departments.announcement_id', 'employees_announcements.id')
                        ->where(fn (QueryBuilder $tree) => $tree
                            ->whereColumn('audience_departments.department_id', 'viewer_department.id')
                            ->orWhereRaw("viewer_department.parent_path LIKE CONCAT('%/', audience_departments.department_id, '/%')"))));
            });
    }

    public function isVisibleTo(Announcement $announcement, User $user): bool
    {
        return $this->visibleQuery($user, (int) $announcement->company_id)->whereKey($announcement->getKey())->exists();
    }

    /**
     * The home card: up to $limit live announcements for the user, pinned
     * first, newest next. Unread ones (unacknowledged, when asked) only,
     * except pinned ones, which stay until they expire. One query, then
     * remembered for the request.
     *
     * @return Collection<int, Announcement>
     */
    public function dashboardFeed(User $user, int $companyId, ?Employee $employee = null, int $limit = 3, bool $employeeKnown = false): Collection
    {
        $key = $user->getKey().':'.$companyId.':'.$limit;

        return $this->feedCache[$key] ??= $this->withReadState($this->visibleQuery($user, $companyId, $employee, $employeeKnown), $user)
            ->where(fn (Builder $query) => $query
                ->where('is_pinned', true)
                ->orWhereNotExists(fn (QueryBuilder $done) => $this->readByUser($done, $user)))
            ->orderByDesc('is_pinned')
            ->orderByRaw('COALESCE(employees_announcements.publish_at, employees_announcements.created_at) DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * Adds my_read_at and my_acknowledged_at of the user to each row.
     *
     * @param  Builder<Announcement>  $query
     * @return Builder<Announcement>
     */
    public function withReadState(Builder $query, User $user): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('employees_announcements.*');
        }

        return $query->addSelect([
            'my_read_at' => AnnouncementRead::query()->select('read_at')
                ->whereColumn('announcement_id', 'employees_announcements.id')
                ->where('user_id', $user->getKey())
                ->limit(1),
            'my_acknowledged_at' => AnnouncementRead::query()->select('acknowledged_at')
                ->whereColumn('announcement_id', 'employees_announcements.id')
                ->where('user_id', $user->getKey())
                ->limit(1),
        ]);
    }

    public function markRead(Announcement $announcement, User $user): AnnouncementRead
    {
        return $this->record($announcement, $user, acknowledge: false);
    }

    public function acknowledge(Announcement $announcement, User $user): AnnouncementRead
    {
        if (! $announcement->requires_acknowledgement) {
            throw ValidationException::withMessages(['announcement' => 'This announcement does not ask for an acknowledgement.']);
        }

        return $this->record($announcement, $user, acknowledge: true);
    }

    /** Employees (with a login) the announcement is addressed to. */
    public function audienceCount(Announcement $announcement): int
    {
        $query = Employee::query()
            ->where('company_id', $announcement->company_id)
            ->where('is_active', true)
            ->whereNotNull('user_id');

        if ($announcement->audience === Announcement::AudienceDepartments) {
            $departmentIds = $announcement->departments()->pluck('employees_departments.id');

            $query->whereIn('department_id', Department::query()
                ->where('company_id', $announcement->company_id)
                ->where(function (Builder $tree) use ($departmentIds): void {
                    $tree->whereIn('id', $departmentIds->all() ?: [0]);

                    foreach ($departmentIds as $departmentId) {
                        $tree->orWhere('parent_path', 'like', '%/'.$departmentId.'/%');
                    }
                })
                ->select('id'));
        }

        return $query->count();
    }

    /** Sanitised HTML, safe to store and to show. */
    public static function sanitize(?string $html): string
    {
        return trim(Str::sanitizeHtml((string) $html));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function validated(array $data, int $companyId): array
    {
        $data = Validator::make($data, [
            'title'                    => ['required', 'string', 'max:255'],
            'body'                     => ['nullable', 'string', 'max:65000'],
            'audience'                 => ['required', Rule::in(array_keys(Announcement::audiences()))],
            'department_ids'           => ['array', Rule::requiredIf(fn (): bool => ($data['audience'] ?? null) === Announcement::AudienceDepartments)],
            'department_ids.*'         => ['integer', Rule::exists('employees_departments', 'id')->where('company_id', $companyId)],
            'is_pinned'                => ['boolean'],
            'requires_acknowledgement' => ['boolean'],
            'publish_at'               => ['nullable', 'date'],
            'expires_at'               => ['nullable', 'date', function (string $attribute, mixed $value, Closure $fail) use ($data): void {
                if (filled($data['publish_at'] ?? null) && Carbon::parse($value)->lte(Carbon::parse($data['publish_at']))) {
                    $fail('The expiry must be after the publish time.');
                }
            }],
        ], [
            'department_ids.required'  => 'Choose at least one department.',
            'department_ids.*.exists'  => 'Choose departments of this company.',
        ])->validate();

        return [
            'title'                    => trim((string) $data['title']),
            'body'                     => static::sanitize($data['body'] ?? null),
            'audience'                 => $data['audience'],
            'department_ids'           => array_values(array_unique(array_map('intval', $data['department_ids'] ?? []))),
            'is_pinned'                => (bool) ($data['is_pinned'] ?? false),
            'requires_acknowledgement' => (bool) ($data['requires_acknowledgement'] ?? false),
            'publish_at'               => $data['publish_at'] ?? null,
            'expires_at'               => $data['expires_at'] ?? null,
        ];
    }

    protected function record(Announcement $announcement, User $user, bool $acknowledge): AnnouncementRead
    {
        if (! $this->isVisibleTo($announcement, $user)) {
            throw new AuthorizationException('This announcement is not addressed to you.');
        }

        $employeeId = Employee::query()->where('company_id', $announcement->company_id)->where('user_id', $user->getKey())->value('id');

        $read = AnnouncementRead::query()->firstOrCreate(
            ['announcement_id' => $announcement->getKey(), 'user_id' => $user->getKey()],
            ['employee_id' => $employeeId, 'read_at' => now()],
        );

        $read->read_at ??= now();

        if ($acknowledge) {
            $read->acknowledged_at ??= now();
        }

        $read->save();
        $this->forgetFeeds();

        return $read;
    }

    /** "Read" for the home card: read, or acknowledged when the announcement asks for it. */
    protected function readByUser(QueryBuilder $query, User $user): QueryBuilder
    {
        return $query->selectRaw('1')
            ->from('employees_announcement_reads as my_reads')
            ->whereColumn('my_reads.announcement_id', 'employees_announcements.id')
            ->where('my_reads.user_id', $user->getKey())
            ->where(fn (QueryBuilder $done) => $done
                ->where(fn (QueryBuilder $plain) => $plain->where('employees_announcements.requires_acknowledgement', false)->whereNotNull('my_reads.read_at'))
                ->orWhereNotNull('my_reads.acknowledged_at'));
    }

    protected function forgetFeeds(): void
    {
        $this->feedCache = [];
    }
}
