<?php

namespace Webkul\Employee\Filament\Pages;

use BackedEnum;
use Filament\Support\Enums\Width;

/**
 * The phone / home-screen app check-in screen (/admin/check-in, the PWA
 * start page): one large Check in / Check out button, today's status,
 * worked time and last punch. It is My Attendance without the calendar and
 * history: the same access rule, the same Livewire actions (checkIn,
 * checkOut, reportLocationFailure) and therefore the same server-side
 * GeofencedAttendanceService decisions, GPS prompt and messages.
 */
class CheckIn extends MyAttendance
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-finger-print';

    protected static ?string $slug = 'check-in';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'employees::filament.pages.check-in';

    public static function getNavigationLabel(): string
    {
        return 'Check in';
    }

    public function getTitle(): string
    {
        return 'Check in';
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Large;
    }
}
