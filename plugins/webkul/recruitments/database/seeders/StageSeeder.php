<?php

namespace Webkul\Recruitment\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Webkul\Employee\Models\EmployeeJobPosition;
use Webkul\Recruitment\Models\Stage;
use Webkul\Security\Models\User;

class StageSeeder extends Seeder
{
    /**
     * Idempotent and non-destructive: adds the default shared pipeline stages
     * that are missing (matched by name among shared stages) and links jobs
     * without a stage to the default one. Existing stages, applications and
     * company-specific pipelines are never deleted or changed.
     */
    public function run(): void
    {
        $user = User::first();

        $recruitmentStages = [
            [
                'sort'           => 0,
                'is_default'     => 1,
                'creator_id'     => $user?->id,
                'name'           => 'New',
                'legend_blocked' => 'Blocked',
                'legend_done'    => 'Ready for Next Stage',
                'legend_normal'  => 'In Progress	',
                'hired_stage'    => false,
                'fold'           => 1,
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
            [

                'sort'           => 1,
                'is_default'     => 0,
                'creator_id'     => $user?->id,
                'name'           => 'First Interview',
                'legend_blocked' => 'Blocked',
                'legend_done'    => 'Ready for Next Stage',
                'legend_normal'  => 'In Progress	',
                'hired_stage'    => false,
                'fold'           => 0,
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
            [

                'sort'           => 2,
                'is_default'     => 0,
                'creator_id'     => $user?->id,
                'name'           => 'Initial Qualification',
                'legend_blocked' => 'Blocked',
                'legend_done'    => 'Ready for Next Stage',
                'legend_normal'  => 'In Progress	',
                'hired_stage'    => false,
                'fold'           => 0,
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
            [

                'sort'           => 3,
                'is_default'     => 0,
                'creator_id'     => $user?->id,
                'name'           => 'Second Interview',
                'legend_blocked' => 'Blocked',
                'legend_done'    => 'Ready for Next Stage',
                'legend_normal'  => 'In Progress	',
                'hired_stage'    => false,
                'fold'           => 0,
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
            [

                'sort'           => 4,
                'is_default'     => 0,
                'creator_id'     => $user?->id,
                'name'           => 'Contract Proposal',
                'legend_blocked' => 'Blocked',
                'legend_done'    => 'Ready for Next Stage',
                'legend_normal'  => 'In Progress	',
                'hired_stage'    => false,
                'fold'           => 0,
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
            [

                'sort'           => 5,
                'is_default'     => 0,
                'creator_id'     => $user?->id,
                'name'           => 'Contract Signed',
                'legend_blocked' => 'Blocked',
                'legend_done'    => 'Ready for Next Stage',
                'legend_normal'  => 'In Progress	',
                'hired_stage'    => true,
                'fold'           => 0,
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
        ];

        $existing = DB::table('recruitments_stages')->whereNull('company_id')->pluck('name')->map(fn ($name): string => mb_strtolower(trim((string) $name)))->all();
        $missing = array_values(array_filter($recruitmentStages, fn (array $stage): bool => ! in_array(mb_strtolower($stage['name']), $existing, true)));

        if ($missing !== [] && DB::table('recruitments_stages')->whereNull('company_id')->where('is_default', true)->exists()) {
            // Keep the existing default stage the only shared default.
            $missing = array_map(fn (array $stage): array => ['is_default' => 0] + $stage, $missing);
        }

        if ($missing !== []) {
            DB::table('recruitments_stages')->insert($missing);
        }

        $stage = Stage::defaultFor(null);

        if (! $stage) {
            return;
        }

        $linked = DB::table('recruitments_stages_jobs')->pluck('job_id')->all();

        $recruitmentStagesJobs = EmployeeJobPosition::query()
            ->whereNotIn('id', $linked)
            ->pluck('id')
            ->map(fn ($jobId): array => ['stage_id' => $stage->id, 'job_id' => $jobId])
            ->all();

        if ($recruitmentStagesJobs !== []) {
            DB::table('recruitments_stages_jobs')->insert($recruitmentStagesJobs);
        }
    }
}
