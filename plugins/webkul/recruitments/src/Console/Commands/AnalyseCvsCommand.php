<?php

namespace Webkul\Recruitment\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Recruitment\Jobs\AnalyseApplicantCv;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Services\CvAnalysis\CvAnalyser;

/**
 * Score applications' CVs (automation plan A5), e.g. after changing a job's
 * keywords or for applications that arrived before the analyser existed:
 *
 *   php artisan recruitments:analyse-cvs --job=12
 *   php artisan recruitments:analyse-cvs --company=25 --only-missing --queue
 */
class AnalyseCvsCommand extends Command
{
    protected $signature = 'recruitments:analyse-cvs
        {--job= : Only applications for this job id}
        {--company= : Only applications of this company id}
        {--only-missing : Skip applications that already have a CV analysis}
        {--queue : Put the work on the queue instead of doing it now}';

    protected $description = 'Score applicants\' CVs against their job (keywords, experience, work location)';

    public function handle(CvAnalyser $analyser): int
    {
        $query = Applicant::query()
            ->whereNotNull('job_id')
            ->when($this->option('job'), fn ($query, $job) => $query->where('job_id', (int) $job))
            ->when($this->option('company'), fn ($query, $company) => $query->where('company_id', (int) $company))
            ->when($this->option('only-missing'), fn ($query) => $query->whereNull('applicant_properties->cv_analysis'));

        $total = 0;
        $scored = 0;

        $query->orderBy('id')->chunkById(100, function ($applicants) use ($analyser, &$total, &$scored): void {
            foreach ($applicants as $applicant) {
                $total++;

                if ($this->option('queue')) {
                    AnalyseApplicantCv::dispatch($applicant->id);

                    continue;
                }

                if (($analyser->analyse($applicant)['score'] ?? null) !== null) {
                    $scored++;
                }
            }
        });

        $this->info($this->option('queue')
            ? "Queued {$total} application(s) for CV analysis."
            : "Analysed {$total} application(s); {$scored} got a CV score.");

        return self::SUCCESS;
    }
}
