<?php

namespace Webkul\Accounting\Filament\Clusters\Reporting\Pages;

use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Webkul\Accounting\Enums\ValueSource;
use Webkul\Accounting\Filament\Clusters\Reporting;
use Webkul\Accounting\Models\ReportLine;
use Webkul\Accounting\Services\ReportValueProviderRegistry;
use Webkul\Security\Services\CurrentCompany;

/**
 * Read-only registry view: which external value providers are registered in
 * code, and which report lines reference them. Providers are registered by
 * plugins/apps on the ReportValueProviderRegistry singleton during boot.
 */
class ExternalProviders extends Page
{
    use HasPageShield;

    protected string $view = 'accounting::filament.clusters.reporting.pages.external-providers';

    protected static ?string $cluster = Reporting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?int $navigationSort = 22;

    protected static function getPagePermission(): ?string
    {
        return 'page_accounting_external_providers';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('accounting::filament/clusters/reporting/pages/external-providers.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('accounting::filament/clusters/reporting/pages/external-providers.navigation.title');
    }

    public function getTitle(): string
    {
        return __('accounting::filament/clusters/reporting/pages/external-providers.navigation.title');
    }

    /**
     * @return array{keys: array<int, string>, lines: Collection}
     */
    #[Computed]
    public function providersData(): array
    {
        $registry = app(ReportValueProviderRegistry::class);

        $lines = CurrentCompany::scope(ReportLine::query(), includeShared: true)
            ->whereHas('template', fn (Builder $template) => CurrentCompany::scope($template, includeShared: true))
            ->where('value_source', ValueSource::EXTERNAL->value)
            ->with('template')
            ->orderBy('report_template_id')
            ->get()
            ->map(fn (ReportLine $line) => [
                'template'   => $line->template?->name,
                'caption'    => $line->caption,
                'provider'   => $line->external_provider,
                'registered' => $line->external_provider !== null && $registry->has((string) $line->external_provider),
            ]);

        return [
            'keys'  => $registry->keys(),
            'lines' => $lines,
        ];
    }
}
