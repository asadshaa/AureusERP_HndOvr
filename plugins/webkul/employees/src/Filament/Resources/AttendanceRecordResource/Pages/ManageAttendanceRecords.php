<?php

namespace Webkul\Employee\Filament\Resources\AttendanceRecordResource\Pages;

use Filament\Resources\Pages\ManageRecords;
use Webkul\Employee\Filament\Resources\AttendanceRecordResource;
use Webkul\Support\Filament\Actions\HelpAction;

class ManageAttendanceRecords extends ManageRecords
{
    protected static string $resource = AttendanceRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('attendance-register'),
        ];
    }
}
