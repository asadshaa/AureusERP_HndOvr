<?php

namespace Webkul\Recruitment\Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Recruitment\Models\Stage;

/**
 * Section 7 ("IMPLEMENTATION SECTION 7 -- RECRUITMENT") "Required workflow"
 * names an explicit stage sequence (Candidate Pool -> Screening -> Interview
 * -> Assessment where configured -> Offer -> Hired/Rejected) that the
 * pre-existing demo StageSeeder does not provide (its stages are named
 * "New"/"First Interview"/"Initial Qualification"/"Second Interview"/
 * "Contract Proposal"/"Contract Signed" -- a generic ATS pipeline, not a
 * broken one, just not the one this task names). That demo seeder is also
 * destructive (DB::table(...)->delete() before reseeding), so it is not
 * safe to extend or re-run against real data.
 *
 * This seeder only adds the named pipeline as an idempotent, non-destructive
 * supplement -- it never deletes or modifies the pre-existing demo stages,
 * and does not change is_default/fold on anything, so it cannot change
 * which stage currently shows as the default for new applications.
 * Stages are created with company_id = null (global), matching
 * StageResource's own existing scoping convention (getEloquentQuery()
 * already resolves whereNull('company_id') OR company_id = current as
 * visible), the same convention the pre-existing demo stages already use --
 * so one global pipeline is visible to every company without duplicating it
 * per company.
 */
class RecruitmentWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $startingSort = ((int) Stage::query()->whereNull('company_id')->max('sort')) + 1;

        $stages = [
            ['name' => 'Screening', 'pipeline_code' => 'screening', 'hired_stage' => false],
            ['name' => 'Interview', 'pipeline_code' => 'interview', 'hired_stage' => false],
            ['name' => 'Assessment', 'pipeline_code' => 'assessment', 'hired_stage' => false],
            ['name' => 'Offer', 'pipeline_code' => 'offer', 'hired_stage' => false],
            ['name' => 'Hired', 'pipeline_code' => 'hired', 'hired_stage' => true],
        ];

        foreach ($stages as $index => $stage) {
            Stage::query()->firstOrCreate(
                ['company_id' => null, 'name' => $stage['name']],
                [
                    'sort'           => $startingSort + $index,
                    'is_default'     => false,
                    'pipeline_code'  => $stage['pipeline_code'],
                    'legend_blocked' => 'Blocked',
                    'legend_done'    => 'Ready for Next Stage',
                    'legend_normal'  => 'In Progress',
                    'hired_stage'    => $stage['hired_stage'],
                    'fold'           => false,
                ]
            );
        }
    }
}
