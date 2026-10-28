<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Support\Models\Company;

/**
 * Employee code settings of one company: codes look like PREFIX-0001.
 */
class EmployeeCodeSequence extends Model
{
    protected $table = 'employees_employee_code_sequences';

    protected $fillable = [
        'company_id',
        'prefix',
        'padding',
        'next_number',
    ];

    protected $casts = [
        'padding'     => 'integer',
        'next_number' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function format(int $number): string
    {
        return $this->prefix.'-'.str_pad((string) $number, max(1, $this->padding), '0', STR_PAD_LEFT);
    }
}
