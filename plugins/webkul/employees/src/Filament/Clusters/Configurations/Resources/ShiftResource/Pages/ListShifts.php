<?php

namespace Webkul\Employee\Filament\Clusters\Configurations\Resources\ShiftResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Model;
use Webkul\Employee\Filament\Clusters\Configurations\Resources\ShiftResource;
use Webkul\Employee\Models\Shift;
use Webkul\Security\Services\CurrentCompany;

class ListShifts extends ListRecords
{
    protected static string $resource = ShiftResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->icon('heroicon-o-plus-circle')
                ->label('New shift')
                ->using(fn (array $data, CreateAction $action): ?Model => ShiftResource::guarded(
                    $action,
                    fn (): Shift => Shift::query()->create($data + ['company_id' => CurrentCompany::id()]),
                )),
        ];
    }
}
