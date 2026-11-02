<?php

namespace Webkul\TimeOff\Filament\Clusters\Configurations\Resources\PublicHolidayResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Webkul\Security\Services\CurrentCompany;
use Webkul\TimeOff\Filament\Clusters\Configurations\Resources\PublicHolidayResource;
use Webkul\TimeOff\Services\PublicHolidayImportService;

class ListPublicHolidays extends ListRecords
{
    protected static string $resource = PublicHolidayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->importPakistanHolidaysAction(),
            CreateAction::make()
                ->label(__('time-off::filament/clusters/configurations/resources/public-holiday/pages/list-public-holiday.header-actions.create.title'))
                ->icon('heroicon-o-plus-circle')
                ->successNotification(
                    Notification::make()
                        ->success()
                        ->title(__('time-off::filament/clusters/configurations/resources/public-holiday/pages/list-public-holiday.header-actions.create.notification.created.title'))
                        ->body(__('time-off::filament/clusters/configurations/resources/public-holiday/pages/list-public-holiday.header-actions.create.notification.created.body'))
                ),
        ];
    }

    /**
     * Adds the bundled Pakistan federal holidays of a year to the active
     * company (PublicHolidayImportService: dates already holidays are
     * skipped, nothing is deleted). Only for those who may create public
     * holidays (HR), checked again when it runs.
     */
    protected function importPakistanHolidaysAction(): Action
    {
        $service = app(PublicHolidayImportService::class);
        $years = $service->bundledYears();
        $lang = 'time-off::filament/clusters/configurations/resources/public-holiday/pages/list-public-holiday.header-actions.import';

        return Action::make('importPakistanHolidays')
            ->label(__("{$lang}.title"))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (): bool => $years !== [] && PublicHolidayResource::canCreate() && CurrentCompany::id() !== null)
            ->authorize(fn (): bool => PublicHolidayResource::canCreate())
            ->modalHeading(__("{$lang}.modal-heading"))
            ->modalDescription(__("{$lang}.modal-description"))
            ->modalSubmitActionLabel(__("{$lang}.submit"))
            ->schema([
                Select::make('year')
                    ->label(__("{$lang}.year"))
                    ->options(array_combine($years, $years))
                    ->default(in_array((int) now()->year, $years, true) ? (int) now()->year : end($years))
                    ->required(),
            ])
            ->action(function (array $data) use ($service, $years, $lang): void {
                $companyId = CurrentCompany::id();
                $year = (int) ($data['year'] ?? 0);

                if (! PublicHolidayResource::canCreate() || $companyId === null || ! CurrentCompany::canAccess($companyId) || ! in_array($year, $years, true)) {
                    Notification::make()->danger()->title(__("{$lang}.notification.denied"))->send();

                    return;
                }

                $result = $service->import($companyId, $service->load((string) $service->bundledFile($year), $year), Auth::user());

                Notification::make()
                    ->success()
                    ->title(__("{$lang}.notification.title", ['year' => $year, 'company' => $result['company']]))
                    ->body(__("{$lang}.notification.body", ['created' => count($result['created']), 'skipped' => count($result['skipped'])]))
                    ->send();
            });
    }
}
