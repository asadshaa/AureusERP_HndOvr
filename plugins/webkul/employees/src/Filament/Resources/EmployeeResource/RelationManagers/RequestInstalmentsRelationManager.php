<?php

namespace Webkul\Employee\Filament\Resources\EmployeeResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use RuntimeException;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\RequestInstalment;
use Webkul\Employee\Services\Payroll\RequestInstalmentService;
use Webkul\Employee\Support\EmployeeRequestKinds;
use Webkul\Security\Models\User;

/**
 * "Loans & advances" tab of an employee: every instalment of their approved
 * loans and the deduction of each approved salary advance, by request, with
 * its status. Payroll HR of the company (hr_export_payroll, never on their
 * own) marks instalments deducted or skipped, undoes a deduction, reschedules
 * what is left or settles a loan early; RequestInstalmentService checks every
 * change again and audits it. The employee sees their own (read-only).
 */
class RequestInstalmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'requestInstalments';

    protected static ?string $title = 'Loans & advances';

    public function table(Table $table): Table
    {
        return $table
            ->description('Approved loans become one instalment per month; an approved salary advance is one deduction in its deduct-from month. Due amounts feed the monthly payroll input.')
            ->modifyQueryUsing(fn ($query) => $query->with(['request.currency', 'request.employee', 'changer']))
            ->defaultGroup(
                Group::make('request_id')
                    ->label('Request')
                    ->getTitleFromRecordUsing(fn (RequestInstalment $record): string => RequestInstalment::kindLabel($record->kind).' '
                        .($record->request?->reference ?: '#'.$record->request_id).' — '
                        .number_format((float) $record->request?->amount, 2).' '.($record->request?->currency?->code ?? ''))
                    ->collapsible(),
            )
            ->columns([
                TextColumn::make('number')->label('#'),
                TextColumn::make('due_month')->label('Month')
                    ->formatStateUsing(fn (?string $state): string => RequestInstalmentService::monthLabel($state))
                    ->sortable(),
                TextColumn::make('amount')->label('Amount')
                    ->formatStateUsing(fn ($state, RequestInstalment $record): string => number_format((float) $state, 2).' '.($record->request?->currency?->code ?? ''))
                    ->alignEnd(),
                TextColumn::make('status')->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => RequestInstalment::statusLabels()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => RequestInstalment::statusColor($state)),
                TextColumn::make('note')->label('Note')->placeholder('—')->wrap()->limit(80),
                TextColumn::make('changer.name')->label('Changed by')->placeholder('—')->toggleable(),
                TextColumn::make('status_changed_at')->label('Changed on')->dateTime('d M Y H:i')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(RequestInstalment::statusLabels()),
            ])
            ->recordActions([
                Action::make('markDeducted')
                    ->label('Mark deducted')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (RequestInstalment $record): bool => $record->status === RequestInstalment::Due && $this->canManage($record))
                    ->requiresConfirmation()
                    ->modalDescription(fn (RequestInstalment $record): string => 'Instalment #'.$record->number.' of '.RequestInstalmentService::monthLabel($record->due_month).' was deducted in payroll.')
                    ->schema([
                        TextInput::make('note')->label('Note (optional)')->maxLength(1000),
                    ])
                    ->action(fn (RequestInstalment $record, array $data) => $this->guarded(
                        fn () => app(RequestInstalmentService::class)->markDeducted($record, $this->user(), $data['note'] ?? null),
                        'Instalment marked as deducted',
                    )),
                Action::make('skip')
                    ->label('Skip')
                    ->icon('heroicon-o-forward')
                    ->color('warning')
                    ->visible(fn (RequestInstalment $record): bool => $record->status === RequestInstalment::Due && $this->canManage($record))
                    ->modalHeading(fn (RequestInstalment $record): string => 'Skip instalment #'.$record->number.' ('.RequestInstalmentService::monthLabel($record->due_month).')?')
                    ->schema([
                        Textarea::make('reason')->label('Reason')->required()->minLength(3)->maxLength(1000),
                        Toggle::make('carry_forward')
                            ->label('Add the amount as a new instalment after the last one')
                            ->helperText('Leave on so the loan still adds up. Turn off only if you will reschedule what is left.')
                            ->default(true),
                    ])
                    ->action(fn (RequestInstalment $record, array $data) => $this->guarded(
                        fn () => app(RequestInstalmentService::class)->skip($record, $this->user(), (string) $data['reason'], (bool) ($data['carry_forward'] ?? true)),
                        'Instalment skipped',
                    )),
                Action::make('revert')
                    ->label('Undo deduction')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (RequestInstalment $record): bool => $record->status === RequestInstalment::Deducted && $this->canManage($record))
                    ->modalDescription('The instalment becomes due again (for example when it was marked deducted by mistake).')
                    ->schema([
                        Textarea::make('reason')->label('Reason')->required()->minLength(3)->maxLength(1000),
                    ])
                    ->action(fn (RequestInstalment $record, array $data) => $this->guarded(
                        fn () => app(RequestInstalmentService::class)->revertToDue($record, $this->user(), (string) $data['reason']),
                        'Instalment is due again',
                    )),
                Action::make('reschedule')
                    ->label('Reschedule rest')
                    ->icon('heroicon-o-calendar-days')
                    ->color('info')
                    ->visible(fn (RequestInstalment $record): bool => $record->status === RequestInstalment::Due && $this->canManage($record))
                    ->modalHeading('Reschedule what is left')
                    ->modalDescription('Every remaining due instalment of this request is replaced: what is left (amount minus deducted and settled) is split into equal monthly instalments from the first month you choose. The old plan is kept in the audit trail.')
                    ->fillForm(fn (RequestInstalment $record): array => [
                        'start_month' => $record->due_month,
                        'instalments' => RequestInstalment::query()->where('request_id', $record->request_id)->where('status', RequestInstalment::Due)->count(),
                    ])
                    ->schema([
                        Select::make('start_month')->label('First month')->options(fn (): array => $this->monthOptions())->required(),
                        TextInput::make('instalments')->label('Number of instalments')->integer()->required()
                            ->minValue(EmployeeRequestKinds::MinInstalments)->maxValue(EmployeeRequestKinds::MaxInstalments),
                        Textarea::make('reason')->label('Reason')->required()->minLength(3)->maxLength(1000),
                    ])
                    ->action(fn (RequestInstalment $record, array $data) => $this->guarded(
                        fn () => app(RequestInstalmentService::class)->reschedule($this->requestOf($record), $this->user(), (string) $data['start_month'], (int) $data['instalments'], (string) $data['reason']),
                        'Instalments rescheduled',
                    )),
                Action::make('settle')
                    ->label('Settle early')
                    ->icon('heroicon-o-banknotes')
                    ->color('danger')
                    ->visible(fn (RequestInstalment $record): bool => $record->status === RequestInstalment::Due && $this->canManage($record))
                    ->requiresConfirmation()
                    ->modalHeading('Settle the rest early?')
                    ->modalDescription('Every remaining due instalment of this request is closed as settled and no longer appears in the payroll input. To take the balance through payroll instead, reschedule it into one instalment.')
                    ->schema([
                        Textarea::make('reason')->label('How it was settled')->required()->minLength(3)->maxLength(1000),
                    ])
                    ->action(fn (RequestInstalment $record, array $data) => $this->guarded(
                        fn () => app(RequestInstalmentService::class)->settle($this->requestOf($record), $this->user(), (string) $data['reason']),
                        'Settled',
                    )),
            ])
            ->defaultSort('number')
            ->paginated([25, 50, 100]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && $ownerRecord instanceof Employee
            && RequestInstalmentService::available()
            && app(RequestInstalmentService::class)->canView($user, $ownerRecord);
    }

    private function canManage(RequestInstalment $record): bool
    {
        $user = Auth::user();
        $request = $record->request;

        return $user instanceof User && $request !== null && app(RequestInstalmentService::class)->canManage($user, $request);
    }

    private function requestOf(RequestInstalment $record): EmployeeRequest
    {
        return EmployeeRequest::query()->with(['employee', 'requestType'])->findOrFail($record->request_id);
    }

    /** Runs a change and reports the outcome; the service refuses anything not allowed. */
    private function guarded(callable $change, string $success): void
    {
        try {
            $change();
            Notification::make()->success()->title($success)->send();
        } catch (AuthorizationException|InvalidArgumentException|RuntimeException $e) {
            Notification::make()->danger()->title('Not changed')->body($e->getMessage())->send();
        }
    }

    /** @return array<string, string> */
    private function monthOptions(): array
    {
        $month = Carbon::now(config('attendance.monthly_report.timezone'))->startOfMonth()->subMonthNoOverflow();
        $options = [];

        for ($i = 0; $i < 26; $i++) {
            $options[$month->format('Y-m')] = $month->format('F Y');
            $month = $month->addMonthNoOverflow();
        }

        return $options;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
