<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Webkul\Chatter\Traits\HasChatter;
use Webkul\Chatter\Traits\HasLogActivity;
use Webkul\Employee\Database\Factories\EmployeeFactory;
use Webkul\Employee\Services\EmployeeCodeGenerator;
use Webkul\Employee\Services\EmployeeSensitiveChangeService;
use Webkul\Employee\Support\Cnic;
use Webkul\Field\Traits\HasCustomFields;
use Webkul\Partner\Models\BankAccount;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Models\Team;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Country;
use Webkul\Support\Models\Currency;
use Webkul\Support\Models\State;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveAllocation;

class Employee extends Model
{
    use HasChatter, HasCustomFields, HasFactory, HasLogActivity, SoftDeletes;

    public const ACTIVITY_PLAN_PLUGIN = 'employees';

    protected $table = 'employees_employees';

    protected $fillable = [
        'company_id',
        'user_id',
        'creator_id',
        'calendar_id',
        'department_id',
        'team_id',
        'job_id',
        'attendance_manager_id',
        'partner_id',
        'work_location_id',
        'parent_id',
        'coach_id',
        'country_id',
        'state_id',
        'country_of_birth',
        'bank_account_id',
        'salary_currency_id',
        'departure_reason_id',
        'name',
        'employee_number',
        'job_title',
        'work_phone',
        'mobile_phone',
        'color',
        'work_email',
        'children',
        'distance_home_work',
        'km_home_work',
        'distance_home_work_unit',
        'private_phone',
        'private_email',
        'private_street1',
        'private_street2',
        'private_city',
        'private_zip',
        'private_state_id',
        'private_country_id',
        'private_car_plate',
        'lang',
        'gender',
        'birthday',
        'joining_date',
        'leaving_date',
        'marital',
        'spouse_complete_name',
        'spouse_birthdate',
        'place_of_birth',
        'ssnid',
        'sinid',
        'identification_id',
        'cnic',
        'ntn',
        'eobi_number',
        'blood_group',
        'probation_end_date',
        'confirmation_date',
        'passport_id',
        'permit_no',
        'visa_no',
        'certificate',
        'study_field',
        'study_school',
        'emergency_contact',
        'emergency_relationship',
        'emergency_phone',
        'employee_type',
        'employment_status',
        'salary_grade',
        'base_salary',
        'barcode',
        'pin',
        'address_id',
        'time_zone',
        'work_permit',
        'leave_manager_id',
        'visa_expire',
        'work_permit_expiration_date',
        'departure_date',
        'departure_description',
        'additional_note',
        'document_metadata',
        'notes',
        'is_active',
        'is_flexible',
        'is_fully_flexible',
        'work_permit_scheduled_activity',
    ];

    protected $casts = [
        'is_active'                      => 'boolean',
        'is_flexible'                    => 'boolean',
        'is_fully_flexible'              => 'boolean',
        'work_permit_scheduled_activity' => 'boolean',
        'joining_date'                   => 'date',
        'leaving_date'                   => 'date',
        'probation_end_date'             => 'date',
        'confirmation_date'              => 'date',
        'base_salary'                    => 'decimal:4',
        'document_metadata'              => 'array',
    ];

    public function getModelTitle(): string
    {
        return __('employees::models/employee.title');
    }

    public function privateState(): BelongsTo
    {
        return $this->belongsTo(State::class, 'private_state_id');
    }

    public function privateCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'private_country_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class, 'calendar_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(EmployeeJobPosition::class, 'job_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class, 'work_location_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function coach(): BelongsTo
    {
        return $this->belongsTo(self::class, 'coach_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class, 'state_id');
    }

    public function countryOfBirth(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_of_birth');
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function salaryCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'salary_currency_id');
    }

    public function departureReason(): BelongsTo
    {
        return $this->belongsTo(DepartureReason::class, 'departure_reason_id');
    }

    public function employmentType(): BelongsTo
    {
        return $this->belongsTo(EmploymentType::class, 'employee_type');
    }

    public function categories()
    {
        return $this->belongsToMany(EmployeeCategory::class, 'employees_employee_categories', 'employee_id', 'category_id');
    }

    public function skills(): HasMany
    {
        return $this->hasMany(EmployeeSkill::class, 'employee_id');
    }

    public function resumes()
    {
        return $this->hasMany(EmployeeResume::class, 'employee_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(EmployeeStatusHistory::class)->latest('effective_date');
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(Leave::class);
    }

    public function leaveAllocations(): HasMany
    {
        return $this->hasMany(LeaveAllocation::class);
    }

    public function performanceReviews(): HasMany
    {
        return $this->hasMany(PerformanceReview::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(EmployeeRequest::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class, 'employee_id');
    }

    /**
     * Active "required for new hires" document types of the employee's
     * company that this employee has no (non-deleted) document of.
     *
     * @return Collection<int, DocumentType>
     */
    public function missingRequiredDocumentTypes(): Collection
    {
        if (! $this->company_id) {
            return new Collection;
        }

        return DocumentType::query()
            ->where('company_id', $this->company_id)
            ->where('is_active', true)
            ->where('required_for_new_hires', true)
            ->whereNotIn('id', $this->documents()->select('document_type_id'))
            ->orderBy('sort')
            ->get();
    }

    /** Adds missing_required_documents_count (see missingRequiredDocumentTypes()) to each row. */
    public function scopeWithMissingRequiredDocumentsCount(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select($query->qualifyColumn('*'));
        }

        return $query->addSelect([
            'missing_required_documents_count' => static::missingRequiredDocumentTypesSubquery()->selectRaw('count(*)'),
        ]);
    }

    /** Employees missing at least one required document. */
    public function scopeMissingRequiredDocuments(Builder $query): Builder
    {
        return $query->whereExists(static::missingRequiredDocumentTypesSubquery()->select('employees_document_types.id'));
    }

    protected static function missingRequiredDocumentTypesSubquery(): Builder
    {
        return DocumentType::query()
            ->whereColumn('employees_document_types.company_id', 'employees_employees.company_id')
            ->where('employees_document_types.is_active', true)
            ->where('employees_document_types.required_for_new_hires', true)
            ->whereNotExists(fn ($documents) => $documents
                ->from('employees_employee_documents')
                ->whereColumn('employees_employee_documents.document_type_id', 'employees_document_types.id')
                ->whereColumn('employees_employee_documents.employee_id', 'employees_employees.id')
                ->whereNull('employees_employee_documents.deleted_at'));
    }

    public function synchronizeApprovalState(ApprovalRequest $request): void
    {
        if ($request->request_type === 'employee_sensitive_change' && $request->status === 'approved') {
            app(EmployeeSensitiveChangeService::class)->applyApproved($request);
        }
    }

    protected static function newFactory(): EmployeeFactory
    {
        return EmployeeFactory::new();
    }

    public function leaveManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leave_manager_id');
    }

    public function workLocationAssignments(): HasMany
    {
        return $this->hasMany(EmployeeWorkLocationAssignment::class, 'employee_id');
    }

    public function attendanceManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendance_manager_id');
    }

    public function companyAddress()
    {
        return $this->belongsTo(Partner::class, 'address_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $employee): void {
            app(EmployeeCodeGenerator::class)->assignIfEmpty($employee);
        });

        static::saving(function (self $employee): void {
            static::assertHierarchyIsSameCompany($employee);
            static::normalizeCnic($employee);

            // Time-off approval always follows the reporting line: the line manager set in
            // "Manager" is who approves this employee's leave, never a separately-picked person.
            $employee->leave_manager_id = $employee->parent_id
                ? static::query()->find($employee->parent_id)?->user_id
                : null;
        });

        static::saved(function (self $employee) {
            $employee->creator_id ??= Auth::id();

            if (! $employee->partner_id) {
                $employee->handlePartnerCreation($employee);
            } else {
                $employee->handlePartnerUpdation($employee);
            }
        });

        static::created(function (self $employee): void {
            if ($employee->company_id) {
                $employee->statusHistory()->create([
                    'company_id'     => $employee->company_id,
                    'changed_by'     => Auth::id(),
                    'status'         => $employee->employment_status ?? ($employee->is_active ? 'active' : 'inactive'),
                    'effective_date' => $employee->joining_date ?? now()->toDateString(),
                    'new_values'     => ['employment_status' => $employee->employment_status],
                ]);
            }
        });

        static::updated(function (self $employee): void {
            if ($employee->wasChanged('employment_status') && $employee->company_id) {
                $employee->statusHistory()->create([
                    'company_id'     => $employee->company_id,
                    'changed_by'     => Auth::id(),
                    'status'         => $employee->employment_status,
                    'effective_date' => $employee->leaving_date ?? now()->toDateString(),
                    'previous_values'=> ['employment_status' => $employee->getOriginal('employment_status')],
                    'new_values'     => ['employment_status' => $employee->employment_status],
                ]);
            }
        });
    }

    /**
     * Every hierarchy relationship an Employee can carry -- department,
     * team, manager, coach, job position, work location, and the linked
     * login -- must belong to the same company as the employee.
     *
     * Filament scopes several of these Select options to the current
     * user's company already (see EmployeeResource::form()), but that is
     * a UI convenience only: nothing stopped a cross-company id reaching
     * this model directly (API, tinker, mass update), and job_id /
     * work_location_id were not even scoped in the UI. This is the
     * server-side enforcement rule 7 requires, checked once here so
     * every write path (create, edit, API) goes through it -- following
     * the same saving-hook pattern Department::validateNoRecursion()
     * already uses in this same plugin for its own structural rule.
     */
    private static function assertHierarchyIsSameCompany(self $employee): void
    {
        $companyId = $employee->company_id;

        if (! $companyId) {
            // Nothing to compare against yet; the FK constraints on each
            // relation still apply independently.
            return;
        }

        $checks = [
            'department' => [$employee->department_id, Department::class, 'Department'],
            'team'       => [$employee->team_id, Team::class, 'Team'],
            'job'        => [$employee->job_id, EmployeeJobPosition::class, 'Job position'],
            'location'   => [$employee->work_location_id, WorkLocation::class, 'Work location'],
            'manager'    => [$employee->parent_id, self::class, 'Manager'],
            'coach'      => [$employee->coach_id, self::class, 'Coach'],
        ];

        foreach ($checks as [$relatedId, $modelClass, $label]) {
            if (! $relatedId) {
                continue;
            }

            $relatedCompanyId = $modelClass::query()->whereKey($relatedId)->value('company_id');

            if ($relatedCompanyId !== null && (int) $relatedCompanyId !== (int) $companyId) {
                throw new InvalidArgumentException("{$label} belongs to a different company than this employee.");
            }
        }

        if ($employee->user_id) {
            $user = User::query()->find($employee->user_id);

            if ($user && (int) $user->default_company_id !== (int) $companyId
                && ! $user->allowedCompanies()->whereKey($companyId)->exists()) {
                throw new InvalidArgumentException('The linked user does not have access to this company.');
            }
        }
    }

    /**
     * CNIC is stored as 12345-1234567-1 whatever way it was typed; anything
     * that is not 13 digits is refused on every write path (form, API,
     * sensitive-change approval), and it must be unique in the company.
     */
    private static function normalizeCnic(self $employee): void
    {
        if (! $employee->isDirty('cnic')) {
            return;
        }

        if (blank($employee->cnic)) {
            $employee->cnic = null;

            return;
        }

        $formatted = Cnic::normalize((string) $employee->cnic);

        if ($formatted === null) {
            throw new InvalidArgumentException('CNIC must be 13 digits, written 12345-1234567-1.');
        }

        $employee->cnic = $formatted;

        if ($employee->company_id && static::withTrashed()
            ->where('company_id', $employee->company_id)
            ->where('cnic', $formatted)
            ->when($employee->exists, fn (Builder $query) => $query->whereKeyNot($employee->getKey()))
            ->exists()) {
            throw new InvalidArgumentException('Another employee of this company already has this CNIC.');
        }
    }

    private function handlePartnerCreation(self $employee): void
    {
        $partner = $employee->partner()->create([
            'account_type' => 'individual',
            'sub_type'     => 'employee',
            'creator_id'   => $employee->creator_id ?? Auth::id(),
            'name'         => $employee?->name,
            'email'        => $employee?->work_email ?? $employee?->private_email,
            'job_title'    => $employee?->job_title,
            'phone'        => $employee?->work_phone,
            'mobile'       => $employee?->mobile_phone,
            'color'        => $employee?->color,
            'parent_id'    => $employee?->parent?->partner_id,
            'company_id'   => $employee?->company_id,
            'user_id'      => $employee?->user_id,
        ]);

        $employee->partner_id = $partner->id;
        $employee->save();
    }

    private function handlePartnerUpdation(self $employee): void
    {
        $partner = Partner::updateOrCreate(
            ['id' => $employee->partner_id],
            [
                'account_type' => 'individual',
                'sub_type'     => 'employee',
                'creator_id'   => $employee->creator_id ?? Auth::id(),
                'name'         => $employee?->name,
                'email'        => $employee?->work_email ?? $employee?->private_email,
                'job_title'    => $employee?->job_title,
                'phone'        => $employee?->work_phone,
                'mobile'       => $employee?->mobile_phone,
                'color'        => $employee?->color,
                'parent_id'    => $employee?->parent?->partner_id,
                'company_id'   => $employee?->company_id,
                'user_id'      => $employee?->user_id,
            ]
        );

        if ($employee->partner_id !== $partner->id) {
            $employee->partner_id = $partner->id;
            $employee->save();
        }
    }
}
