<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Account\Models\Move;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * One amount taken off a business advance: a settlement (an approved Advance
 * Settlement request, spent on company work) or cash the employee handed back.
 */
class AdvanceEntry extends Model
{
    protected $table = 'employees_advance_entries';

    protected $fillable = [
        'company_id', 'advance_request_id', 'source_request_id', 'type', 'amount', 'entry_date',
        'accounting_move_id', 'reference', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount'     => 'decimal:4',
            'entry_date' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(EmployeeRequest::class, 'advance_request_id');
    }

    public function sourceRequest(): BelongsTo
    {
        return $this->belongsTo(EmployeeRequest::class, 'source_request_id');
    }

    public function accountingMove(): BelongsTo
    {
        return $this->belongsTo(Move::class, 'accounting_move_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
