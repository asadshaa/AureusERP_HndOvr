<?php

namespace Webkul\Employee\Filament\Clusters\Configurations\Resources\ShiftRosterResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Model;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\ShiftResource;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\ShiftRosterResource;
use Webkul\Employee\Models\ShiftRoster;
use Webkul\Security\Services\CurrentCompany;

class ListShiftRosters extends ListRecords
{
    protected static string $resource = ShiftRosterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->icon('heroicon-o-plus-circle')
                ->label('New roster pattern')
                ->using(fn (array $data, CreateAction $action): ?Model => ShiftResource::guarded(
                    $action,
                    fn (): ShiftRoster => ShiftRoster::query()->create($data + ['company_id' => CurrentCompany::id()]),
                )),
        ];
    }
}
