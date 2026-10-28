<?php

namespace Webkul\Recruitment\Filament\Clusters\Configurations\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use RuntimeException;
use Webkul\Recruitment\Filament\Clusters\Configurations;
use Webkul\Recruitment\Services\Website\JobBoardService;
use Webkul\Security\Services\CurrentCompany;

/**
 * Recruitment → Configurations → Job boards: the active company's free job
 * boards (Zoho-style "Free job boards"). Feed boards (Indeed, Jooble,
 * talent.com, recruit.net, Expertini, TipTopJob, generic) read the company
 * website's XML feed once it is registered with them; manual boards (Dr.Job,
 * Post Job Free, LinkedIn Basic, Twine) are posted with "Copy post". Same
 * permission as the Careers website page, checked again in JobBoardService.
 */
class JobBoards extends Page
{
    protected string $view = 'recruitments::filament.clusters.configurations.pages.job-boards';

    protected static ?string $cluster = Configurations::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationLabel = 'Job boards';

    protected static ?string $title = 'Free job boards';

    protected static ?string $slug = 'job-boards';

    protected static ?int $navigationSort = 3;

    /** The company the page was opened for: a save never lands in another one. */
    #[Locked]
    public ?int $companyId = null;

    public static function getNavigationGroup(): ?string
    {
        return 'Website';
    }

    public static function canAccess(): bool
    {
        return JobBoardService::canManage(CurrentCompany::user(), CurrentCompany::id());
    }

    public function mount(): void
    {
        $this->companyId = CurrentCompany::id();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'boards'     => $this->companyId ? app(JobBoardService::class)->overview($this->companyId) : [],
            'websiteUrl' => CareersWebsite::getUrl(),
        ];
    }

    public function editRegistrationAction(): Action
    {
        return Action::make('editRegistration')
            ->label('Update')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->size('sm')
            ->authorize(fn (): bool => static::canAccess())
            ->modalHeading(fn (array $arguments): string => 'Registration: '.($this->board($arguments['board'] ?? '')['label'] ?? 'job board'))
            ->fillForm(function (array $arguments): array {
                $board = $this->board($arguments['board'] ?? '');

                return [
                    'is_registered' => $board['is_registered'] ?? false,
                    'registered_at' => $board['registered_at'] ?? null,
                    'notes'         => $board['notes'] ?? null,
                ];
            })
            ->schema([
                Toggle::make('is_registered')
                    ->label('Registered with the board?')
                    ->helperText('Feed boards: the board has accepted (or is reviewing) the feed URL. Manual boards: the free employer account exists.'),
                DatePicker::make('registered_at')
                    ->label('Registered on')
                    ->native(false)
                    ->maxDate(now())
                    ->helperText('Empty = today, when switched on.'),
                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(3)
                    ->maxLength(1000)
                    ->placeholder('Account email, contact person, ticket number, the aggregator\'s name...'),
            ])
            ->action(function (array $data, array $arguments): void {
                if ($this->companyId === null || $this->companyId !== CurrentCompany::id()) {
                    Notification::make()->danger()->title('The active company changed')->body('Reload the page to edit the job boards of the company you are working in now.')->send();

                    return;
                }

                try {
                    app(JobBoardService::class)->saveRegistration(CurrentCompany::user(), $this->companyId, (string) ($arguments['board'] ?? ''), $data);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Please check the details')->body(e(collect($e->errors())->flatten()->implode(' ')))->send();

                    return;
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Could not save')->body(e($e->getMessage()))->send();

                    return;
                }

                Notification::make()->success()->title('Job board saved')->send();
            });
    }

    /** @return array<string, mixed>|null */
    protected function board(string $key): ?array
    {
        if (! $this->companyId) {
            return null;
        }

        return collect(app(JobBoardService::class)->overview($this->companyId))->firstWhere('key', $key);
    }
}
