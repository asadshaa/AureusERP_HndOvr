<?php

namespace Webkul\Employee\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * A kind of employee document (CNIC, contract, ...), per company.
 *
 * @property bool $has_expiry
 * @property bool $visible_to_employee
 * @property bool $required_for_new_hires
 */
class DocumentType extends Model
{
    protected $table = 'employees_document_types';

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'has_expiry',
        'visible_to_employee',
        'required_for_new_hires',
        'is_active',
        'sort',
        'creator_id',
    ];

    protected $casts = [
        'has_expiry'             => 'boolean',
        'visible_to_employee'    => 'boolean',
        'required_for_new_hires' => 'boolean',
        'is_active'              => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class, 'document_type_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected static function booted(): void
    {
        static::creating(function (self $type): void {
            $type->creator_id ??= Auth::id();
            $type->code = $type->code ?: str($type->name)->slug('_')->limit(60, '')->toString();
        });
    }
}
