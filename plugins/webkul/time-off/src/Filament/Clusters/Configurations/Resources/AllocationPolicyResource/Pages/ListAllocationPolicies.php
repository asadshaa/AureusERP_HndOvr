<?php

namespace Webkul\TimeOff\Filament\Clusters\Configurations\Resources\AllocationPolicyResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Webkul\Security\Services\CurrentCompany;
use Webkul\TimeOff\Filament\Clusters\Configurations\Resources\AllocationPolicyResource;
use Webkul\TimeOff\Models\LeaveAllocationPolicy;

class ListAllocationPolicies extends ListRecords
{
    protected static string $resource = AllocationPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add_defaults')
                ->label('Add default policies')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->visible(fn (): bool => AllocationPolicyResource::canCreate())
                ->requiresConfirmation()
                ->modalDescription('Adds Annual Leave 12 days (carry forward up to 6), Sick Leave 8 and Casual Leave 5 for this company\'s own leave types that have no policy yet. Existing policies are not changed.')
                ->action(function (): void {
                    $companyId = (int) CurrentCompany::id();
                    $before = LeaveAllocationPolicy::query()->where('company_id', $companyId)->count();
                    LeaveAllocationPolicy::forCompany($companyId);
                    $added = LeaveAllocationPolicy::query()->where('company_id', $companyId)->count() - $before;

                    Notification::make()
                        ->success()
                        ->title($added > 0 ? "{$added} default policy(ies) added" : 'Nothing to add')
                        ->send();
                }),
            CreateAction::make()
                ->label('New policy')
                ->icon('heroicon-o-plus-circle')
                ->mutateDataUsing(function (array $data): array {
                    $data['company_id'] = CurrentCompany::id();

                    return $data;
                }),
        ];
    }
}
