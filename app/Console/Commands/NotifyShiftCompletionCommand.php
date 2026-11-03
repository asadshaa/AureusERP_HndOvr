<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Services\Attendance\AttendanceIntervalService;
use Webkul\Employee\Services\Attendance\AttendanceScheduleResolver;

class NotifyShiftCompletionCommand extends Command
{
    protected $signature = 'hr:notify-shift-completion';

    protected $description = 'Notify employees who have worked their schedule\'s required hours (or reached its end) to remind them to check out';

    public function handle(AttendanceScheduleResolver $scheduleResolver, AttendanceIntervalService $intervals): int
    {
        $nowUtc = CarbonImmutable::now('UTC');
        $notified = 0;

        AttendanceRecord::query()
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->with(['employee.user', 'employee.calendar'])
            ->chunkById(100, function ($records) use ($nowUtc, $scheduleResolver, $intervals, &$notified): void {
                foreach ($records as $record) {
                    $employee = $record->employee;
                    if (! $employee || ! $employee->user) {
                        continue;
                    }

                    $cacheKey = "hr_shift_completed_notified_{$record->id}";
                    if (Cache::has($cacheKey)) {
                        continue;
                    }

                    // Worked = the day's closed pairs plus the running one (breaks between pairs are not work).
                    $dayIntervals = $intervals->intervalsFor($record);
                    $runningFrom = $dayIntervals->isEmpty() ? $record->check_in : $dayIntervals->first(fn ($interval): bool => $interval->isOpen())?->check_in;
                    $elapsedSeconds = (int) $dayIntervals->sum(fn ($interval): int => $interval->durationMinutes() * 60)
                        + ($runningFrom ? max(0, $nowUtc->getTimestamp() - CarbonImmutable::parse($runningFrom)->getTimestamp()) : 0);

                    $attendanceDate = $record->attendance_date?->toDateString() ?? $nowUtc->toDateString();
                    [$schedStartUtc, $schedEndUtc] = $scheduleResolver->scheduledWindowFor($employee, $attendanceDate);
                    // The resolved schedule's required minutes (override > roster > work schedule > calendar > company default).
                    $requiredSeconds = max(1, $scheduleResolver->scheduleFor($employee, $attendanceDate)->requiredMinutes) * 60;

                    $isShiftComplete = $elapsedSeconds >= $requiredSeconds || ($schedEndUtc && $nowUtc >= $schedEndUtc);

                    if ($isShiftComplete) {
                        $totalMinutes = (int) round($elapsedSeconds / 60);
                        $elapsedFormatted = intdiv($totalMinutes, 60).'h '.($totalMinutes % 60).'m';

                        $notification = Notification::make()
                            ->title('Working Hours Complete')
                            ->body("Your working hours are complete ({$elapsedFormatted} worked). You can check out now!")
                            ->icon('heroicon-o-check-circle')
                            ->color('success')
                            ->actions([
                                Action::make('checkout')
                                    ->button()
                                    ->label('Check Out Now')
                                    ->url(route('filament.admin.pages.my-attendance')),
                            ]);

                        $employee->user->notifyNow($notification->toDatabase());

                        Cache::put($cacheKey, true, now()->addHours(24));
                        $notified++;
                    }
                }
            });

        $this->info("Notified {$notified} employee(s) of shift completion.");

        return self::SUCCESS;
    }
}
