<?php

namespace Webkul\TimeOff\Filament\Clusters\MyTime\Resources\MyTimeOffResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Webkul\Chatter\Filament\Actions\ChatterAction;
use Webkul\Support\Services\ApprovalEngine;
use Webkul\Support\Traits\HasRecordNavigationTabs;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Filament\Clusters\MyTime\Resources\MyTimeOffResource;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Services\LeaveApprovalService;

class ViewMyTimeOff extends ViewRecord
{
    use HasRecordNavigationTabs;

    protected static string $resource = MyTimeOffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ChatterAction::make()
                ->resource(static::$resource)
                ->activityPlans($this->getRecord()->activityPlans()),
            // The self-service "My Time off" screen had no way at all for an
            // employee to actually submit their own leave for approval --
            // only the HR/Manager-facing Management -> Time off screen had
            // this action. Mirrors that action's exact logic (same
            // LeaveApprovalService::submit() call, same eligible-state
            // check), just reachable from the employee's own record here.
            Action::make('submit_for_approval')
                ->label('Submit for approval')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->visible(function (): bool {
                    /** @var Leave $record */
                    $record = $this->getRecord();
                    $canSubmit = (int) $record->employee?->user_id === (int) Auth::id()
                        || (bool) Auth::user()?->can('hr_approve_leave');
                    $approvalStatus = $record->approvalRequest?->status;

                    return $canSubmit
                        && in_array($record->state, [State::CONFIRM, State::REFUSE], true)
                        && $approvalStatus !== 'pending';
                })
                ->action(function (): void {
                    /** @var Leave $record */
                    $record = $this->getRecord();
                    $request = app(LeaveApprovalService::class)->submit($record, Auth::user());

                    Notification::make()
                        ->success()
                        ->title('Leave submitted for approval')
                        ->body(app(ApprovalEngine::class)->describeCurrentApprover($request))
                        ->send();

                    $this->getRecord()->refresh();
                }),
            EditAction::make(),
            DeleteAction::make()
                ->successNotification(
                    Notification::make()
                        ->success()
                        ->title(__('time-off::filament/clusters/my-time/resources/my-time-off/pages/view-time-off.header-actions.delete.notification.title'))
                        ->body(__('time-off::filament/clusters/my-time/resources/my-time-off/pages/view-time-off.header-actions.delete.notification.body'))
                ),
        ];
    }
}
