<?php

namespace Webkul\Recruitment\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Webkul\Recruitment\Enums\InterviewStatus;
use Webkul\Recruitment\Models\Interview;
use Webkul\Recruitment\Models\InterviewInterviewer;
use Webkul\Recruitment\Services\InterviewNotifier;

/**
 * Hourly (Stage 2.3): emails the candidate and the interviewers once before
 * an interview (recruitment.interviews.reminder_hours, default 24), and asks
 * each interviewer once for feedback when an interview has ended (or was
 * marked completed) and theirs is still missing. Each reminder / request is
 * claimed with an atomic update first, so overlapping runs or re-runs never
 * send twice; a send that fails is released again for the next run.
 */
class SendInterviewRemindersCommand extends Command
{
    protected $signature = 'recruitments:interview-reminders {--dry-run : Only report what would be sent}';

    protected $description = 'Send interview reminders (once, before the interview) and feedback requests (once, after it)';

    public function handle(InterviewNotifier $notifier): int
    {
        if (! Schema::hasTable('recruitments_interviews')) {
            return self::SUCCESS;
        }

        $hours = max(1, (int) config('recruitment.interviews.reminder_hours', 24));

        $dueReminders = Interview::query()
            ->where('status', InterviewStatus::SCHEDULED->value)
            ->whereNull('reminded_at')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addHours($hours))
            // No reminder for an application that was refused or archived meanwhile.
            ->whereHas('applicant', fn (Builder $applicant): Builder => $applicant->whereNull('refuse_reason_id')->where('is_active', true))
            ->orderBy('starts_at')
            ->pluck('id');

        $dueFeedback = InterviewInterviewer::query()
            ->whereNull('submitted_at')
            ->whereNull('feedback_requested_at')
            ->whereHas('interview', fn (Builder $query): Builder => $query
                ->whereIn('status', InterviewStatus::feedbackValues())
                ->where(fn (Builder $ended): Builder => $ended
                    ->where('ends_at', '<=', now())
                    ->orWhere('status', InterviewStatus::COMPLETED->value))
                ->whereHas('applicant'))
            ->orderBy('id')
            ->pluck('id');

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$dueReminders->count()} interview reminder(s) and {$dueFeedback->count()} feedback request(s) are due.");

            return self::SUCCESS;
        }

        $reminded = 0;

        foreach ($dueReminders as $interviewId) {
            $claimed = Interview::query()
                ->whereKey($interviewId)
                ->whereNull('reminded_at')
                ->where('status', InterviewStatus::SCHEDULED->value)
                ->update(['reminded_at' => now()]);

            if ($claimed !== 1) {
                continue;
            }

            try {
                $notifier->reminder(Interview::query()->with(['applicant.candidate', 'company', 'job', 'organizer', 'assignments.user'])->findOrFail($interviewId));
                $reminded++;
            } catch (Throwable $e) {
                Interview::query()->whereKey($interviewId)->update(['reminded_at' => null]);
                report($e);
            }
        }

        $requested = 0;

        foreach ($dueFeedback as $assignmentId) {
            $claimed = InterviewInterviewer::query()
                ->whereKey($assignmentId)
                ->whereNull('feedback_requested_at')
                ->whereNull('submitted_at')
                ->update(['feedback_requested_at' => now()]);

            if ($claimed !== 1) {
                continue;
            }

            try {
                $assignment = InterviewInterviewer::query()->with('user')->findOrFail($assignmentId);
                $interview = Interview::query()->with(['applicant.candidate', 'company', 'job', 'organizer', 'assignments.user'])->findOrFail($assignment->interview_id);
                $notifier->feedbackRequest($interview, $assignment);
                $requested++;
            } catch (Throwable $e) {
                InterviewInterviewer::query()->whereKey($assignmentId)->update(['feedback_requested_at' => null]);
                report($e);
            }
        }

        $this->info("Sent {$reminded} interview reminder(s) and {$requested} feedback request(s).");

        return self::SUCCESS;
    }
}
