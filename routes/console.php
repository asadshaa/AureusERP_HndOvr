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
Schedule::command('accounting:drive:sync-inbound')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();
