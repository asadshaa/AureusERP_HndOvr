<?php

namespace App\Filament\Widgets;

use Filament\Resources\Resource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Webkul\Accounting\Filament\Clusters\Accounting\Resources\BankTransactionMappingResource;
use Webkul\Employee\Filament\Resources\EmployeeRequestResource;
use Webkul\Purchase\Filament\Admin\Clusters\Orders\Resources\QuotationResource as PurchaseQuotationResource;
use Webkul\Sale\Filament\Clusters\Orders\Resources\QuotationResource as SaleQuotationResource;
use Webkul\Support\Filament\Resources\ApprovalRequestResource;
use Webkul\TimeOff\Filament\Clusters\Management\Resources\AllocationResource;
use Webkul\TimeOff\Filament\Clusters\Management\Resources\TimeOffResource;

/**
 * A single "what needs me right now" glance across every module, instead of
 * clicking into Approval Requests, then Employee Requests, then Time Off,
 * etc. separately to find out. Deliberately reuses each resource's own
 * getNavigationBadge() rather than re-deriving the counts, so this widget
 * can never drift out of sync with what the sidebar badges already show --
 * and only lists a resource the viewer can actually navigate to, matching
 * what the sidebar itself already hides via canViewAny().
 */
class PendingActionsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -10;

    protected function getStats(): array
    {
        $resources = [
            ['resource' => ApprovalRequestResource::class, 'label' => 'Approval Requests'],
            ['resource' => EmployeeRequestResource::class, 'label' => 'Employee Requests'],
            ['resource' => TimeOffResource::class, 'label' => 'Time Off'],
            ['resource' => AllocationResource::class, 'label' => 'Leave Allocations'],
            ['resource' => BankTransactionMappingResource::class, 'label' => 'Bank Transaction Mapping'],
            ['resource' => SaleQuotationResource::class, 'label' => 'Sales Quotations'],
            ['resource' => PurchaseQuotationResource::class, 'label' => 'Purchase Quotations'],
        ];

        $stats = [];

        foreach ($resources as $entry) {
            /** @var class-string<\Filament\Resources\Resource> $resource */
            $resource = $entry['resource'];

            if (! $resource::canViewAny()) {
                continue;
            }

            $count = (int) ($resource::getNavigationBadge() ?? 0);

            $stats[] = Stat::make($entry['label'], (string) $count)
                ->description($count > 0 ? 'needs attention' : 'all clear')
                ->color($count > 0 ? 'warning' : 'success')
                ->url($resource::getUrl('index'));
        }

        return $stats;
    }
}
