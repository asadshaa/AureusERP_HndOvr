<?php

namespace Webkul\Partner\Filament\Resources;

use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Webkul\Partner\Filament\Resources\PartnerResource\Schemas\PartnerForm;
use Webkul\Partner\Filament\Resources\PartnerResource\Schemas\PartnerInfolist;
use Webkul\Partner\Filament\Resources\PartnerResource\Support\PartnerSchemaRegistry;
use Webkul\Partner\Filament\Resources\PartnerResource\Tables\PartnersTable;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Traits\HasResourcePermissionQuery;

class PartnerResource extends Resource
{
    use HasResourcePermissionQuery;

    protected static ?string $model = Partner::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static bool $isGloballySearchable = false;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email', 'phone'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            __('partners::filament/resources/partner.global-search.email') => $record->email ?? '—',
            __('partners::filament/resources/partner.global-search.phone') => $record->phone ?? '—',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return PartnerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PartnerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PartnersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        // Company isolation, deliberately strict (no "or company_id IS NULL"
        // fallback the way TaxGroup gets): client decision was to scope
        // Partners to the active company only, not treat any partner as
        // globally shared. Every Customer/Vendor resource across accounts,
        // accounting, sales, purchases and invoices extends this base
        // resource and calls parent::getEloquentQuery() first, so they all
        // inherit this automatically.
        $query = parent::getEloquentQuery()
            ->where('company_id', Auth::user()?->default_company_id);

        if ($eagerLoads = PartnerSchemaRegistry::eagerLoads()) {
            $query->with($eagerLoads);
        }

        return $query;
    }
}
