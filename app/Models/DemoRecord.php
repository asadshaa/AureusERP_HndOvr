<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A row created (or filled in) by the demo seeder: see the
 * create_demo_records_table migration and App\Services\Demo\DemoRecorder.
 */
class DemoRecord extends Model
{
    public const PHASE_STAFF = 'staff';

    public const PHASE_ACTIVITY = 'activity';

    /** record_table of a file the seeder stored ("disk:path" in record_id). */
    public const FILE_TABLE = '__file';

    public const UPDATED_AT = null;

    protected $table = 'demo_records';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'original' => 'array',
            'applied'  => 'array',
        ];
    }
}
