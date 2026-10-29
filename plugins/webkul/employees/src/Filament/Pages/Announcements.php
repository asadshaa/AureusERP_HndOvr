<?php

namespace Webkul\Employee\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Webkul\Employee\Models\Announcement;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\AnnouncementService;
use Webkul\Employee\Services\CelebrationService;
use Webkul\Employee\Support\ChecklistKinds;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Enums\NavigationGroup;

/**
 * Announcements of the active company: everyone sees the live ones
 * addressed to them and marks them read (or acknowledges them when asked);
 * HR (hr_manage_announcements) also posts, edits and removes them and sees
 * how many people read and acknowledged each one. ?announcement=ID opens
 * one in full (the home card's "Read").
 */
class Announcements extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $slug = 'announcements';

    protected static ?int $navigationSort = 10;

    protected string $view = 'employees::filament.pages.announcements';

    #[Url(as: 'announcement')]
    public ?int $focusedId = null;

    public static function canAccess(): bool
    {
        $user = CurrentCompany::user();

        return $user instanceof User && CurrentCompany::id($user) !== null;
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Dashboard;
    }

    public static function getNavigationLabel(): string
    {
        return 'Announcements';
    }

    public function getTitle(): string
    {
        return 'Announcements';
    }

    /** The announcement opened in full, when the viewer may see it. */
    public function focusedAnnouncement(): ?Announcement
    {
        if (! $this->focusedId) {
            return null;
        }

        return $this->service()->withReadState($this->visibleQuery(), $this->user())
            ->whereKey($this->focusedId)
            ->first();
    }

    public function markRead(int $announcementId): void
    {
        $this->record($announcementId, acknowledge: false);
    }

    public function acknowledge(int $announcementId): void
    {
        $this->record($announcementId, acknowledge: true);
    }

    public function closeFocused(): void
    {
        $this->focusedId = null;
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('New announcement')
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => $this->canManage())
                ->modalWidth('3xl')
                ->schema(fn (): array => $this->formSchema())
                ->fillForm(['audience' => Announcement::AudienceAll, 'is_pinned' => false, 'requires_acknowledgement' => false])
                ->action(function (array $data): void {
                    $this->guard(fn () => $this->service()->create($this->user(), $data), 'Announcement posted');
                }),
            Action::make('birthday_privacy')
                ->label(fn (): string => $this->ownEmployee()?->show_birthday === false ? 'Show my birthday' : 'Hide my birthday')
                ->icon('heroicon-o-cake')
                ->color('gray')
                ->visible(fn (): bool => $this->ownEmployee() !== null)
                ->requiresConfirmation()
                ->modalHeading('Birthday on the home page')
                ->modalDescription(fn (): string => $this->ownEmployee()?->show_birthday === false
                    ? 'Colleagues will see your birthday (day and month only, never the year) in the "This week" card.'
                    : 'Your birthday will no longer appear in the "This week" card. Your work anniversary still does.')
                ->action(function (): void {
                    $show = $this->ownEmployee()?->show_birthday === false;
                    $this->guard(fn () => app(CelebrationService::class)->setOwnBirthdayVisibility($this->user(), $show), $show ? 'Your birthday will be shown' : 'Your birthday is hidden');
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $manager = $this->canManage();

        return $table
            ->query(fn (): Builder => $this->service()->withReadState($manager ? $this->companyQuery() : $this->visibleQuery(), $this->user())
                ->with('departments:id,name')
                ->when($manager, fn (Builder $query): Builder => $query->withCount([
                    'reads as read_count'         => fn (Builder $reads) => $reads->whereNotNull('read_at'),
                    'reads as acknowledged_count' => fn (Builder $reads) => $reads->whereNotNull('acknowledged_at'),
                ])))
            ->columns([
                IconColumn::make('is_pinned')
                    ->label('')
                    ->icon(fn (bool $state): ?string => $state ? 'heroicon-s-bookmark' : null)
                    ->color('warning')
                    ->width('1%'),
                TextColumn::make('title')
                    ->label('Announcement')
                    ->weight('medium')
                    ->wrap()
                    ->searchable()
                    ->description(fn (Announcement $record): string => $record->excerpt(140)),
                TextColumn::make('published')
                    ->label('Published')
                    ->state(fn (Announcement $record): ?string => ($record->publish_at ?? $record->created_at)?->timezone(ChecklistKinds::timezone())->format('d M Y'))
                    ->description(fn (Announcement $record): ?string => $record->expires_at ? 'Until '.$record->expires_at->timezone(ChecklistKinds::timezone())->format('d M Y H:i') : null),
                TextColumn::make('my_state')
                    ->label('You')
                    ->badge()
                    ->state(fn (Announcement $record): string => match (true) {
                        $record->requires_acknowledgement && $record->my_acknowledged_at !== null => 'Acknowledged',
                        $record->requires_acknowledgement                                         => 'Please acknowledge',
                        $record->my_read_at !== null                                              => 'Read',
                        default                                                                   => 'Unread',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Acknowledged', 'Read' => 'success',
                        'Please acknowledge'   => 'danger',
                        default                => 'warning',
                    }),
                TextColumn::make('audience')
                    ->label('Audience')
                    ->visible($manager)
                    ->state(fn (Announcement $record): string => $record->audience === Announcement::AudienceAll
                        ? 'Everyone'
                        : $record->departments->pluck('name')->implode(', ')),
                TextColumn::make('reach')
                    ->label('Read / acknowledged')
                    ->visible($manager)
                    ->state(fn (Announcement $record): string => $record->read_count.' of '.$this->service()->audienceCount($record).' read'
                        .($record->requires_acknowledgement ? ', '.$record->acknowledged_count.' acknowledged' : '')),
                TextColumn::make('state')
                    ->label('State')
                    ->visible($manager)
                    ->badge()
                    ->state(fn (Announcement $record): string => $record->stateLabel())
                    ->color(fn (string $state): string => match ($state) {
                        'Live'      => 'success',
                        'Scheduled' => 'info',
                        default     => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('Show')
                    ->visible($manager)
                    ->options(['live' => 'Live', 'scheduled' => 'Scheduled', 'expired' => 'Expired'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'live'      => $query->where(fn (Builder $q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', now()))
                            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())),
                        'scheduled' => $query->where('publish_at', '>', now()),
                        'expired'   => $query->where('expires_at', '<=', now()),
                        default     => $query,
                    }),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('is_pinned')->orderByRaw('COALESCE(employees_announcements.publish_at, employees_announcements.created_at) DESC'))
            ->recordActions([
                Action::make('open')
                    ->label('Read')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->action(fn (Announcement $record) => $this->focusedId = (int) $record->getKey()),
                Action::make('mark_read')
                    ->label('Mark as read')
                    ->icon('heroicon-o-check')
                    ->visible(fn (Announcement $record): bool => ! $record->requires_acknowledgement && $record->my_read_at === null && $record->isLive())
                    ->action(fn (Announcement $record) => $this->markRead((int) $record->getKey())),
                Action::make('acknowledge')
                    ->label('Acknowledge')
                    ->icon('heroicon-o-hand-thumb-up')
                    ->color('primary')
                    ->visible(fn (Announcement $record): bool => $record->requires_acknowledgement && $record->my_acknowledged_at === null && $record->isLive())
                    ->action(fn (Announcement $record) => $this->acknowledge((int) $record->getKey())),
                Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray')
                    ->visible(fn (Announcement $record): bool => $this->canManage((int) $record->company_id))
                    ->modalWidth('3xl')
                    ->schema(fn (): array => $this->formSchema())
                    ->fillForm(fn (Announcement $record): array => [
                        'title'                    => $record->title,
                        'body'                     => $record->body,
                        'audience'                 => $record->audience,
                        'department_ids'           => $record->departments->pluck('id')->all(),
                        'is_pinned'                => $record->is_pinned,
                        'requires_acknowledgement' => $record->requires_acknowledgement,
                        'publish_at'               => $record->publish_at,
                        'expires_at'               => $record->expires_at,
                    ])
                    ->action(function (Announcement $record, array $data): void {
                        $this->guard(fn () => $this->service()->update($record, $this->user(), $data), 'Announcement saved');
                    }),
                Action::make('delete')
                    ->label('Remove')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (Announcement $record): bool => $this->canManage((int) $record->company_id))
                    ->requiresConfirmation()
                    ->action(function (Announcement $record): void {
                        $this->guard(fn () => $this->service()->delete($record, $this->user()), 'Announcement removed');
                    }),
            ])
            ->paginated([10, 25, 50])
            ->emptyStateIcon('heroicon-o-megaphone')
            ->emptyStateHeading('No announcements')
            ->emptyStateDescription($manager ? 'Post one with "New announcement".' : 'Company news from HR will appear here.');
    }

    /** @return array<int, mixed> */
    protected function formSchema(): array
    {
        $companyId = (int) CurrentCompany::id();

        return [
            TextInput::make('title')->required()->maxLength(255),
            RichEditor::make('body')
                ->label('Message')
                ->toolbarButtons([['bold', 'italic', 'underline', 'link'], ['h2', 'h3'], ['bulletList', 'orderedList', 'blockquote'], ['undo', 'redo']]),
            Grid::make(2)->schema([
                Select::make('audience')
                    ->options(Announcement::audiences())
                    ->required()
                    ->live(),
                Select::make('department_ids')
                    ->label('Departments')
                    ->helperText('Sub-departments are included.')
                    ->multiple()
                    ->options(fn (): array => Department::query()->where('company_id', $companyId)->orderBy('name')->pluck('name', 'id')->all())
                    ->visible(fn (Get $get): bool => $get('audience') === Announcement::AudienceDepartments)
                    ->required(fn (Get $get): bool => $get('audience') === Announcement::AudienceDepartments),
                DateTimePicker::make('publish_at')
                    ->label('Publish at')
                    ->helperText('Empty: now.')
                    ->seconds(false)
                    ->timezone(ChecklistKinds::timezone()),
                DateTimePicker::make('expires_at')
                    ->label('Expires at')
                    ->helperText('Empty: stays until removed.')
                    ->seconds(false)
                    ->timezone(ChecklistKinds::timezone()),
                Toggle::make('is_pinned')->label('Pin to the top'),
                Toggle::make('requires_acknowledgement')->label('Ask everyone to acknowledge'),
            ]),
        ];
    }

    protected function record(int $announcementId, bool $acknowledge): void
    {
        $announcement = Announcement::query()->find($announcementId);

        if (! $announcement instanceof Announcement) {
            return;
        }

        $this->guard(
            fn () => $acknowledge ? $this->service()->acknowledge($announcement, $this->user()) : $this->service()->markRead($announcement, $this->user()),
            $acknowledge ? 'Thanks for acknowledging' : 'Marked as read',
        );
    }

    /** @return Builder<Announcement> */
    protected function visibleQuery(): Builder
    {
        return $this->service()->visibleQuery($this->user(), (int) CurrentCompany::id(), $this->ownEmployee());
    }

    /** @return Builder<Announcement> */
    protected function companyQuery(): Builder
    {
        return Announcement::query()->where('employees_announcements.company_id', (int) CurrentCompany::id());
    }

    protected function canManage(?int $companyId = null): bool
    {
        return $this->service()->canManage($this->user(), $companyId);
    }

    protected function ownEmployee(): ?Employee
    {
        return Employee::query()
            ->where('company_id', CurrentCompany::id())
            ->where('user_id', $this->user()->getKey())
            ->first(['id', 'company_id', 'department_id', 'show_birthday']);
    }

    protected function guard(callable $callback, string $success): void
    {
        try {
            $callback();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Not saved')->body(collect($e->errors())->flatten()->first())->send();

            return;
        } catch (AuthorizationException $e) {
            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($success)->send();
    }

    protected function service(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = CurrentCompany::user();

        return $user;
    }
}
