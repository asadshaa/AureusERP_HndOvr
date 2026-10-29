<?php

namespace Webkul\Employee\Filament\Resources\PerformanceReviewResource\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Webkul\Employee\Filament\Appraisals\AppraisalTable;
use Webkul\Employee\Filament\Pages\MyAppraisals;
use Webkul\Employee\Filament\Pages\TeamAppraisals;
use Webkul\Employee\Filament\Resources\PerformanceReviewResource;
use Webkul\Employee\Models\PerformanceGoal;
use Webkul\Employee\Models\PerformanceReview;
use Webkul\Employee\Services\Appraisals\AppraisalAccess;
use Webkul\Employee\Services\Appraisals\AppraisalScoring;
use Webkul\Employee\Services\Appraisals\AppraisalSheet;
use Webkul\Employee\Services\PerformanceService;
use Webkul\Employee\Support\AppraisalKinds;
use Webkul\Security\Models\User;

/**
 * One appraisal: the employee fills in the self review here, the reviewer
 * (or HR when there is no line manager) the manager review with the self
 * review beside each item, HR calibrates, the employee acknowledges, and
 * anyone allowed downloads the PDF. Only what AppraisalAccess allows is
 * shown, and every save goes through PerformanceService, which checks again.
 */
class ViewAppraisal extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithRecord;

    protected static string $resource = PerformanceReviewResource::class;

    protected string $view = 'employees::filament.appraisals.view-appraisal';

    /** @var array<string, mixed>|null */
    public ?array $selfData = [];

    /** @var array<string, mixed>|null */
    public ?array $managerData = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless($this->access()->canView($this->user(), $this->review()), 403);

        $review = $this->review();

        if ($this->editsSelf()) {
            $this->getSchema('selfForm')->fill([
                'items'    => (array) ($review->self_answers['items'] ?? []),
                'comments' => $review->self_comments,
            ]);
        }

        if ($this->editsManager()) {
            $this->getSchema('managerForm')->fill([
                'items'                    => (array) ($review->manager_answers['items'] ?? []),
                'comments'                 => $review->manager_comments,
                'improvement_plan'         => $review->improvement_plan,
                'promotion_recommendation' => $review->promotion_recommendation,
                'goals'                    => $review->nextPeriodGoals->map(fn (PerformanceGoal $goal): array => [
                    'title'       => $goal->title,
                    'description' => $goal->description,
                    'due_date'    => $goal->due_date?->toDateString(),
                    'weight'      => (float) $goal->weight ?: null,
                ])->all(),
            ]);
        }
    }

    public function getTitle(): string
    {
        return 'Appraisal: '.$this->review()->employee?->name;
    }

    public function getSubheading(): ?string
    {
        $cycle = $this->review()->cycle;

        return trim(($cycle?->name ?? '').' · '.($cycle?->periodLabel() ?? ''), ' ·');
    }

    public function review(): PerformanceReview
    {
        /** @var PerformanceReview $review */
        $review = $this->getRecord();

        return $review;
    }

    public function editsSelf(): bool
    {
        return $this->access()->canEditSelfReview($this->user(), $this->review());
    }

    public function editsManager(): bool
    {
        return $this->access()->canEditManagerReview($this->user(), $this->review());
    }

    /** @return array<string, mixed> */
    public function sheet(): array
    {
        return app(AppraisalSheet::class)->build($this->review(), $this->user());
    }

    public function selfForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('selfData')
            ->components([
                ...$this->itemSections(withSelfHint: false),
                Section::make('Overall')
                    ->schema([
                        Textarea::make('comments')
                            ->label('Your overall comments')
                            ->placeholder('Highlights of the period, what got in the way, support you need.')
                            ->rows(4)
                            ->maxLength(5000),
                    ]),
            ]);
    }

    public function managerForm(Schema $schema): Schema
    {
        $cycle = $this->review()->cycle;

        return $schema
            ->statePath('managerData')
            ->components([
                ...$this->itemSections(withSelfHint: true),
                Section::make('Overall')
                    ->schema([
                        Textarea::make('comments')->label('Manager comments')->rows(4)->maxLength(5000),
                        Textarea::make('improvement_plan')->label('Development / improvement plan')->rows(3)->maxLength(5000),
                        Textarea::make('promotion_recommendation')
                            ->label('Promotion / increment recommendation')
                            ->helperText('For HR; not shown to the employee.')
                            ->rows(2)
                            ->maxLength(5000),
                    ]),
                Section::make('Goals for the next period')
                    ->visible(AppraisalScoring::includesGoals($cycle))
                    ->schema([
                        Repeater::make('goals')
                            ->hiddenLabel()
                            ->addActionLabel('Add goal')
                            ->defaultItems(0)
                            ->maxItems(15)
                            ->columns(6)
                            ->schema([
                                TextInput::make('title')->required()->maxLength(250)->columnSpan(3),
                                DatePicker::make('due_date')->label('Due')->native(false)->columnSpan(2),
                                TextInput::make('weight')->numeric()->minValue(0)->maxValue(100)->columnSpan(1),
                                Textarea::make('description')->label('Measure of success')->rows(1)->maxLength(2000)->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }

    public function saveSelf(bool $submit = false): void
    {
        if (! $this->editsSelf()) {
            $this->refuse('This self review is not open for you.');

            return;
        }

        $this->persist(fn () => app(PerformanceService::class)->saveSelfReview(
            $this->review(), $this->user(), $this->getSchema('selfForm')->getState(), $submit,
        ), $submit ? 'Self review submitted. Your line manager has been told.' : 'Draft saved.', $submit);
    }

    public function saveManager(bool $submit = false): void
    {
        if (! $this->editsManager()) {
            $this->refuse('This manager review is not open for you.');

            return;
        }

        $this->persist(fn () => app(PerformanceService::class)->saveManagerReview(
            $this->review(), $this->user(), $this->getSchema('managerForm')->getState(), $submit,
        ), $submit ? 'Manager review submitted.' : 'Draft saved.', $submit);
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('acknowledge')
                ->label('Acknowledge')
                ->icon('heroicon-o-hand-thumb-up')
                ->color('success')
                ->visible(fn (): bool => $this->access()->canAcknowledge($this->user(), $this->review()))
                ->modalHeading('Acknowledge your appraisal')
                ->modalDescription('You confirm you have read your appraisal. You may add a comment; it is kept with the appraisal.')
                ->schema([
                    Textarea::make('comment')->label('Your comment (optional)')->rows(3)->maxLength(5000),
                ])
                ->action(fn (array $data) => $this->persist(
                    fn () => app(PerformanceService::class)->acknowledge($this->review(), $this->user(), $data['comment'] ?? null),
                    'Thank you, your acknowledgement is recorded.',
                    true,
                )),
            Action::make('calibrate')
                ->label('Calibrate')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('warning')
                ->visible(fn (): bool => $this->access()->canCalibrate($this->user(), $this->review()))
                ->modalDescription(fn (): string => 'Manager score '.number_format((float) $this->review()->manager_rating, 2).'. The final rating and band change; the manager score is kept.')
                ->fillForm(fn (): array => ['rating' => $this->review()->final_rating, 'reason' => $this->review()->calibration_reason])
                ->schema(AppraisalTable::calibrationSchema())
                ->action(fn (array $data) => $this->persist(
                    fn () => app(PerformanceService::class)->calibrate($this->review(), $this->user(), (float) $data['rating'], (string) $data['reason']),
                    'Final rating calibrated.',
                    true,
                )),
            Action::make('pdf')
                ->label('Download PDF')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->visible(fn (): bool => $this->access()->canDownloadPdf($this->user(), $this->review()))
                ->url(fn (): string => route('employees.appraisals.pdf', ['review' => $this->review()->getKey()]))
                ->openUrlInNewTab(),
            Action::make('back')
                ->label('Back')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => $this->access()->isOwn($this->user(), $this->review())
                    ? MyAppraisals::getUrl()
                    : TeamAppraisals::getUrl()),
        ];
    }

    /**
     * One section per template section; each item a 1-5 choice and a comment.
     *
     * @return array<int, Section>
     */
    protected function itemSections(bool $withSelfHint): array
    {
        $review = $this->review();
        $scale = AppraisalScoring::scale($review->cycle);
        $options = collect($scale)->mapWithKeys(fn (string $label, int $rating): array => [$rating => "{$rating} · {$label}"])->all();
        $selfAnswers = (array) ($review->self_answers['items'] ?? []);
        $showSelf = $withSelfHint && $this->access()->canSeeSelfReview($this->user(), $review) && $review->submitted_at !== null;

        return collect(AppraisalScoring::sections($review->cycle))
            ->map(function (array $section) use ($options, $scale, $selfAnswers, $showSelf): Section {
                $components = [];

                foreach ($section['items'] as $item) {
                    $key = $item['key'];
                    $self = is_array($selfAnswers[$key] ?? null) ? $selfAnswers[$key] : [];
                    $selfRating = AppraisalScoring::rating($self['rating'] ?? null);
                    $weight = rtrim(rtrim(number_format((float) $item['weight'], 2), '0'), '.');

                    $components[] = Grid::make(['default' => 1, 'lg' => 5])->schema([
                        ToggleButtons::make("items.{$key}.rating")
                            ->label($item['title'].' ('.(AppraisalKinds::itemTypes()[$item['type']] ?? $item['type']).', weight '.$weight.')')
                            ->helperText($item['description'])
                            ->hint($showSelf ? 'Self: '.($selfRating !== null ? $selfRating.' · '.($scale[$selfRating] ?? '') : 'not rated') : null)
                            ->hintColor('info')
                            ->options($options)
                            ->inline()
                            ->columnSpan(['default' => 1, 'lg' => 3]),
                        Textarea::make("items.{$key}.comment")
                            ->label('Comment')
                            ->placeholder($showSelf && filled($self['comment'] ?? null) ? 'Employee: '.$self['comment'] : 'Optional')
                            ->rows(2)
                            ->maxLength(2000)
                            ->columnSpan(['default' => 1, 'lg' => 2]),
                    ]);
                }

                return Section::make($section['title'])
                    ->description($section['description'])
                    ->schema($components);
            })
            ->all();
    }

    /** Runs a save, tells the user, and reloads the page after a step changed. */
    protected function persist(callable $callback, string $success, bool $reload): void
    {
        try {
            $callback();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->persistent()->send();

            return;
        }

        Notification::make()->success()->title($success)->send();

        if ($reload) {
            $this->redirect(static::getUrl(['record' => $this->review()->getKey()]), navigate: false);

            return;
        }

        $this->record = $this->review()->fresh();
    }

    protected function refuse(string $message): void
    {
        Notification::make()->danger()->title('Not saved')->body($message)->send();
    }

    protected function access(): AppraisalAccess
    {
        return app(AppraisalAccess::class);
    }

    protected function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
