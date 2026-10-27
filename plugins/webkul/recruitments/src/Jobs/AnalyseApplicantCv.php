<?php

namespace Webkul\Recruitment\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Services\CvAnalysis\CvAnalyser;

/** Reads an application's CV and stores its CV score (automation plan A5). */
class AnalyseApplicantCv implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    /** One analysis per application at a time; later requests within a minute are dropped. */
    public int $uniqueFor = 60;

    public function __construct(
        public int $applicantId,
    ) {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->applicantId;
    }

    public function handle(CvAnalyser $analyser): void
    {
        $applicant = Applicant::query()->find($this->applicantId);

        if ($applicant) {
            $analyser->analyse($applicant);
        }
    }
}
