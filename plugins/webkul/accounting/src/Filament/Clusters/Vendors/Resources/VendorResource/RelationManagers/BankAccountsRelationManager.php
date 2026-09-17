<?php

namespace Webkul\Accounting\Filament\Clusters\Vendors\Resources\VendorResource\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Webkul\Partner\Filament\Resources\BankAccountResource;

class BankAccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'bankAccounts';

    public function form(Schema $schema): Schema
    {
        return BankAccountResource::form($schema);
    }

    public function table(Table $table): Table
    {
        return BankAccountResource::table($table)
            ->headerActions([
                // Found during the 4-role manual test walkthrough, same gap
                // as ManualAdjustmentResource/JournalEntryResource: a
                // RelationManager has no dedicated /create route to fall
                // back on at all, so this modal action was completely
                // unguarded -- a read-only role (e.g. Internal Auditor, who
                // can already open any Vendor via view_accounting_vendor)
                // could add a bank account to a real vendor. Gated on the
                // same Shield permission VendorResource's own default
                // canEdit()/canCreate() already rely on for the vendor
                // record itself -- managing its bank accounts is part of
                // editing the vendor, not a separate capability.
                CreateAction::make()
                    ->label(__('accounting::filament/clusters/vendors/resources/vendor/relation-manager/bank-account-relation-manager.create-bank-account'))
                    ->icon('heroicon-o-plus-circle')
                    ->authorize(fn (): bool => Auth::user()?->can('update_accounting_vendor') ?? false)
                    ->mutateDataUsing(function (array $data): array {
                        return $data;
                    }),
            ]);
    }
}
