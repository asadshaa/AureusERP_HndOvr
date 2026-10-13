<div class="fi-resource-relation-manager space-y-6">
    @include('employees::filament.components.employee-monthly-attendance-summary', [
        'summary'  => $this->getMonthlySummaryForView(),
        'employee' => $this->getOwnerRecord(),
    ])

    {{ $this->content }}

    <x-filament-panels::unsaved-action-changes-alert />
</div>
