<?php

namespace Webkul\Recruitment\Filament\Clusters\Applications\Resources\ApplicantResource\RelationManagers;

use Filament\Actions\ActionGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\JobOfferResource;
use Webkul\Recruitment\Filament\Schemas\JobOfferComponents;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\JobOffer;

/**
 * "Offers" tab of an application (HR parity 3.6): every offer made, with its
 * status, and the next step on each. Applications of another member
 * company are view-only (the actions need the active company);
 * JobOfferService checks every action again.
 */
class OffersRelationManager extends RelationManager
{
    protected static string $relationship = 'offers';

    protected static ?string $title = 'Offers';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-document-text';

    /** Actions decide for themselves (view pages are read-only by default in Filament). */
    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(JobOfferComponents::tableRelations()))
            ->defaultSort('id', 'desc')
            ->emptyStateHeading(__('No offers yet'))
            ->emptyStateDescription(__('Make an offer: it is approved, then sent to the candidate with the letter and an accept / decline link.'))
            ->columns(JobOfferComponents::columns(withApplicant: false))
            ->recordUrl(fn (JobOffer $record): string => JobOfferResource::getUrl('view', ['record' => $record]))
            ->headerActions([
                JobOfferComponents::makeOfferAction(fn (): Applicant => $this->applicant(), 'makeOfferFromTab'),
            ])
            ->recordActions([
                ActionGroup::make(JobOfferComponents::recordActions()),
            ]);
    }

    protected function applicant(): Applicant
    {
        /** @var Applicant $applicant */
        $applicant = $this->getOwnerRecord();

        return $applicant;
    }
}
