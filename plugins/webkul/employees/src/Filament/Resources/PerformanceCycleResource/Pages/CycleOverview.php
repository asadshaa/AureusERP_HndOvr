<?php

namespace Webkul\Employee\Filament\Resources\PerformanceCycleResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Filament\Appraisals\AppraisalTable;
use Webkul\Employee\Filament\Resources\PerformanceCycleResource;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\PerformanceCycle;
use Webkul\Employee\Services\Appraisals\AppraisalAccess;
use Webkul\Employee\Services\Appraisals\AppraisalCycleExport;
use Webkul\Employee\Services\Appraisals\AppraisalScoring;
use Webkul\Employee\Services\PerformanceService;
use Webkul\Employee\Support\AppraisalKinds;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

/**
 * HR overview of one cycle: completion progress, the distribution of final
 * ratings by band, every appraisal with calibration / reviewer / skip
 * actions, the Excel export and closing the cycle.
 */
class CycleOverview extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = PerformanceCycleResource::class;

    protected string $view = 'employees::filament.appraisals.cycle-overview';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(app(AppraisalAccess::class)->canViewCycle($this->user(), $this->cycle()), 403);
    }

    public function getTitle(): string
    {
        return $this->cycle()->name;
    }

    public function getSubheading(): ?string
    {
        $cycle = $this->cycle();

        return collect([
            $cycle->periodLabel(),
            AppraisalKinds::cycleStatuses()[$cycle->status] ?? $cycle->status,
            $cycle->self_review_due_on ? 'self reviews due '.$cycle->self_review_due_on->format('j M') : null,
            $cycle->manager_review_due_on ? 'manager reviews due '.$cycle->manager_review_due_on->format('j M') : null,
            'results shown '.mb_strtolower(AppraisalKinds::visibilityOptions()[$cycle->results_visibility] ?? ''),
        ])->filter()->implode(' · ');
    }

    public function cycle(): PerformanceCycle
    {
        /** @var PerformanceCycle $cycle */
        $cycle = $this->getRecord();

        return $cycle;
    }

    /** @return array<string, mixed> */
    public function stats(): array
    {
        return app(PerformanceService::class)->cycleStats($this->cycle());
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export to Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => app(AppraisalCycleExport::class)->download($this->user(), $this->cycle())),
            Action::make('close')
                ->label('Close & share results')
                ->icon('heroicon-o-lock-closed')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription(fn (): string => PerformanceCycleResource::closeSummary($this->cycle()))
                ->visible(fn (): bool => $this->user()->can('close', $this->cycle()))
                ->action(function (): void {
                    PerformanceCycleResource::closeCycle($this->cycle());
                    $this->record = $this->cycle()->fresh();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $cycle = $this->cycle();

        return $table
            ->query(fn (): Builder => app(AppraisalAccess::class)
                ->visibleQuery($this->user(), (int) CurrentCompany::id($this->user()))
                ->where('cycle_id', $cycle->getKey())
                ->with(['cycle', 'employee.department', 'employee.parent', 'reviewer.user', 'employee.user']))
            ->columns(AppraisalTable::columns($this->user(), withEmployee: true, withReviewer: true, withCycle: false))
            ->filters([
                SelectFilter::make('status')->options(AppraisalKinds::statuses()),
                SelectFilter::make('final_band')
                    ->label('Band')
                    ->options(collect(AppraisalScoring::bands($cycle))->pluck('label', 'label')->all()),
                SelectFilter::make('department')
                    ->options(fn (): array => Department::query()->where('company_id', $cycle->company_id)->orderBy('complete_name')->pluck('complete_name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('employee', fn (Builder $employees) => $employees->where('department_id', $data['value']))
                        : $query),
                Filter::make('no_manager')
                    ->label('No line manager (HR reviews)')
                    ->query(fn (Builder $query): Builder => app(AppraisalAccess::class)->withoutManager($query)),
                Filter::make('calibrated')
                    ->label('Calibrated')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('calibrated_at')),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('status')->orderBy('id'))
            ->recordActions(AppraisalTable::actions())
            ->paginated([25, 50, 100]);
    }

    protected function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
