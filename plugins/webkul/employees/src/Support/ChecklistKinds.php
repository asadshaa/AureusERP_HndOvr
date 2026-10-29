<?php

namespace Webkul\Employee\Support;

use Carbon\CarbonImmutable;

/**
 * Names shared by onboarding / exit checklist templates and the checklists
 * started from them: the two checklist types, who is responsible for a task,
 * task and checklist statuses, and the few tasks the system understands
 * (it completes them itself, or offers a one-click action).
 */
final class ChecklistKinds
{
    public const Onboarding = 'onboarding';

    public const Offboarding = 'offboarding';

    public const RoleHr = 'hr';

    public const RoleLineManager = 'line_manager';

    public const RoleItAdmin = 'it_admin';

    public const RoleEmployee = 'employee';

    public const ChecklistOpen = 'open';

    public const ChecklistCompleted = 'completed';

    public const ChecklistCancelled = 'cancelled';

    public const TaskPending = 'pending';

    public const TaskDone = 'done';

    public const TaskSkipped = 'skipped';

    public const TaskCancelled = 'cancelled';

    public const SourceManual = 'manual';

    public const SourceDocument = 'document';

    public const SourceSystem = 'system';

    /** Completed when the employee gets a user account. */
    public const ActionCreateUser = 'create_user';

    /** Completed when the employee gets a line manager. */
    public const ActionAssignManager = 'assign_manager';

    /** Completed when the employee gets working hours (a schedule). */
    public const ActionAttendanceSchedule = 'attendance_schedule';

    /** Due a week before the probation end date when one is set. */
    public const ActionProbationReview = 'probation_review';

    /** Shows the employee's open loan and salary advance requests. */
    public const ActionOpenAdvances = 'open_advances';

    /** Links to the HR letter request type; completed when an experience letter is issued. */
    public const ActionExperienceLetter = 'experience_letter';

    /** One-click "Disable user on last working day", run by hr:checklist-disable-users. */
    public const ActionDisableUser = 'disable_user';

    /** Onboarding starts by itself only for someone who joined at most this many days ago, never for back-filled records. */
    public const AutoStartMaxDaysAgo = 30;

    /** @return array<string, string> */
    public static function types(): array
    {
        return [
            self::Onboarding  => 'Onboarding',
            self::Offboarding => 'Exit',
        ];
    }

    /** @return array<string, string> */
    public static function roles(): array
    {
        return [
            self::RoleHr          => 'HR',
            self::RoleLineManager => 'Line manager',
            self::RoleItAdmin     => 'IT / admin',
            self::RoleEmployee    => 'Employee',
        ];
    }

    /** @return array<string, string> */
    public static function actions(): array
    {
        return [
            self::ActionCreateUser         => 'Done when the employee has a user account',
            self::ActionAssignManager      => 'Done when a line manager is set',
            self::ActionAttendanceSchedule => 'Done when working hours or a work schedule are set',
            self::ActionProbationReview    => 'Due a week before the probation end date',
            self::ActionOpenAdvances       => 'Shows open loans and salary advances',
            self::ActionExperienceLetter   => 'Done when an experience letter is issued',
            self::ActionDisableUser        => 'Offers "Disable user on last working day"',
        ];
    }

    /** @return array<string, string> */
    public static function taskStatuses(): array
    {
        return [
            self::TaskPending   => 'To do',
            self::TaskDone      => 'Done',
            self::TaskSkipped   => 'Skipped',
            self::TaskCancelled => 'Cancelled',
        ];
    }

    /** @return array<string, string> */
    public static function checklistStatuses(): array
    {
        return [
            self::ChecklistOpen      => 'Open',
            self::ChecklistCompleted => 'Completed',
            self::ChecklistCancelled => 'Cancelled',
        ];
    }

    public static function typeLabel(?string $type): string
    {
        return self::types()[$type] ?? 'Checklist';
    }

    public static function roleLabel(?string $role): string
    {
        return self::roles()[$role] ?? 'HR';
    }

    public static function taskStatusColor(?string $status): string
    {
        return match ($status) {
            self::TaskDone      => 'success',
            self::TaskSkipped   => 'gray',
            self::TaskCancelled => 'gray',
            default             => 'warning',
        };
    }

    public static function timezone(): string
    {
        return (string) config('attendance.monthly_report.timezone', 'Asia/Karachi');
    }

    /** Today in the companies' local time (Asia/Karachi). */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay();
    }
}
