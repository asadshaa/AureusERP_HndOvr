<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

/*
|--------------------------------------------------------------------------
| Drive -> Aureus inbound sync (Phase 4)
|--------------------------------------------------------------------------
|
| First Schedule:: entry in this application (see HANDOVER.md and
| ExpireDocumentTransfersCommand/ExpireWebRtcSessionsCommand's doc
| comments -- both note this app previously wired no scheduler at all,
| relying on external cron). Laravel 11+ picks up Schedule:: calls made
| directly in routes/console.php (this file is registered as a command
| route path in bootstrap/app.php's ->withRouting(commands: ...), and
| the Schedule facade resolves the same singleton `schedule:run` reads
| from later) -- no separate Kernel::schedule() override needed.
|
| No --company: accounting:drive:sync-inbound already loops over every
| Drive-enabled company itself (Company::where('is_active', true), the
| only "which companies" query this feature has -- see that command's
| own doc comment for why there is no separate per-company Drive opt-in
| flag to check). Calling the artisan command here reuses that query
| as-is rather than duplicating it.
|
| everyFifteenMinutes(): no interval hint exists anywhere in
| config/accounting_drive.php (checked), so this is a plain default
| for "notice a new file reasonably soon without hammering the Drive
| API every minute".
|
| withoutOverlapping(20): this codebase has no prior withoutOverlapping()
| call anywhere to mirror a convention from (grepped the whole
| plugins/ tree). 20 minutes -- a little more than the 15-minute
| interval -- so a merely-slow run is still given room to finish
| before being treated as abandoned, while a genuinely stuck run
| doesn't block every subsequent scheduled tick indefinitely the way
| the framework's own 24-hour default would.
|
| onOneServer(): this repo has no Horizon config and no multi-server
| hint was found, but it is safe to add regardless -- onOneServer()
| needs an atomic lock, which it gets via Cache::lock(), and
| CACHE_STORE here is "database" (.env), whose DatabaseStore explicitly
| `implements LockProvider` (confirmed by reading
| vendor/laravel/framework/.../Cache/DatabaseStore.php rather than just
| assuming it), so this is a real single-server guarantee on this
| stack, not a silent no-op.
|
| IMPORTANT: this lock only covers the `accounting:drive:sync-inbound`
| artisan process itself -- which just loops companies and dispatches
| DiscoverDriveIngestionsJob (a fast jobs-table INSERT with
| QUEUE_CONNECTION=database), then returns. It does NOT cover the
| actual discover()/download()/register() work, which runs later on
| the queue worker, nor the manual "Sync Now" Filament action, which
| calls the same work synchronously and never goes through this
| command at all. The real protection against two overlapping runs
| for the same company (this scheduled job vs. itself, or vs. a
| manual click) is DriveIngestionService::syncInbound()'s own
| per-company Cache::lock -- see that method's doc comment. This
| withoutOverlapping()/onOneServer() pair only prevents redundant
| duplicate dispatches from piling up when the dispatch loop itself is
| somehow slow.
|
*/
if (config('accounting_drive.enabled')) {
    Schedule::command('accounting:drive:sync-inbound')
        ->everyMinute()
        ->withoutOverlapping(5)
        ->onOneServer();
}

/*
|--------------------------------------------------------------------------
| Geofenced attendance housekeeping
|--------------------------------------------------------------------------
|
| hr:flag-forgotten-shifts puts GPS shifts that were never checked out
| (older than hr_attendance_geofence.max_shift_hours) into the HR review
| queue and notifies the line manager. It is idempotent -- a record is
| flagged once -- so running it hourly only shortens how long a forgotten
| shift goes unnoticed.
|
| hr:prune-attendance-evidence nulls precise coordinates/IP/user agent on
| verifications older than evidence_retention_days, keeping the result,
| distance and review trail.
|
*/
Schedule::command('hr:flag-forgotten-shifts')
    ->hourly()
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::command('hr:notify-shift-completion')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();

Schedule::command('hr:prune-attendance-evidence')
    ->dailyAt('02:30')
    ->withoutOverlapping(60)
    ->onOneServer();

/*
| recruitments:remind-posting-removals closes the ads of jobs closed or
| deleted outside the normal flow, then reminds recruiters to take down
| manual job ads (LinkedIn, Rozee.pk, WhatsApp...) of closed jobs and tells
| HR once when the reminders run out (config/recruitment.php).
*/
Schedule::command('recruitments:remind-posting-removals')
    ->dailyAt('09:00')
    ->withoutOverlapping(30)
    ->onOneServer();

/*
| recruitments:pull-website-applications imports the new applications of each
| switched-on company careers website (Recruitment > Configurations > Careers
| website) and acknowledges them. Idempotent, so an overlap or a re-run never
| imports twice; failures wait on the Website intake page. withoutOverlapping(10):
| a slow website (10 s timeout per call, 2 retries) may need longer than one
| 5-minute tick.
*/
Schedule::command('recruitments:pull-website-applications')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();

/*
| recruitments:interview-reminders emails the candidate and the interviewers
| once, about 24 hours before an interview (recruitment.interviews.reminder_hours),
| and asks each interviewer once for feedback after the interview. Every
| email is claimed atomically before it is queued, so an overlap or a re-run
| never sends twice.
*/
Schedule::command('recruitments:interview-reminders')
    ->hourly()
    ->withoutOverlapping(30)
    ->onOneServer();

/*
| hr:today-attendance-digest tells each company's HR managers at 11:00
| Karachi time how many people are absent and late today and how many left
| yesterday without checking out, with a link to Attendance -> Today's
| attendance. Idempotent per company, day and recipient (the notification
| carries a digest key), so an overlap or a re-run never notifies twice.
*/
Schedule::command('hr:today-attendance-digest')
    ->dailyAt('11:00')
    ->timezone(config('attendance.monthly_report.timezone', 'Asia/Karachi'))
    ->withoutOverlapping(30)
    ->onOneServer();

/*
| Employee attendance notices (config/attendance.php -> notifications.*, all
| idempotent: keyed per employee and day, per manager and day, or per
| employee and month, so an overlap or a re-run never notifies twice):
|
| hr:remind-missing-check-in -- "You haven't checked in today", 15 minutes
| after the employee is due: the 10:15 tick, or 14:15 after an approved
| first half off. Weekends, public holidays, approved leave and On-Duty are
| skipped. Runs every 15 minutes in office hours so the 14:15 reminders go
| out too.
|
| hr:line-manager-attendance-digest -- each line manager's own team, late /
| absent so far, at 10:30; nothing to report = no notice.
|
| hr:grace-balance-alerts -- once the office day is over: the first time in
| a month an employee's grace balance is below zero. (Late check-ins raise
| the same alert straight away.)
*/
Schedule::command('hr:remind-missing-check-in')
    ->everyFifteenMinutes()
    ->between('10:00', '18:00')
    ->timezone(config('attendance.monthly_report.timezone', 'Asia/Karachi'))
    ->withoutOverlapping(10)
    ->onOneServer();

Schedule::command('hr:line-manager-attendance-digest')
    ->dailyAt('10:30')
    ->timezone(config('attendance.monthly_report.timezone', 'Asia/Karachi'))
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::command('hr:grace-balance-alerts')
    ->dailyAt('20:00')
    ->timezone(config('attendance.monthly_report.timezone', 'Asia/Karachi'))
    ->withoutOverlapping(30)
    ->onOneServer();

/*
| hr:document-expiry-reminders tells the company's HR (and the employee, for
| document types visible to them) 30 days and 7 days before an employee
| document expires and on expiry. Idempotent per document and threshold
| (recorded on the document), so an overlap or a re-run never notifies twice.
*/
Schedule::command('hr:document-expiry-reminders')
    ->dailyAt('09:00')
    ->timezone(config('attendance.monthly_report.timezone', 'Asia/Karachi'))
    ->withoutOverlapping(30)
    ->onOneServer();

/*
| time-off:yearly-allocation creates each active employee's leave allocations
| for the new year from the company's yearly allocation policy (Time Off ->
| Configuration -> Yearly allocation), carrying forward unused days up to the
| policy cap. Runs once, 1 January 00:30 Karachi time; idempotent (an
| allocation starting 1 January of the year is never created twice), so a
| re-run or a manual run (--year=, --company=, --dry-run) is safe.
*/
Schedule::command('time-off:yearly-allocation')
    ->yearlyOn(1, 1, '00:30')
    ->timezone(config('attendance.monthly_report.timezone', 'Asia/Karachi'))
    ->withoutOverlapping(120)
    ->onOneServer();
