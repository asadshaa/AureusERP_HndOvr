<?php

namespace Webkul\Employee\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;
use Webkul\Employee\Models\ApprovalDelegation;
use Webkul\Employee\Services\Reporting\DelegationService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Enums\NavigationGroup;
use Webkul\Support\Filament\Actions\HelpAction;

/**
 * Employees -> Substitute approvers: "while I am away, Sana approves for me".
 * HR sees every arrangement of the company and can name a substitute for
 * anybody; everyone else sees only the ones they made or that name them.
 * DelegationService enforces the rules; this page only calls it.
 */
class ApprovalSubstitutes extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-plus';

    protected static ?int $navigationSort = 13;

    protected static ?string $slug = 'employees/substitute-approvers';

    protected string $view = 'employees::filament.pages.approval-substitutes';

    public static function canAccess(): bool
    {
        $user = CurrentCompany::user();

        return $user instanceof User
            && CurrentCompany::id($user) !== null
            && ($user->can(HrPermissions::ManageReportingLines) || $user->can('view_any_support_approval::request'));
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Employee;
    }

    public static function getNavigationLabel(): string
    {
        return 'Substitute approvers';
    }

    public function getTitle(): string
    {
        return 'Substitute approvers';
    }

    public function getSubheading(): ?string
    {
        return 'Going away? Name someone who can approve in your place for a few days. They never approve their own requests, and you can end it at any time.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->visibleQuery())
            ->columns([
                TextColumn::make('fromUser.name')->label('Person')->weight('medium')->searchable(),
                TextColumn::make('toUser.name')->label('Substitute')->searchable(),
                TextColumn::make('starts_on')->label('From')->date('j M Y')->sortable(),
                TextColumn::make('ends_on')->label('To')->date('j M Y')->sortable(),
                TextColumn::make('reason')->label('Reason')->placeholder('—')->wrap()->limit(60),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (ApprovalDelegation $record): string => self::statusLabel($record))
                    ->color(fn (string $state): string => match ($state) {
                        'In force'     => 'success',
                        'Coming up'    => 'info',
                        'Ended early'  => 'warning',
                        default        => 'gray',
                    }),
                TextColumn::make('creator.name')->label('Set by')->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('end')
                    ->label('End now')
                    ->icon('heroicon-o-stop-circle')
                    ->color('danger')
                    ->visible(fn (ApprovalDelegation $record): bool => $this->service()->canRevoke($this->user(), $record))
                    ->requiresConfirmation()
                    ->modalHeading('End this substitute arrangement?')
                    ->modalDescription(fn (ApprovalDelegation $record): string => $record->toUser?->name.' will no longer be able to approve in place of '.$record->fromUser?->name.'.')
                    ->action(function (ApprovalDelegation $record): void {
                        $this->attempt(fn () => $this->service()->revoke($this->user(), $record), 'Substitute arrangement ended');
                    }),
            ])
            ->paginated([25, 50])
            ->emptyStateIcon('heroicon-o-user-plus')
            ->emptyStateHeading('No substitutes named')
            ->emptyStateDescription('Use "Name a substitute" when someone is going away.');
    }

    /** In plain words: Coming up, In force, Ended or Ended early. */
    public static function statusLabel(ApprovalDelegation $delegation): string
    {
        $today = DelegationService::today();

        return match (true) {
            $delegation->revoked_at !== null                      => 'Ended early',
            $delegation->starts_on->toDateString() > $today       => 'Coming up',
            $delegation->ends_on->toDateString() < $today         => 'Ended',
            default                                               => 'In force',
        };
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('name')
                ->label('Name a substitute')
                ->icon('heroicon-o-plus')
                ->modalWidth('lg')
                ->modalSubmitActionLabel('Name substitute')
                ->fillForm(fn (): array => [
                    'from_user_id' => $this->user()->id,
                    'starts_on'    => DelegationService::today(),
                ])
                ->schema([
                    Select::make('from_user_id')
                        ->label('Who is going away?')
                        ->helperText(fn (): ?string => $this->isHr() ? null : 'You can only name a substitute for your own approvals.')
                        ->searchable()
                        ->options(fn (): array => $this->isHr() ? $this->userOptions() : [$this->user()->id => $this->user()->name])
                        ->getSearchResultsUsing(fn (string $search): array => $this->isHr() ? $this->userOptions($search) : [])
                        ->disabled(fn (): bool => ! $this->isHr())
                        ->dehydrated()
                        ->required(),
                    Select::make('to_user_id')
                        ->label('Who approves instead?')
                        ->helperText('They can approve what would have come to the person, on those days only.')
                        ->searchable()
                        ->options(fn (): array => $this->userOptions())
                        ->getSearchResultsUsing(fn (string $search): array => $this->userOptions($search))
                        ->getOptionLabelUsing(fn ($value): ?string => User::query()->whereKey($value)->value('name'))
                        ->different('from_user_id')
                        ->required(),
                    DatePicker::make('starts_on')
                        ->label('First day')
                        ->minDate(fn (): string => DelegationService::today())
                        ->required(),
                    DatePicker::make('ends_on')
                        ->label('Last day')
                        ->helperText('At most '.DelegationService::MaxDays.' days. You can set another one after it ends.')
                        ->minDate(fn (): string => DelegationService::today())
                        ->maxDate(fn (): string => Carbon::parse(DelegationService::today())->addDays(DelegationService::MaxDays)->toDateString())
                        ->afterOrEqual('starts_on')
                        ->required(),
                    Textarea::make('reason')
                        ->label('Reason (optional)')
                        ->placeholder('For example: annual leave')
                        ->rows(2)
                        ->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $this->attempt(function () use ($data): void {
                        $from = User::query()->findOrFail((int) $data['from_user_id']);
                        $to = User::query()->findOrFail((int) $data['to_user_id']);

                        $this->service()->create(
                            $this->user(),
                            $this->companyId(),
                            $from,
                            $to,
                            (string) $data['starts_on'],
                            (string) $data['ends_on'],
                            $data['reason'] ?? null,
                        );
                    }, 'Substitute named');
                }),
            HelpAction::make('substitutes'),
        ];
    }

    /** @return Builder<ApprovalDelegation> */
    protected function visibleQuery(): Builder
    {
        $query = $this->service()->query($this->companyId());

        if ($this->isHr()) {
            return $query;
        }

        $me = (int) $this->user()->id;

        return $query->where(fn (Builder $inner) => $inner
            ->where('from_user_id', $me)
            ->orWhere('to_user_id', $me)
            ->orWhere('created_by', $me));
    }

    protected function attempt(callable $work, string $success): void
    {
        try {
            $work();
        } catch (RuntimeException|InvalidArgumentException $exception) {
            Notification::make()->title('Not saved')->body($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($success)->success()->send();
    }

    /** @return array<int, string> */
    protected function userOptions(?string $search = null): array
    {
        return CurrentCompany::scopeUsersOf(User::query(), $this->companyId())
            ->where('is_active', true)
            ->when(filled($search), fn (Builder $query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->limit(50)
            ->pluck('name', 'id')
            ->all();
    }

    protected function isHr(): bool
    {
        return $this->user()->can(HrPermissions::ManageReportingLines);
    }

    protected function companyId(): int
    {
        return (int) CurrentCompany::id($this->user());
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = CurrentCompany::user();

        return $user;
    }

    protected function service(): DelegationService
    {
        return app(DelegationService::class);
    }
}
