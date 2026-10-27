<?php

namespace Webkul\Recruitment\Filament\Clusters\Configurations\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use RuntimeException;
use Webkul\Recruitment\Filament\Clusters\Configurations;
use Webkul\Recruitment\Models\IntakeFailure;
use Webkul\Recruitment\Models\WebsiteConnection;
use Webkul\Recruitment\Services\Website\WebsiteConnectionService;
use Webkul\Security\Services\CurrentCompany;

/**
 * Recruitment → Configurations → Careers website: the active company's
 * WordPress site (automation plan §6 A3). The Application Password is
 * write-only: it is never sent back to the browser, and an empty field keeps
 * the stored one. Needs hr_manage_recruitment_integrations, checked again on
 * every action and in WebsiteConnectionService.
 */
class CareersWebsite extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'recruitments::filament.clusters.configurations.pages.careers-website';

    protected static ?string $cluster = Configurations::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'Careers website';

    protected static ?string $title = 'Careers website';

    protected static ?string $slug = 'careers-website';

    protected static ?int $navigationSort = 1;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** The company the page was opened for: a save never lands in another one. */
    #[Locked]
    public ?int $companyId = null;

    protected ?WebsiteConnection $loadedConnection = null;

    protected bool $connectionLoaded = false;

    public static function getNavigationGroup(): ?string
    {
        return 'Website';
    }

    public static function canAccess(): bool
    {
        return WebsiteConnectionService::canManage(CurrentCompany::user(), CurrentCompany::id());
    }

    public function mount(): void
    {
        $this->companyId = CurrentCompany::id();
        $this->fillFromConnection();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('WordPress site')
                ->description('The company website with the "Aureus Careers" plugin. Aureus only calls the website (HTTPS, outbound): it lists published jobs there and fetches the applications every 5 minutes.')
                ->columns(2)
                ->schema([
                    TextInput::make('base_url')
                        ->label('Website address')
                        ->placeholder('https://www.example.com')
                        ->required()
                        ->url()
                        ->startsWith(['https://'])
                        ->maxLength(255)
                        ->helperText('HTTPS only. The address the site really answers on (with or without www).')
                        ->columnSpanFull(),
                    TextInput::make('username')
                        ->label('WordPress user')
                        ->required()
                        ->maxLength(255)
                        ->helperText('A dedicated user for Aureus, e.g. aureus-integration.'),
                    TextInput::make('app_password')
                        ->label('Application password')
                        ->password()
                        ->autocomplete('new-password')
                        ->maxLength(255)
                        ->required(fn (): bool => $this->connection() === null)
                        ->helperText(fn (): string => $this->connection()
                            ? 'Saved (encrypted). Leave empty to keep it; type a new one to replace it.'
                            : 'Create it in WordPress: Users → that user → Application Passwords. Stored encrypted.'),
                    Toggle::make('is_active')
                        ->label('Use this website')
                        ->helperText('On: jobs with the "Company website" channel go on the site (with Google for Jobs and the Indeed feed), apply links point at the site, and applications are fetched. Off: nothing is sent or fetched.')
                        ->columnSpanFull(),
                ]),
            Section::make('Status')
                ->columns(2)
                ->visible(fn (): bool => $this->connection() !== null)
                ->schema([
                    TextEntry::make('status_checked')
                        ->label('Last connection test')
                        ->state(fn (): string => $this->connection()?->last_checked_at?->format('d M Y H:i') ?? 'Not tested yet'),
                    TextEntry::make('status_error')
                        ->label('Connection test result')
                        ->state(fn (): string => $this->connection()?->last_checked_at
                            ? ($this->connection()->last_error ?? 'OK')
                            : '—')
                        ->color(fn (): string => filled($this->connection()?->last_error) ? 'danger' : 'success'),
                    TextEntry::make('status_pulled')
                        ->label('Applications last fetched')
                        ->state(fn (): string => $this->connection()?->last_pulled_at?->format('d M Y H:i') ?? 'Never'),
                    TextEntry::make('status_pull_error')
                        ->label('Last fetch problem')
                        ->state(fn (): string => $this->connection()?->last_pull_error ?? '—')
                        ->color(fn (): ?string => filled($this->connection()?->last_pull_error) ? 'danger' : null),
                    TextEntry::make('status_failures')
                        ->label('Applications waiting on the Website intake page')
                        ->state(fn (): int => IntakeFailure::query()->forCompany((int) $this->companyId)->open()->count())
                        ->url(fn (): string => WebsiteIntake::getUrl()),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->authorize(fn (): bool => static::canAccess())
                ->action(function (): void {
                    if ($this->persist()) {
                        Notification::make()->success()->title('Careers website saved')->send();
                    }
                }),
            Action::make('testConnection')
                ->label('Test connection')
                ->icon('heroicon-o-signal')
                ->color('gray')
                ->tooltip('Saves the settings, then calls the website\'s ping.')
                ->authorize(fn (): bool => static::canAccess())
                ->action(function (): void {
                    $connection = $this->persist();

                    if (! $connection) {
                        return;
                    }

                    $outcome = app(WebsiteConnectionService::class)->test(CurrentCompany::user(), $connection);
                    $this->fillFromConnection();

                    $outcome['ok']
                        ? Notification::make()->success()->title('Connection works')->body(e($outcome['message']))->send()
                        : Notification::make()->danger()->title('Connection failed')->body(e($outcome['message']))->persistent()->send();
                }),
        ];
    }

    /** Validates and saves the form for the active company; null (with a message) when refused. */
    protected function persist(): ?WebsiteConnection
    {
        if ($this->companyId === null || $this->companyId !== CurrentCompany::id()) {
            Notification::make()->danger()->title('The active company changed')->body('Reload the page to edit the careers website of the company you are working in now.')->send();

            return null;
        }

        $state = $this->form->getState();

        try {
            $connection = app(WebsiteConnectionService::class)->save(CurrentCompany::user(), $this->companyId, $state);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn (array $messages, string $key): array => ["data.{$key}" => $messages])->all());
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Could not save the careers website')->body(e($e->getMessage()))->send();

            return null;
        }

        $this->fillFromConnection();

        return $connection;
    }

    /** The active company's connection, loaded once per request. */
    protected function connection(): ?WebsiteConnection
    {
        if (! $this->connectionLoaded) {
            $companyId = $this->companyId ?? CurrentCompany::id();
            $this->loadedConnection = $companyId ? WebsiteConnection::query()->forCompany($companyId)->first() : null;
            $this->connectionLoaded = true;
        }

        return $this->loadedConnection;
    }

    protected function fillFromConnection(): void
    {
        $this->connectionLoaded = false;
        $connection = $this->connection();

        $this->form->fill([
            'base_url'     => $connection?->base_url,
            'username'     => $connection?->username ?? 'aureus-integration',
            'app_password' => null,
            'is_active'    => $connection?->is_active ?? false,
        ]);
    }
}
