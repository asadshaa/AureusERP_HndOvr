<?php

namespace Webkul\Accounting\Filament\Clusters\Reporting\Pages;

use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;
use Webkul\Accounting\Data\ValidationIssue;
use Webkul\Accounting\Enums\TemplateStatus;
use Webkul\Accounting\Filament\Clusters\Reporting;
use Webkul\Accounting\Models\ReportTemplate;
use Webkul\Accounting\Services\ReportTemplateValidator;
use Webkul\Security\Services\CurrentCompany;

/**
 * The live Finance review board: every unmapped account line, unresolved
 * formula and structural issue across all report templates, produced by the
 * template validator. This is the interactive counterpart of
 * STAGE4_UNMAPPED_ACCOUNTS.md / STAGE4_UNRESOLVED_FORMULAS.md.
 */
class ReportMappingReview extends Page
{
    use HasPageShield;

    protected string $view = 'accounting::filament.clusters.reporting.pages.report-mapping-review';

    protected static ?string $cluster = Reporting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?int $navigationSort = 21;

    protected static function getPagePermission(): ?string
    {
        return 'page_accounting_report_mapping_review';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('accounting::filament/clusters/reporting/pages/report-mapping-review.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('accounting::filament/clusters/reporting/pages/report-mapping-review.navigation.title');
    }

    public function getTitle(): string
    {
        return __('accounting::filament/clusters/reporting/pages/report-mapping-review.navigation.title');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function reviewData(): array
    {
        $validator = app(ReportTemplateValidator::class);

        return CurrentCompany::scope(ReportTemplate::query(), includeShared: true)
            ->where('status', '!=', TemplateStatus::ARCHIVED->value)
            ->orderBy('sort')
            ->get()
            ->map(function (ReportTemplate $template) use ($validator) {
                $issues = $validator->validate($template);

                return [
                    'template' => $template,
                    'unmapped' => $issues->filter(fn (ValidationIssue $i) => $i->code === 'missing_account_bindings')->values(),
                    'formulas' => $issues->filter(fn (ValidationIssue $i) => in_array($i->code, [
                        'missing_formulas',
                        'formula_cycle',
                        'missing_operand_line',
                        'cross_template_operand',
                        'operand_not_computable',
                        'operand_line_missing_id',
                        'operand_constant_missing_value',
                    ], true))->values(),
                    'other' => $issues->reject(fn (ValidationIssue $i) => in_array($i->code, [
                        'missing_account_bindings',
                        'missing_formulas',
                        'formula_cycle',
                        'missing_operand_line',
                        'cross_template_operand',
                        'operand_not_computable',
                        'operand_line_missing_id',
                        'operand_constant_missing_value',
                    ], true))->values(),
                ];
            })
            ->all();
    }
}
