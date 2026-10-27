<?php

namespace Webkul\Recruitment\Filament\Clusters\Applications\Resources;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Webkul\Chatter\Filament\Actions\ActivityTableAction;
use Webkul\Recruitment\Filament\Clusters\Applications;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\CandidateResource\Pages\CreateCandidate;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\CandidateResource\Pages\EditCandidate;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\CandidateResource\Pages\ListCandidates;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\CandidateResource\Pages\ManageSkill;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\CandidateResource\Pages\ViewCandidate;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\CandidateResource\RelationManagers\SkillsRelationManager;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Support\RecruitmentCompanyScope;
use Webkul\Security\Filament\Concerns\ScopedToMemberCompanies;
use Webkul\Security\Services\CurrentCompany;

class CandidateResource extends Resource
{
    use ScopedToMemberCompanies;

    protected static ?string $model = Candidate::class;

    /** CVs live on the private disk and are only handed out through downloadCvAction(). */
    public const CV_DISK = 'local';

    public const CV_MAX_KB = 5120;

    /**
     * "Download CV" for the candidate pages. Record access is already limited
     * to the user's company by getEloquentQuery(); the policy check makes the
     * download follow the same view permission as the record itself.
     */
    public static function downloadCvAction(): Action
    {
        return Action::make('downloadCv')
            ->label('Download CV')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (Candidate $record): bool => filled($record->resume_path)
                && (bool) Auth::user()?->can('view', $record))
            ->action(function (Candidate $record) {
                abort_unless(Auth::user()?->can('view', $record), 403);

                $disk = Storage::disk(self::CV_DISK);
                if (! $disk->exists($record->resume_path)) {
                    Notification::make()->danger()->title('The CV file could not be found.')->send();

                    return null;
                }

                $extension = pathinfo($record->resume_path, PATHINFO_EXTENSION);

                return $disk->download($record->resume_path, Str::slug($record->name ?: 'candidate').'-cv'.($extension ? '.'.$extension : ''));
            });
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $cluster = Applications::class;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('recruitments::filament/clusters/applications/resources/candidate.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('recruitments::filament/clusters/applications/resources/candidate.navigation.title');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'name',
            'email_from',
            'phone',
            'company.name',
            'degree.name',
        ];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            __('recruitments::filament/clusters/applications/resources/candidate.global-search.email-from') => $record?->email_from ?? '—',
            __('recruitments::filament/clusters/applications/resources/candidate.global-search.phone')      => $record?->phone ?? '—',
            __('recruitments::filament/clusters/applications/resources/candidate.global-search.company')    => $record?->company->name ?? '—',
            __('recruitments::filament/clusters/applications/resources/candidate.global-search.degree')     => $record?->degree->name ?? '—',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Group::make()
                    ->schema([
                        Section::make(__('recruitments::filament/clusters/applications/resources/candidate.form.sections.basic-information.title'))
                            ->schema([
                                TextInput::make('name')
                                    ->label(__('recruitments::filament/clusters/applications/resources/candidate.form.sections.basic-information.fields.full-name'))
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('email_from')
                                    ->label(__('recruitments::filament/clusters/applications/resources/candidate.form.sections.basic-information.fields.email'))
                                    ->email()
                                    ->live()
                                    ->maxLength(255),
                                TextInput::make('phone')
                                    ->label(__('recruitments::filament/clusters/applications/resources/candidate.form.sections.basic-information.fields.phone'))
                                    ->tel()
                                    ->maxLength(255),
                                TextInput::make('linkedin_profile')
                                    ->label(__('recruitments::filament/clusters/applications/resources/candidate.form.sections.basic-information.fields.linkedin'))
                                    ->url()
                                    ->maxLength(255),
                                TextInput::make('portfolio_url')
                                    ->label('Portfolio URL')
                                    ->url()
                                    ->maxLength(255),
                                TextInput::make('source_reference')
                                    ->label('Source reference')
                                    ->maxLength(255),
                                FileUpload::make('resume_path')
                                    ->label('CV / résumé')
                                    ->acceptedFileTypes(['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
                                    // Private, non web-served disk. The default disk here is "public"
                                    // (served at /storage), where visibility('private') protects nothing.
                                    ->disk(self::CV_DISK)
                                    ->directory('recruitment/cv')
                                    ->visibility('private')
                                    ->maxSize(self::CV_MAX_KB)
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                        Section::make(__('recruitments::filament/clusters/applications/resources/candidate.form.sections.additional-details.title'))
                            ->schema([
                                Select::make('degree_id')
                                    ->relationship('degree', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->label(__('recruitments::filament/clusters/applications/resources/candidate.form.sections.additional-details.fields.degree')),
                                Select::make('recruitments_candidate_categories')
                                    ->multiple()
                                    ->relationship('categories', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->label(__('recruitments::filament/clusters/applications/resources/candidate.form.sections.additional-details.fields.tags')),
                                Select::make('manager_id')
                                    ->relationship('manager', 'name', modifyQueryUsing: fn (Builder $query): Builder => RecruitmentCompanyScope::companyUsers($query))
                                    ->searchable()
                                    ->preload()
                                    ->label(__('recruitments::filament/clusters/applications/resources/candidate.form.sections.additional-details.fields.manager')),
                                DatePicker::make('availability_date')
                                    ->native(false)
                                    ->label(__('recruitments::filament/clusters/applications/resources/candidate.form.sections.additional-details.fields.availability-date')),
                            ])
                            ->columns(2),
                    ])
                    ->columnSpan(['lg' => 2]),
                Group::make()
                    ->schema([
                        Section::make(__('recruitments::filament/clusters/applications/resources/candidate.form.sections.status-and-evaluation.title'))
                            ->schema([
                                Toggle::make('is_active')
                                    ->label(__('Status'))
                                    ->inline(false)
                                    ->default(true),
                                TextEntry::make('evaluation')
                                    ->label(__('recruitments::filament/clusters/applications/resources/candidate.form.sections.status-and-evaluation.fields.evaluation'))
                                    ->state(function ($record) {
                                        $html = '<div class="flex gap-1" style="color: rgb(217 119 6);">';

                                        for ($i = 1; $i <= 3; $i++) {
                                            $iconType = $i <= $record?->priority ? 'heroicon-s-star' : 'heroicon-o-star';
                                            $html .= view('filament::components.icon', [
                                                'icon'  => $iconType,
                                                'class' => 'w-5 h-5',
                                            ])->render();
                                        }

                                        $html .= '</div>';

                                        return new HtmlString($html);
                                    }),
                            ]),
                    ])
                    ->columnSpan(['lg' => 1]),
            ])
            ->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Stack::make([
                    Stack::make([
                        TextColumn::make('name')
                            ->weight(FontWeight::Bold)
                            ->label(__('recruitments::filament/clusters/applications/resources/candidate.table.columns.name'))
                            ->searchable()
                            ->sortable(),
                        Stack::make([
                            TextColumn::make('categories.name')
                                ->label(__('recruitments::filament/clusters/applications/resources/candidate.table.columns.tags'))
                                ->badge()
                                ->searchable()
                                ->weight(FontWeight::Bold)
                                ->wrap()
                                ->state(function (Candidate $record): array {
                                    return $record->categories->map(fn ($category) => [
                                        'label' => $category->name,
                                        'color' => $category->color ?? '#808080',
                                    ])->toArray();
                                })
                                ->formatStateUsing(fn ($state) => $state['label'])
                                ->color(fn ($state) => Color::generateV3Palette($state['color'])),
                            TextColumn::make('priority')
                                ->label(__('recruitments::filament/clusters/applications/resources/candidate.table.columns.evaluation'))
                                ->color('warning')
                                ->formatStateUsing(function ($state) {
                                    $html = '<div class="flex gap-1" style="margin-top: 6px;">';
                                    for ($i = 1; $i <= 3; $i++) {
                                        $iconType = $i <= $state ? 'heroicon-s-star' : 'heroicon-o-star';
                                        $html .= view('filament::components.icon', [
                                            'icon'  => $iconType,
                                            'class' => 'w-5 h-5',
                                        ])->render();
                                    }
                                    $html .= '</div>';

                                    return new HtmlString($html);
                                }),
                        ])
                            ->visible(fn ($record) => filled($record?->categories?->count())),
                    ])->space(1),
                ])
                    ->space(4),
            ])
            ->contentGrid([
                'md' => 2,
                'xl' => 3,
            ])
            ->filtersFormColumns(2)
            ->filters([
                static::companyFilter(),
                QueryBuilder::make()
                    ->constraintPickerColumns(1)
                    ->constraints([
                        RelationshipConstraint::make('company')
                            ->label(__('recruitments::filament/clusters/applications/resources/candidate.table.filters.company'))
                            ->icon('heroicon-o-building-office-2')
                            ->multiple()
                            ->selectable(
                                IsRelatedToOperator::make()
                                    ->titleAttribute('name')
                                    ->modifyRelationshipQueryUsing(fn (Builder $query): Builder => $query->whereKey(CurrentCompany::membershipIds()))
                                    ->searchable()
                                    ->multiple()
                                    ->preload(),
                            ),
                        RelationshipConstraint::make('partner')
                            ->label(__('recruitments::filament/clusters/applications/resources/candidate.table.filters.partner-name'))
                            ->icon('heroicon-o-user')
                            ->multiple()
                            ->selectable(
                                IsRelatedToOperator::make()
                                    ->titleAttribute('name')
                                    ->searchable()
                                    ->multiple()
                                    ->preload(),
                            ),
                        RelationshipConstraint::make('degree')
                            ->label(__('recruitments::filament/clusters/applications/resources/candidate.table.filters.degree'))
                            ->icon('heroicon-o-academic-cap')
                            ->multiple()
                            ->selectable(
                                IsRelatedToOperator::make()
                                    ->titleAttribute('name')
                                    ->searchable()
                                    ->multiple()
                                    ->preload(),
                            ),
                        RelationshipConstraint::make('manager')
                            ->label(__('recruitments::filament/clusters/applications/resources/candidate.table.filters.manager-name'))
                            ->icon('heroicon-o-user')
                            ->multiple()
                            ->selectable(
                                IsRelatedToOperator::make()
                                    ->titleAttribute('name')
                                    ->searchable()
                                    ->multiple()
                                    ->preload(),
                            ),
                    ]),
            ])
            ->groups([
                Tables\Grouping\Group::make('manager.name')
                    ->label(__('recruitments::filament/clusters/applications/resources/candidate.table.groups.manager-name'))
                    ->collapsible(),
            ])
            ->recordActions([
                ActivityTableAction::make()
                    ->visible(fn (Candidate $record): bool => static::isActiveCompanyRecord($record)),
                ViewAction::make(),
                EditAction::make()
                    ->authorizationTooltip(static::switchCompanyTooltip()),
                DeleteAction::make()
                    ->successNotification(
                        Notification::make()
                            ->success()
                            ->title(__('recruitments::filament/clusters/applications/resources/candidate.table.actions.delete.notification.title'))
                            ->body(__('recruitments::filament/clusters/applications/resources/candidate.table.actions.delete.notification.body'))
                    ),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->successNotification(
                            Notification::make()
                                ->success()
                                ->title(__('recruitments::filament/clusters/applications/resources/candidate.table.bulk-actions.delete.notification.title'))
                                ->body(__('recruitments::filament/clusters/applications/resources/candidate.table.bulk-actions.delete.notification.body'))
                        ),
                ]),
            ])
            ->emptyStateActions([
                CreateAction::make()
                    ->icon('heroicon-o-plus-circle')
                    ->successNotification(
                        Notification::make()
                            ->success()
                            ->title(__('recruitments::filament/clusters/applications/resources/candidate.table.empty-state-actions.create.notification.title'))
                            ->body(__('recruitments::filament/clusters/applications/resources/candidate.table.empty-state-actions.create.notification.body'))
                    ),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 3])
                    ->schema([
                        Group::make()
                            ->schema([
                                Section::make(__('recruitments::filament/clusters/applications/resources/candidate.infolist.sections.basic-information.title'))
                                    ->schema([
                                        TextEntry::make('name')
                                            ->icon('heroicon-o-user')
                                            ->placeholder('—')
                                            ->label(__('recruitments::filament/clusters/applications/resources/candidate.infolist.sections.basic-information.entries.full-name')),
                                        TextEntry::make('partner.name')
                                            ->icon('heroicon-o-identification')
                                            ->placeholder('—')
                                            ->label(__('recruitments::filament/clusters/applications/resources/candidate.infolist.sections.basic-information.entries.contact')),
                                        TextEntry::make('email_from')
                                            ->icon('heroicon-o-envelope')
                                            ->placeholder('—')
                                            ->label(__('recruitments::filament/clusters/applications/resources/candidate.infolist.sections.basic-information.entries.email')),
                                        TextEntry::make('phone')
                                            ->icon('heroicon-o-phone')
                                            ->placeholder('—')
                                            ->label(__('recruitments::filament/clusters/applications/resources/candidate.infolist.sections.basic-information.entries.phone')),
                                        TextEntry::make('linkedin_profile')
                                            ->icon('heroicon-o-link')
                                            ->placeholder('—')
                                            ->url(fn ($record) => $record->linkedin_profile)
                                            ->label(__('recruitments::filament/clusters/applications/resources/candidate.infolist.sections.basic-information.entries.linkedin')),
                                    ])
                                    ->columns(2),
                                Section::make(__('recruitments::filament/clusters/applications/resources/candidate.infolist.sections.additional-details.title'))
                                    ->schema([
                                        TextEntry::make('company.name')
                                            ->icon('heroicon-o-building-office')
                                            ->placeholder('—')
                                            ->label(__('recruitments::filament/clusters/applications/resources/candidate.infolist.sections.additional-details.entries.company')),
                                        TextEntry::make('degree.name')
                                            ->icon('heroicon-o-academic-cap')
                                            ->placeholder('—')
                                            ->label(__('recruitments::filament/clusters/applications/resources/candidate.infolist.sections.additional-details.entries.degree')),
                                        TextEntry::make('categories.name')
                                            ->icon('heroicon-o-tag')
                                            ->placeholder('—')
                                            ->state(function (Candidate $record): array {
                                                return $record->categories->map(fn ($category) => [
                                                    'label' => $category->name,
                                                    'color' => $category->color ?? '#808080',
                                                ])->toArray();
                                            })
                                            ->badge()
                                            ->formatStateUsing(fn ($state) => $state['label'])
                                            ->color(fn ($state) => Color::generateV3Palette($state['color']))
                                            ->listWithLineBreaks()
                                            ->label(__('recruitments::filament/clusters/applications/resources/candidate.infolist.sections.additional-details.entries.tags')),
                                        TextEntry::make('manager.name')
                                            ->icon('heroicon-o-user-circle')
                                            ->placeholder('—')
                                            ->label(__('recruitments::filament/clusters/applications/resources/candidate.infolist.sections.additional-details.entries.manager')),
                                        TextEntry::make('availability_date')
                                            ->icon('heroicon-o-calendar')
                                            ->placeholder('—')
                                            ->date()
                                            ->label(__('recruitments::filament/clusters/applications/resources/candidate.infolist.sections.additional-details.entries.availability-date')),
                                    ])
                                    ->columns(2),
                            ])
                            ->columnSpan(2),
                        Group::make()
                            ->schema([
                                Section::make(__('recruitments::filament/clusters/applications/resources/candidate.infolist.sections.status-and-evaluation.title'))
                                    ->schema([
                                        IconEntry::make('is_active')
                                            ->boolean()
                                            ->label(__('Status')),
                                        TextEntry::make('priority')
                                            ->label(__('recruitments::filament/clusters/applications/resources/candidate.infolist.sections.status-and-evaluation.entries.evaluation'))
                                            ->formatStateUsing(function ($state) {
                                                $html = '<div class="flex gap-1" style="color: rgb(217 119 6);">';
                                                for ($i = 1; $i <= 3; $i++) {
                                                    $iconType = $i <= $state ? 'heroicon-s-star' : 'heroicon-o-star';
                                                    $html .= view('filament::components.icon', [
                                                        'icon'  => $iconType,
                                                        'class' => 'w-5 h-5',
                                                    ])->render();
                                                }

                                                $html .= '</div>';

                                                return new HtmlString($html);
                                            })
                                            ->placeholder('—'),
                                    ]),
                                Section::make(__('recruitments::filament/clusters/applications/resources/candidate.infolist.sections.communication.title'))
                                    ->schema([
                                        TextEntry::make('email_cc')
                                            ->icon('heroicon-o-envelope')
                                            ->placeholder('—')
                                            ->label(__('recruitments::filament/clusters/applications/resources/candidate.infolist.sections.communication.entries.cc-email')),
                                        IconEntry::make('message_bounced')
                                            ->boolean()
                                            ->label(__('recruitments::filament/clusters/applications/resources/candidate.infolist.sections.communication.entries.email-bounced')),
                                    ]),
                            ])
                            ->columnSpan(1),
                    ])->columnSpanFull(),
            ]);
    }

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            ViewCandidate::class,
            EditCandidate::class,
            ManageSkill::class,
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationGroup::make('Manage Skills', [
                SkillsRelationManager::class,
            ])
                ->icon('heroicon-o-bolt'),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListCandidates::route('/'),
            'create' => CreateCandidate::route('/create'),
            'edit'   => EditCandidate::route('/{record}/edit'),
            'view'   => ViewCandidate::route('/{record}'),
            'skills' => ManageSkill::route('/{record}/skills'),
        ];
    }
}
