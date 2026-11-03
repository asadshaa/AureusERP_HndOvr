<?php

namespace Webkul\Accounting\Filament\Clusters\Reporting\Resources\ReportTemplateResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Webkul\Accounting\Data\ValidationIssue;
use Webkul\Accounting\Filament\Clusters\Reporting\Pages\FinancialReports;
use Webkul\Accounting\Filament\Clusters\Reporting\Resources\ReportTemplateResource;
use Webkul\Accounting\Services\ReportTemplateValidator;
use Webkul\Chatter\Filament\Actions\ChatterAction;
use Webkul\Security\Services\CurrentCompany;

class EditReportTemplate extends EditRecord
{
    protected static string $resource = ReportTemplateResource::class;

    protected function beforeSave(): void
    {
        if (! $this->getRecord()->isDraft()) {
            Notification::make()
                ->warning()
                ->title(__('accounting::filament/clusters/reporting/resources/report-template.pages.edit.immutable', [
                    'status' => $this->getRecord()->statusEnum()->getLabel(),
                ]))
                ->send();

            $this->halt();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            ChatterAction::make()
                ->resource(static::getResource()),
            Action::make('validate')
                ->label(__('accounting::filament/clusters/reporting/resources/report-template.pages.edit.actions.validate.label'))
                ->icon('heroicon-o-shield-check')
                ->color('info')
                ->action(function () {
                    $issues = app(ReportTemplateValidator::class)->validate($this->getRecord(), CurrentCompany::id());

                    if ($issues->isEmpty()) {
                        Notification::make()
                            ->success()
                            ->title(__('accounting::filament/clusters/reporting/resources/report-template.pages.edit.actions.validate.passed'))
                            ->send();

                        return;
                    }

                    $body = $issues
                        ->take(12)
                        ->map(fn (ValidationIssue $issue) => strtoupper($issue->severity).': '.$issue->message)
                        ->implode("\n");

                    if ($issues->count() > 12) {
                        $body .= "\n".__('accounting::filament/clusters/reporting/resources/report-template.pages.edit.actions.validate.more', [
                            'count' => $issues->count() - 12,
                        ]);
                    }

                    Notification::make()
                        ->danger()
                        ->title(__('accounting::filament/clusters/reporting/resources/report-template.pages.edit.actions.validate.failed', [
                            'count' => $issues->count(),
                        ]))
                        ->body($body)
                        ->persistent()
                        ->send();
                }),
            Action::make('preview')
                ->label(__('accounting::filament/clusters/reporting/resources/report-template.pages.edit.actions.preview'))
                ->icon('heroicon-o-eye')
                ->url(fn () => FinancialReports::getUrl(['template' => $this->getRecord()->id])),
            ReportTemplateResource::publishAction(),
            ReportTemplateResource::newVersionAction(),
            ReportTemplateResource::archiveAction(),
            DeleteAction::make()
                ->visible(fn () => $this->getRecord()->isDraft()),
        ];
    }
}
