<?php

namespace Webkul\Employee\Services;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeDocument;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

/**
 * "Export to Excel" of the Employees directory. Whatever query the caller
 * passes (the list with its filters), rows are cut down again to the
 * user's member companies and HR scope, and the sensitive columns (CNIC,
 * NTN, bank account, salary) are only written for users with
 * hr_view_sensitive_employee_data.
 */
class EmployeeDirectoryExport
{
    public function __construct(
        protected HrHierarchyService $hierarchy,
    ) {}

    /** @return array<string, array{label: string, sensitive: bool, value: Closure(Employee): mixed}> */
    public function definitions(): array
    {
        $date = fn (?\DateTimeInterface $value): ?string => $value?->format('Y-m-d');

        return [
            'employee_number'    => ['label' => 'Employee code', 'sensitive' => false, 'value' => fn (Employee $e) => $e->employee_number],
            'name'               => ['label' => 'Name', 'sensitive' => false, 'value' => fn (Employee $e) => $e->name],
            'company'            => ['label' => 'Company', 'sensitive' => false, 'value' => fn (Employee $e) => $e->company?->name],
            'department'         => ['label' => 'Department', 'sensitive' => false, 'value' => fn (Employee $e) => $e->department?->complete_name ?? $e->department?->name],
            'job'                => ['label' => 'Job position', 'sensitive' => false, 'value' => fn (Employee $e) => $e->job?->name],
            'job_title'          => ['label' => 'Job title', 'sensitive' => false, 'value' => fn (Employee $e) => $e->job_title],
            'manager'            => ['label' => 'Manager', 'sensitive' => false, 'value' => fn (Employee $e) => $e->parent?->name],
            'work_location'      => ['label' => 'Work location', 'sensitive' => false, 'value' => fn (Employee $e) => $e->workLocation?->name],
            'work_email'         => ['label' => 'Work email', 'sensitive' => false, 'value' => fn (Employee $e) => $e->work_email],
            'work_phone'         => ['label' => 'Work phone', 'sensitive' => false, 'value' => fn (Employee $e) => $e->work_phone],
            'mobile_phone'       => ['label' => 'Mobile', 'sensitive' => false, 'value' => fn (Employee $e) => $e->mobile_phone],
            'employment_status'  => ['label' => 'Status', 'sensitive' => false, 'value' => fn (Employee $e) => $e->employment_status],
            'joining_date'       => ['label' => 'Date of joining', 'sensitive' => false, 'value' => fn (Employee $e) => $date($e->joining_date)],
            'probation_end_date' => ['label' => 'Probation end', 'sensitive' => false, 'value' => fn (Employee $e) => $date($e->probation_end_date)],
            'confirmation_date'  => ['label' => 'Confirmation date', 'sensitive' => false, 'value' => fn (Employee $e) => $date($e->confirmation_date)],
            'eobi_number'        => ['label' => 'EOBI no.', 'sensitive' => false, 'value' => fn (Employee $e) => $e->eobi_number],
            'blood_group'        => ['label' => 'Blood group', 'sensitive' => false, 'value' => fn (Employee $e) => $e->blood_group],
            'emergency_contact'  => ['label' => 'Emergency contact', 'sensitive' => false, 'value' => fn (Employee $e) => $e->emergency_contact],
            'emergency_phone'    => ['label' => 'Emergency phone', 'sensitive' => false, 'value' => fn (Employee $e) => $e->emergency_phone],
            'emergency_relation' => ['label' => 'Emergency relation', 'sensitive' => false, 'value' => fn (Employee $e) => $e->emergency_relationship],
            'cnic'               => ['label' => 'CNIC', 'sensitive' => true, 'value' => fn (Employee $e) => $e->cnic],
            'ntn'                => ['label' => 'NTN', 'sensitive' => true, 'value' => fn (Employee $e) => $e->ntn],
            'bank_account'       => ['label' => 'Bank account', 'sensitive' => true, 'value' => fn (Employee $e) => $e->bankAccount?->account_number],
            'base_salary'        => ['label' => 'Base salary', 'sensitive' => true, 'value' => fn (Employee $e) => $e->base_salary !== null ? (float) $e->base_salary : null],
            'salary_currency'    => ['label' => 'Salary currency', 'sensitive' => true, 'value' => fn (Employee $e) => $e->salaryCurrency?->name],
        ];
    }

    public function canSeeSensitive(User $user): bool
    {
        return $user->can(HrPermissions::ViewSensitiveEmployeeData);
    }

    /** Column key => label the user may pick. @return array<string, string> */
    public function columnOptions(User $user): array
    {
        return collect($this->definitions())
            ->filter(fn (array $column): bool => ! $column['sensitive'] || $this->canSeeSensitive($user))
            ->map(fn (array $column): string => $column['label'])
            ->all();
    }

    /** @return array<int, string> */
    public function defaultColumns(): array
    {
        return ['employee_number', 'name', 'department', 'job', 'manager', 'work_location', 'work_email', 'mobile_phone', 'employment_status', 'joining_date'];
    }

    /**
     * Rows of the export: header + one row per employee.
     *
     * @param  array<int, string>  $columns
     * @return array<int, array<int, mixed>>
     */
    public function rows(User $user, Builder $query, array $columns): array
    {
        $definitions = $this->definitions();
        $allowed = array_keys($this->columnOptions($user));
        $columns = array_values(array_filter($columns, fn (string $column): bool => in_array($column, $allowed, true))) ?: $this->defaultColumns();

        $rows = [array_map(fn (string $column): string => $definitions[$column]['label'], $columns)];

        foreach ($this->employees($user, $query) as $employee) {
            $rows[] = array_map(fn (string $column): mixed => ($definitions[$column]['value'])($employee), $columns);
        }

        return $rows;
    }

    /** @return Collection<int, Employee> */
    public function employees(User $user, Builder $query): Collection
    {
        $visible = $this->hierarchy->visibleEmployeeIdsAcrossMemberships($user);
        $model = $query->getModel();

        return $query->clone()
            ->whereIn($model->qualifyColumn('company_id'), CurrentCompany::membershipIds($user) ?: [0])
            ->whereIn($model->qualifyColumn('id'), $visible->all() ?: [0])
            ->with(['company', 'department', 'job', 'parent', 'workLocation', 'bankAccount', 'salaryCurrency'])
            ->reorder()
            ->orderBy($model->qualifyColumn('name'))
            ->get();
    }

    /** @param  array<int, string>  $columns */
    public function spreadsheet(User $user, Builder $query, array $columns): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Employees');

        $rows = $this->rows($user, $query, $columns);

        foreach ($rows as $rowIndex => $row) {
            foreach (array_values($row) as $columnIndex => $value) {
                $cell = $sheet->getCell([$columnIndex + 1, $rowIndex + 1]);

                // Codes, CNICs and phone numbers stay text (no lost leading zeros).
                is_float($value) || is_int($value)
                    ? $cell->setValue($value)
                    : $cell->setValueExplicit($value === null ? '' : (string) $value, DataType::TYPE_STRING);
            }
        }

        $lastColumn = $sheet->getHighestColumn();
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());

        foreach (range(1, count($rows[0])) as $index) {
            $sheet->getColumnDimensionByColumn($index)->setAutoSize(true);
        }

        return $spreadsheet;
    }

    /** @param  array<int, string>  $columns */
    public function download(User $user, Builder $query, array $columns): StreamedResponse
    {
        $spreadsheet = $this->spreadsheet($user, $query, $columns);

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 'Employees-'.now(EmployeeDocument::timezone())->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
