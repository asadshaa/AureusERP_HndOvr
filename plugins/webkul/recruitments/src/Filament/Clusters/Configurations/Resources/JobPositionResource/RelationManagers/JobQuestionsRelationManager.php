<?php

namespace Webkul\Recruitment\Filament\Clusters\Configurations\Resources\JobPositionResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\ScreeningQuestionResource;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\JobQuestion;
use Webkul\Recruitment\Models\ScreeningQuestion;
use Webkul\Recruitment\Services\ScreeningService;
use Webkul\Security\Services\CurrentCompany;

/**
 * "Screening questions" tab on a job: the questions applicants answer for this
 * job (copied from the library or written here, each editable for this job).
 * Answers keep a snapshot, so editing a question never rewrites past answers.
 */
class JobQuestionsRelationManager extends RelationManager
{
    protected static string $relationship = 'questions';

    protected static ?string $title = 'Screening questions';

    public function isReadOnly(): bool
    {
        return ! $this->canManage();
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('view_recruitment_job::position') ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(ScreeningQuestionResource::questionFields())->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('sort', fn (): bool => $this->canManage())
            ->defaultSort('sort')
            ->emptyStateHeading('No screening questions yet')
            ->emptyStateDescription('Add questions from the library, or write your own for this job.')
            ->columns([
                TextColumn::make('label')->label('Question')->wrap(),
                TextColumn::make('type')->label('Answer type')->badge(),
                IconColumn::make('is_required')->label('Required')->boolean(),
                TextColumn::make('knockout')
                    ->label('Must-have rule')
                    ->state(fn (JobQuestion $record): string => ScreeningQuestionResource::describeRule($record->knockout))
                    ->wrap(),
                TextColumn::make('answers_count')->label('Answers')->counts('answers'),
            ])
            ->headerActions([
                Action::make('addFromLibrary')
                    ->label('Add from library')
                    ->icon('heroicon-o-book-open')
                    ->authorize(fn (): bool => $this->canManage())
                    ->schema([
                        CheckboxList::make('questions')
                            ->label('Library questions')
                            ->options(fn (): array => ScreeningQuestion::query()
                                ->activeForCompany((int) $this->job()->company_id)
                                ->pluck('label', 'id')
                                ->all())
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        try {
                            $added = app(ScreeningService::class)->addFromLibrary($this->job(), array_map('intval', $data['questions'] ?? []), Auth::user());
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Could not add the questions')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title("{$added->count()} question(s) added")->send();
                    }),
                CreateAction::make()
                    ->label('New question')
                    ->authorize(fn (): bool => $this->canManage())
                    ->mutateDataUsing(fn (array $data): array => $data + ['sort' => (int) $this->job()->questions()->max('sort') + 1]),
            ])
            ->recordActions([
                EditAction::make()->authorize(fn (): bool => $this->canManage()),
                DeleteAction::make()
                    ->authorize(fn (): bool => $this->canManage())
                    ->modalDescription('Answers already given keep the question as it was asked.'),
            ]);
    }

    /** Another member company's job is view-only (switch company to change its questions). */
    protected function canManage(): bool
    {
        return (int) $this->job()->company_id === CurrentCompany::id()
            && ScreeningService::canManageQuestions(Auth::user(), $this->job()->company_id);
    }

    protected function job(): JobPosition
    {
        /** @var JobPosition $job */
        $job = $this->getOwnerRecord();

        return $job;
    }
}
