<?php

namespace Webkul\Recruitment\Filament\Clusters\Configurations\Resources\OfferLetterTemplateResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\OfferLetterTemplateResource;
use Webkul\Recruitment\Models\OfferSetting;
use Webkul\Recruitment\Services\JobOfferService;
use Webkul\Security\Services\CurrentCompany;

class ManageOfferLetterTemplates extends ManageRecords
{
    protected static string $resource = OfferLetterTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('offerSettings')
                ->label(__('Offer approval settings'))
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('gray')
                ->modalHeading(__('Offer approval settings'))
                ->modalDescription(__('Offers go to the hiring manager, then HR. Above this monthly gross salary the CEO also approves.'))
                ->fillForm(fn (): array => [
                    'ceo_approval_threshold' => OfferSetting::query()->where('company_id', CurrentCompany::id())->value('ceo_approval_threshold'),
                ])
                ->schema([
                    TextInput::make('ceo_approval_threshold')
                        ->label(__('CEO approval above (monthly gross)'))
                        ->numeric()
                        ->minValue(0)
                        ->prefix((string) config('recruitment.offers.currency', 'PKR'))
                        ->placeholder(number_format((float) config('recruitment.offers.ceo_approval_threshold', 300000)))
                        ->helperText(__('Leave empty for the default (:amount).', ['amount' => number_format((float) config('recruitment.offers.ceo_approval_threshold', 300000))])),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        app(JobOfferService::class)->saveSettings((int) CurrentCompany::id(), filled($data['ceo_approval_threshold'] ?? null) ? (string) $data['ceo_approval_threshold'] : null, Auth::user());
                    } catch (AuthorizationException|ValidationException $e) {
                        Notification::make()->danger()->title(__('Could not save the settings'))->body($e->getMessage())->send();
                        $action->halt();
                    }

                    Notification::make()->success()->title(__('Offer settings saved'))->send();
                }),
            CreateAction::make()
                ->icon('heroicon-o-plus-circle')
                ->modalWidth('5xl')
                ->mutateDataUsing(fn (array $data): array => array_merge($data, ['company_id' => CurrentCompany::id()])),
        ];
    }
}
