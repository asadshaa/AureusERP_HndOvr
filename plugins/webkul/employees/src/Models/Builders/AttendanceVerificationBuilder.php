<?php

namespace Webkul\Employee\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Model events do not fire for query-level writes, so the append-only rule
 * is enforced here as well: no mass delete, and only the columns a review or
 * privacy prune may touch can be updated.
 */
class AttendanceVerificationBuilder extends Builder
{
    private const UPDATABLE = [
        'review_status', 'reviewed_by', 'reviewed_at', 'review_note', 'updated_at',
        'attendance_record_id', 'latitude', 'longitude', 'ip_address', 'user_agent',
    ];

    private const NULL_ONLY = ['latitude', 'longitude', 'ip_address', 'user_agent'];

    public function delete()
    {
        throw new LogicException('Location check details are kept as a permanent record and cannot be deleted.');
    }

    public function forceDelete()
    {
        throw new LogicException('Location check details are kept as a permanent record and cannot be deleted.');
    }

    public function update(array $values)
    {
        foreach ($values as $column => $value) {
            if (! in_array($column, self::UPDATABLE, true)) {
                throw new LogicException("Location check details are kept as a permanent record, so {$column} cannot be changed.");
            }

            if (in_array($column, self::NULL_ONLY, true) && $value !== null) {
                throw new LogicException("The location check detail {$column} can only be cleared, not changed.");
            }
        }

        return parent::update($values);
    }
}
