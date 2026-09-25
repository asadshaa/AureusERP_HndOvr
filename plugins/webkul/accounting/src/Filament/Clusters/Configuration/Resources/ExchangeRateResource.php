<?php

namespace Webkul\Accounting\Filament\Clusters\Configuration\Resources;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Throwable;
use Webkul\Accounting\Enums\ExchangeRateApprovalStatus;
use Webkul\Accounting\Enums\ExchangeRateSource;
use Webkul\Accounting\Enums\ExchangeRateType;
use Webkul\Accounting\Filament\Clusters\Configuration;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\ExchangeRateResource\Pages\CreateExchangeRate;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\ExchangeRateResource\Pages\EditExchangeRate;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\ExchangeRateResource\Pages\ListExchangeRates;
use Webkul\Accounting\Models\ExchangeRate;
use Webkul\Accounting\Services\Currency\ExchangeRateApprovalService;
use Webkul\Accounting\Support\AccountingPermissions;
use Webkul\Support\Models\Currency;
use Webkul\Support\Services\ApprovalEngine;

class ExchangeRateResource extends Resource
{
    protected static ?string $model = ExchangeRate::class;

    protected static ?string $cluster = Configuration::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?int $navigationSort = 6;

    public static function getNavigationLabel(): string
    {
        return 'Exchange Rates';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Auth::user()?->default_company_id)
            ->with(['sourceCurrency', 'targetCurrency', 'creator', 'approver']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dated exchange rate')->columns(2)->schema([
                Select::make('company_id')
                    ->options(fn (): array => [(int) Auth::user()?->default_company_id => Auth::user()?->defaultCompany?->name])
                    ->default(Auth::user()?->default_company_id)
                    ->disabled()
                    ->dehydrated()
                    ->required(),
                static::currencySelect('source_currency_id', 'Source currency')->required(),
                static::currencySelect('target_currency_id', 'Target currency')->required()->different('source_currency_id'),
                DatePicker::make('effective_date')->required(),
                TextInput::make('rate')
                    ->label('Rate (1 source unit = X target units)')
                    ->required()
                    ->rule('regex:/^\d{1,15}(\.\d{1,15})?$/')
                    ->helperText('Up to 15 decimal places; must be greater than zero.'),
                Select::make('rate_type')->options(ExchangeRateType::options())->required(),
                Select::make('source')->options(ExchangeRateSource::options())->required(),
                TextInput::make('source_reference')->maxLength(255),
                TextInput::make('provider')->maxLength(255),
                Textarea::make('notes')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('effective_date')->date()->sortable(),
                TextColumn::make('sourceCurrency.code')->label('From')->searchable(),
                TextColumn::make('targetCurrency.code')->label('To')->searchable(),
                TextColumn::make('rate')->copyable()->sortable(),
                TextColumn::make('rate_type')->badge(),
                TextColumn::make('source')->badge(),
                TextColumn::make('approval_status')->badge(),
                TextColumn::make('source_reference')->toggleable(),
                TextColumn::make('provider')->toggleable(),
                TextColumn::make('approver.name')->placeholder('—')->toggleable(),
                TextColumn::make('approved_at')->dateTime()->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('approval_status')->options(ExchangeRateApprovalStatus::options()),
                SelectFilter::make('rate_type')->options(ExchangeRateType::options()),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (ExchangeRate $record): bool => $record->approval_status !== ExchangeRateApprovalStatus::Approved),
                Action::make('submit_approval')
                    ->label('Submit for approval')
                    ->authorize(AccountingPermissions::ManageExchangeRates)
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn (ExchangeRate $record): bool => $record->approval_status !== ExchangeRateApprovalStatus::Approved
                        && app(ExchangeRateApprovalService::class)->requiresConfiguredApproval($record))
                    ->action(function (ExchangeRate $record): void {
                        try {
                            $request = app(ExchangeRateApprovalService::class)->submit($record, Auth::user());
                            Notification::make()->success()
                                ->title("Approval request APR-{$request->id} is in the shared approval queue.")
                                ->body(app(ApprovalEngine::class)->describeCurrentApprover($request))
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title('Could not submit for approval')->body($e->getMessage())->send();
                        }
                    }),
                Action::make('approve')
                    ->authorize(AccountingPermissions::ApproveExchangeRates)
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (ExchangeRate $record): bool => $record->approval_status !== ExchangeRateApprovalStatus::Approved)
                    ->action(function (ExchangeRate $record): void {
                        try {
                            app(ExchangeRateApprovalService::class)->approve($record, Auth::user());
                            Notification::make()->success()->title('Exchange rate approved. Missing bank conversions were refreshed.')->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title('Could not approve this exchange rate')->body($e->getMessage())->send();
                        }
                    }),
                Action::make('reject')
                    ->authorize(AccountingPermissions::ApproveExchangeRates)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ExchangeRate $record): bool => $record->approval_status !== ExchangeRateApprovalStatus::Rejected)
                    ->action(function (ExchangeRate $record): void {
                        try {
                            app(ExchangeRateApprovalService::class)->reject($record, Auth::user());
                            Notification::make()->warning()->title('Exchange rate rejected.')->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title('Could not reject this exchange rate')->body($e->getMessage())->send();
                        }
                    }),
            ])
            ->defaultSort('effective_date', 'desc');
    }

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        // ManageExchangeRates alone used to gate this -- meaning a role
        // that can only approve (ApproveExchangeRates) or only needs
        // read-only visibility (ViewExchangeRates, e.g. Internal Auditor,
        // VP Finance, CFO) couldn't see the list at all. Neither addition
        // widens what ManageExchangeRates-holders can already do.
        return $user !== null && ($user->can(AccountingPermissions::ManageExchangeRates)
            || $user->can(AccountingPermissions::ApproveExchangeRates)
            || $user->can(AccountingPermissions::ViewExchangeRates));
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->can(AccountingPermissions::ManageExchangeRates) ?? false;
    }

    /**
     * canEdit() already existed (below) and correctly gates on
     * ManageExchangeRates. canDelete() did not exist at all -- same gap
     * as ManualAdjustmentResource, just partial here: no Policy class
     * exists for ExchangeRate, so without an explicit override Filament
     * defaults to allowing delete for any authenticated user regardless
     * of permissions.
     */
    public static function canDelete($record): bool
    {
        return Auth::user()?->can(AccountingPermissions::ManageExchangeRates) ?? false;
    }

    public static function canEdit($record): bool
    {
        return (Auth::user()?->can(AccountingPermissions::ManageExchangeRates) ?? false)
            && $record->approval_status !== ExchangeRateApprovalStatus::Approved;
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListExchangeRates::route('/'),
            'create' => CreateExchangeRate::route('/create'),
            'edit'   => EditExchangeRate::route('/{record}/edit'),
        ];
    }

    private static function currencySelect(string $name, string $label): Select
    {
        $companyId = (int) Auth::user()?->default_company_id;

        return Select::make($name)
            ->label($label)
            ->searchable()
            ->options(fn (): array => Currency::query()->active()
                ->whereHas('enabledCompanies', fn ($query) => $query->where('companies.id', $companyId))
                ->orderBy('display_order')->limit(50)->get()
                ->mapWithKeys(fn (Currency $currency): array => [$currency->id => $currency->display_name])->all())
            ->getSearchResultsUsing(fn (string $search): array => Currency::query()
                ->active()
                ->whereHas('enabledCompanies', fn ($query) => $query->where('companies.id', $companyId))
                ->where(fn ($query) => $query->where('code', 'like', "%{$search}%")->orWhere('full_name', 'like', "%{$search}%"))
                ->orderBy('display_order')
                ->limit(50)
                ->get()
                ->mapWithKeys(fn (Currency $currency): array => [$currency->id => $currency->display_name])
                ->all())
            ->getOptionLabelUsing(fn ($value): ?string => Currency::query()->find($value)?->display_name);
    }
}
