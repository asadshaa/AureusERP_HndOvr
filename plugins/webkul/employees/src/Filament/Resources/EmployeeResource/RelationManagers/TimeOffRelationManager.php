<?php

namespace Webkul\Employee\Filament\Resources\EmployeeResource\RelationManagers;

use App\Filament\Widgets\EmployeeDashboardOverviewWidget;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Support\HrPermissions;
use Webkul\TimeOff\Enums\State;

class TimeOffRelationManager extends RelationManager
{
    protected static string $relationship = 'leaves';

    protected static ?string $title = 'Time Off & Leaves';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-calendar-days';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        return $user->can('view_any_time_off_time::off')
            || $user->can('view_any_time_off_my::time::off')
            || $user->can(HrPermissions::ViewAllRecords)
            || ($ownerRecord->user_id !== null && (int) $ownerRecord->user_id === (int) $user->id)
            || ($ownerRecord->parent_id !== null && (int) $ownerRecord->parent?->user_id === (int) $user->id);
    }

    public function table(Table $table): Table
    {
        $employee = $this->getOwnerRecord();
        $leaveData = EmployeeDashboardOverviewWidget::calculateCanonicalLeaves($employee);
        $totalLeft = number_format($leaveData['total_left'] ?? 0, 1);
        $totalAlloc = number_format($leaveData['total_allocated'] ?? 0, 1);

        $cardSummaries = [];
        foreach ($leaveData['cards'] ?? [] as $card) {
            $cardSummaries[] = "{$card['name']}: ".number_format($card['left'], 1).' left';
        }
        $balanceDescription = "Balance: {$totalLeft} / {$totalAlloc} Days (".implode(' • ', $cardSummaries).')';

        return $table
            ->description($balanceDescription)
            ->defaultSort('request_date_from', 'desc')
            ->columns([
                TextColumn::make('holidayStatus.name')
                    ->label('Leave Type')
                    ->badge()
                    ->sortable()
                    ->searchable(),
                TextColumn::make('request_date_from')
                    ->label('From')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('request_date_to')
                    ->label('To')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('number_of_days')
                    ->label('Days')
                    ->numeric(decimalPlaces: 1)
                    ->sortable(),
                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(function ($state): string {
                        $val = $state instanceof State ? $state->value : (string) $state;

                        return match ($val) {
                            'validate_two' => 'Approved',
                            'validate_one' => 'Partially Approved',
                            'confirm'      => 'Pending',
                            'refuse'       => 'Refused',
                            default        => ucfirst($val),
                        };
                    })
                    ->color(function ($state): string {
                        $val = $state instanceof State ? $state->value : (string) $state;

                        return match ($val) {
                            'validate_two' => 'success',
                            'validate_one' => 'info',
                            'confirm'      => 'warning',
                            'refuse'       => 'danger',
                            default        => 'gray',
                        };
                    }),
                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->dateTime('d M Y, h:i A')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('Status')
                    ->options([
                        'confirm'      => 'Pending',
                        'validate_one' => 'Partially Approved',
                        'validate_two' => 'Approved',
                        'refuse'       => 'Refused',
                    ]),
            ])
            ->headerActions([
                Action::make('request_time_off')
                    ->label('Request Time Off')
                    ->icon('heroicon-m-plus')
                    ->url(fn (): string => url('/admin/time-off/dashboard/my-time-offs/create'))
                    ->visible(fn (): bool => (int) ($this->getOwnerRecord()->user_id ?? 0) === (int) Auth::id()),
            ]);
    }
}
