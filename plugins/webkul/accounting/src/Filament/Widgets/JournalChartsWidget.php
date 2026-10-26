<?php

namespace Webkul\Accounting\Filament\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;
use Webkul\Account\Models\Journal;

class JournalChartsWidget extends Widget
{
    /** Rendered with the dashboard instead of in its own request. */
    protected static bool $isLazy = false;

    protected string $view = 'accounting::filament.widgets.journal-charts-widget';

    protected int|string|array $columnSpan = 'full';

    public string $activeTab = 'all';

    public static function canView(): bool
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        return $user->hasRole([
            'Admin',
            'Super Admin',
            'accountant',
            'accounting_manager',
            'cfo',
            'controller',
            'vp_finance',
            'finance_operator',
            'treasury_officer',
            'reconciliation_officer',
            'ap_officer',
            'ar_officer',
        ])
        || (int) $user->id === 1
        || $user->can('view_any_account_journal');
    }

    public function getJournals()
    {
        $companyId = (int) (Auth::user()?->default_company_id ?? 1);

        return Journal::where('company_id', $companyId)
            ->where('show_on_dashboard', true)
            ->orderBy('id', 'asc')
            ->when($this->activeTab !== 'all', function ($query) {
                $query->where('type', $this->activeTab);
            })
            ->get();
    }
}
