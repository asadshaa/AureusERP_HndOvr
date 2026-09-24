<?php

namespace Webkul\Support\Filament\Resources;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Support\Enums\NavigationGroup;
use Webkul\Support\Filament\Resources\ApprovalRequestResource\Pages\ListApprovalRequests;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Services\ApprovalEngine;

class ApprovalRequestResource extends Resource
{
    protected static ?string $model = ApprovalRequest::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-check-badge';

    protected static ?int $navigationSort = 81;

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Setting;
    }

    public static function getNavigationLabel(): string
    {
        return 'Approval Queue';
    }

    /**
     * Counts only requests the CURRENT viewer can actually act on right
     * now -- not every pending request company-wide, which would often be
     * misleading (a request waiting on someone else's approval step isn't
     * something this user can do anything about). Reuses the exact same
     * canAct() check the row-level Approve/Reject buttons already gate on,
     * so the badge and what's actually clickable always agree.
     */
    public static function getNavigationBadge(): ?string
    {
        $user = Auth::user();

        if (! $user) {
            return null;
        }

        $engine = app(ApprovalEngine::class);

        $count = static::getEloquentQuery()
            ->where('status', 'pending')
            ->get()
            ->filter(fn (ApprovalRequest $request): bool => $engine->canAct($request, $user))
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Auth::user()?->default_company_id)
            ->with(['workflow', 'requester', 'decisions.actor']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Request')->formatStateUsing(fn ($state): string => 'APR-'.$state)->sortable(),
                TextColumn::make('workflow.name')->label('Workflow')->searchable(),
                TextColumn::make('request_type')->badge()->searchable(),
                TextColumn::make('requester.name')->label('Requester')->placeholder('System'),
                TextColumn::make('amount')->numeric(decimalPlaces: 2)->placeholder('—'),
                TextColumn::make('current_step_sequence')->label('Step')->placeholder('Complete'),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'approved' => 'success', 'rejected' => 'danger', default => 'warning',
                }),
                TextColumn::make('submitted_at')->dateTime()->sortable(),
                TextColumn::make('completed_at')->dateTime()->placeholder('Pending'),
            ])
            // Oldest pending first -- unlike a "new arrivals" queue, an
            // approval sitting unactioned the longest is the one most
            // likely to be blocking someone else's downstream work, so it
            // belongs at the top, not a freshly-submitted one.
            ->defaultSort('submitted_at', 'asc')
            ->filters([
                SelectFilter::make('status')->options([
                    'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected',
                ]),
            ])
            ->recordActions([
                Action::make('approve')
                    ->color('success')
                    ->icon('heroicon-o-check')
                    ->schema([Textarea::make('reason')->label('Approval note')])
                    ->visible(fn (ApprovalRequest $record): bool => Auth::user() !== null
                        && app(ApprovalEngine::class)->canAct($record, Auth::user()))
                    ->action(function (ApprovalRequest $record, array $data): void {
                        app(ApprovalEngine::class)->approve($record, Auth::user(), $data['reason'] ?? null);
                        Notification::make()->success()->title('Approval recorded')->send();
                    }),
                Action::make('reject')
                    ->color('danger')
                    ->icon('heroicon-o-x-mark')
                    ->requiresConfirmation()
                    ->schema([Textarea::make('reason')->required()])
                    ->visible(fn (ApprovalRequest $record): bool => Auth::user() !== null
                        && app(ApprovalEngine::class)->canAct($record, Auth::user()))
                    ->action(function (ApprovalRequest $record, array $data): void {
                        app(ApprovalEngine::class)->reject($record, Auth::user(), (string) $data['reason']);
                        Notification::make()->success()->title('Request rejected')->send();
                    }),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListApprovalRequests::route('/')];
    }
}
