<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Support\Models\Company;

class PerformanceGoal extends Model
{
    protected $table = 'employees_performance_goals';

    protected $fillable = [
        'company_id', 'review_id', 'period', 'title', 'description', 'weight', 'target_value',
        'actual_value', 'rating', 'status', 'due_date',
    ];

    protected function casts(): array
    {
        return [
            'weight'       => 'decimal:2',
            'target_value' => 'decimal:4',
            'actual_value' => 'decimal:4',
            'rating'       => 'decimal:2',
            'due_date'     => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(PerformanceReview::class, 'review_id');
    }
}
