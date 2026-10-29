<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Models\Move;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Security\Models\User;
use Webkul\Support\Contracts\ReturnableApprovalSubject;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class EmployeeRequest extends Model implements ReturnableApprovalSubject
{
    protected $table = 'employees_requests';

    protected $attributes = [
        'status' => 'draft',
    ];

    protected $fillable = [
        'company_id', 'employee_id', 'request_type_id', 'requested_by', 'currency_id',
        'approval_request_id', 'accounting_move_id', 'reference', 'title', 'description',
        'amount', 'billed_amount', 'tax_deduction_rate', 'income_tax_deduction', 'sales_tax_deduction',
        'nature_of_expense', 'account_title', 'iban', 'bank_name',
        'payload', 'attachments', 'status', 'rejection_reason', 'submitted_at',
        'approved_at', 'rejected_at', 'posted_to_accounting_at',
    ];

    /**
     * account_title/iban/bank_name are deliberately excluded from every
     * array/JSON representation of this model (Filament table columns,
     * relation eager-loads rendered elsewhere, etc. still work -- this only
     * blocks accidental exposure through a bare $request->toArray()/
     * ->toJson() or an unguarded API response). Filament's own field-level
     * ->visible() gating on the form/table is the primary control; this is
     * the model-level backstop.
     */
    protected $hidden = ['account_title', 'iban', 'bank_name'];

    protected function casts(): array
    {
        return [
            'amount'                  => 'decimal:4',
            'billed_amount'           => 'decimal:4',
            'tax_deduction_rate'      => 'decimal:4',
            'income_tax_deduction'    => 'decimal:4',
            'sales_tax_deduction'     => 'decimal:4',
            'payload'                 => 'array',
            'attachments'             => 'array',
            'submitted_at'            => 'datetime',
            'approved_at'             => 'datetime',
            'rejected_at'             => 'datetime',
            'posted_to_accounting_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requestType(): BelongsTo
    {
        return $this->belongsTo(EmployeeRequestType::class, 'request_type_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    public function accountingMove(): BelongsTo
    {
        return $this->belongsTo(Move::class);
    }

    public function synchronizeApprovalState(ApprovalRequest $request): void
    {
        if ((int) $this->approval_request_id === (int) $request->id) {
            app(EmployeeRequestService::class)->synchronize($this);
        }
    }

    /**
     * Where an approval notification sends the requester (ApprovalLinks): the
     * Employee Requests list with this request open -- the edit form while it
     * is returned for changes, its details otherwise.
     */
    public function approvalSubjectUrl(?User $recipient = null): ?string
    {
        return route('filament.admin.resources.employee-requests.index', [
            'tableAction'       => $this->status === 'returned' ? 'edit' : 'view',
            'tableActionRecord' => $this->getKey(),
        ]);
    }

    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            $request->requested_by ??= Auth::id();
            $request->company_id ??= $request->employee?->company_id;
            $request->currency_id ??= $request->company?->currency_id;

            if (blank($request->title)) {
                $payload = (array) ($request->payload ?? []);
                $dateStr = $payload['formatted_date'] ?? $payload['attendance_date'] ?? null;
                $typeName = $request->requestType?->name ?? 'Employee Request';
                $request->title = $dateStr
                    ? "{$typeName} - {$dateStr}"
                    : "{$typeName} - ".now()->format('d M Y');
            }
        });

        static::saving(function (EmployeeRequest $model): void {
            if (is_array($model->payload)) {
                $payload = $model->payload;
                $dateStr = $payload['attendance_date'] ?? null;
                if ($dateStr) {
                    $carbon = Carbon::parse($dateStr);
                    if (empty($payload['day_of_week'])) {
                        $payload['day_of_week'] = $carbon->format('l');
                    }
                    if (empty($payload['formatted_date'])) {
                        $payload['formatted_date'] = $carbon->format('d M Y');
                    }
                    $inTime = $payload['requested_check_in_time'] ?? null;
                    $outTime = $payload['requested_check_out_time'] ?? null;
                    if ($inTime && empty($payload['requested']['check_in'])) {
                        $payload['requested']['check_in'] = Carbon::parse("{$dateStr} {$inTime}")->toDateTimeString();
                    }
                    if ($outTime && empty($payload['requested']['check_out'])) {
                        $outCarbon = Carbon::parse("{$dateStr} {$outTime}");
                        if ($inTime && $outCarbon->lt(Carbon::parse("{$dateStr} {$inTime}"))) {
                            $outCarbon->addDay();
                        }
                        $payload['requested']['check_out'] = $outCarbon->toDateTimeString();
                    }
                    $model->payload = $payload;
                }
            }
        });
    }
}
