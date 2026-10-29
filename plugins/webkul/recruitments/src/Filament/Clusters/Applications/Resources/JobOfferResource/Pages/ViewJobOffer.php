<?php

namespace Webkul\Recruitment\Filament\Clusters\Applications\Resources\JobOfferResource\Pages;

use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\JobOfferResource;
use Webkul\Recruitment\Filament\Schemas\JobOfferComponents;
use Webkul\Recruitment\Models\JobOffer;

/**
 * One offer: its terms (salary only for HR, hiring managers and approvers),
 * approval, letter, the candidate's answer and the full history, with the
 * next steps as header actions. JobOfferService checks every action.
 */
class ViewJobOffer extends ViewRecord
{
    protected static string $resource = JobOfferResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var JobOffer $offer */
        $offer = $this->getRecord();

        return __('Offer :reference: :candidate', ['reference' => $offer->reference, 'candidate' => $offer->candidateName()]);
    }

    protected function getHeaderActions(): array
    {
        $actions = JobOfferComponents::recordActions(withView: false);
        $primary = array_slice($actions, 0, 9);
        $more = array_slice($actions, 9);

        return [
            ...$primary,
            ActionGroup::make([
                ...$more,
                DeleteAction::make()
                    ->label(__('Delete draft'))
                    ->successRedirectUrl(fn (JobOffer $record): string => $record->applicant
                        ? ApplicantResource::getUrl('view', ['record' => $record->applicant])
                        : JobOfferResource::getUrl('index')),
            ]),
        ];
    }
}
