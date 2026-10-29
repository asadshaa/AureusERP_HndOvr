<?php

namespace Webkul\Employee\Support;

use Illuminate\Support\Facades\DB;
use Webkul\Employee\Models\ChecklistTemplate;
use Webkul\Employee\Models\DocumentType;

/**
 * The onboarding and exit checklists every company starts with. HR can
 * rename, reorder, add or remove tasks in Employees -> Configurations ->
 * Checklist templates; provisioning never touches a company's templates
 * again once its default template exists.
 */
final class DefaultChecklistTemplates
{
    public const Code = 'default';

    /**
     * Task rows: [code, title, responsible role, days from the joining date / last working day, document type code, action, description].
     *
     * @return array<string, array{name: string, description: string, tasks: array<int, array{0: string, 1: string, 2: string, 3: int, 4: ?string, 5: ?string, 6: ?string}>}>
     */
    public static function rows(): array
    {
        return [
            ChecklistKinds::Onboarding => [
                'name'        => 'New joiner onboarding',
                'description' => 'Started when an employee is added with a joining date. Days count from the joining date.',
                'tasks'       => [
                    ['offer_letter', 'Offer letter signed', ChecklistKinds::RoleHr, -7, 'offer_letter', null, 'Upload the signed offer letter to the employee\'s Documents.'],
                    ['cnic_copy', 'CNIC copy collected', ChecklistKinds::RoleHr, 0, 'cnic', null, 'Upload a copy of both sides of the CNIC.'],
                    ['contract_signed', 'Employment contract signed', ChecklistKinds::RoleHr, 0, 'employment_contract', null, 'Upload the signed employment contract.'],
                    ['bank_details', 'Bank details provided', ChecklistKinds::RoleEmployee, 3, 'bank_details', null, 'Upload a cheque leaf or bank letter showing the account title and IBAN.'],
                    ['user_account', 'Create user account', ChecklistKinds::RoleItAdmin, -1, null, ChecklistKinds::ActionCreateUser, 'Create the login (Employee role) and link it on the employee\'s Settings tab.'],
                    ['line_manager', 'Assign line manager', ChecklistKinds::RoleHr, 0, null, ChecklistKinds::ActionAssignManager, 'Set the Manager field on the employee.'],
                    ['attendance_schedule', 'Add to attendance schedule', ChecklistKinds::RoleHr, 0, null, ChecklistKinds::ActionAttendanceSchedule, 'Set the working hours and the workplace for check-in.'],
                    ['equipment_issued', 'Equipment issued (laptop, SIM, ID card)', ChecklistKinds::RoleItAdmin, 0, null, null, null],
                    ['induction', 'Induction and introductions', ChecklistKinds::RoleLineManager, 2, null, null, 'Walk the new joiner through the team, tools and first tasks.'],
                    ['probation_review', 'Probation review', ChecklistKinds::RoleLineManager, 83, null, ChecklistKinds::ActionProbationReview, 'Review the probation and tell HR whether to confirm.'],
                ],
            ],
            ChecklistKinds::Offboarding => [
                'name'        => 'Employee exit',
                'description' => 'Started with "Start exit". Days count from the last working day.',
                'tasks'       => [
                    ['exit_letter', 'Resignation / termination letter recorded', ChecklistKinds::RoleHr, -30, null, null, 'Upload the letter to the employee\'s Documents.'],
                    ['handover', 'Handover to line manager', ChecklistKinds::RoleLineManager, -3, null, null, 'Work, files and passwords handed over.'],
                    ['return_equipment', 'Return equipment (laptop, SIM, ID card, keys)', ChecklistKinds::RoleItAdmin, 0, null, null, null],
                    ['clear_advances', 'Clear advances and loans', ChecklistKinds::RoleHr, -5, null, ChecklistKinds::ActionOpenAdvances, 'Settle the open loan and salary advance balances in the final pay.'],
                    ['final_settlement', 'Final attendance and leave settlement', ChecklistKinds::RoleHr, 0, null, null, 'Close the month\'s attendance and the leave balance for the final pay.'],
                    ['exit_interview', 'Exit interview', ChecklistKinds::RoleHr, -2, null, null, null],
                    ['experience_letter', 'Experience letter', ChecklistKinds::RoleHr, 0, null, ChecklistKinds::ActionExperienceLetter, 'Raise an HR letter request (Experience letter) and issue it.'],
                    ['disable_user', 'Disable user account', ChecklistKinds::RoleItAdmin, 0, null, ChecklistKinds::ActionDisableUser, 'Use "Disable user on last working day" so the login is switched off that evening.'],
                    ['remove_groups', 'Remove from teams and groups', ChecklistKinds::RoleItAdmin, 0, null, null, 'Teams in Aureus, email lists and WhatsApp groups.'],
                ],
            ],
        ];
    }

    /** Create the company's default templates that do not exist yet (idempotent). Returns the number created. */
    public static function provisionForCompany(int $companyId): int
    {
        $created = 0;
        $documentTypes = null;

        foreach (self::rows() as $type => $definition) {
            $template = DB::transaction(function () use ($companyId, $type, $definition, &$documentTypes): ?ChecklistTemplate {
                $template = ChecklistTemplate::query()->firstOrCreate(
                    ['company_id' => $companyId, 'type' => $type, 'code' => self::Code],
                    [
                        'name'        => $definition['name'],
                        'description' => $definition['description'],
                        'is_default'  => true,
                        'is_active'   => true,
                    ],
                );

                if (! $template->wasRecentlyCreated) {
                    return null;
                }

                $documentTypes ??= DocumentType::query()->where('company_id', $companyId)->pluck('id', 'code');

                foreach ($definition['tasks'] as $index => [$code, $title, $role, $offset, $documentCode, $action, $description]) {
                    $template->tasks()->create([
                        'code'             => $code,
                        'title'            => $title,
                        'description'      => $description,
                        'responsible_role' => $role,
                        'due_offset_days'  => $offset,
                        'document_type_id' => $documentCode !== null ? ($documentTypes[$documentCode] ?? null) : null,
                        'action_key'       => $action,
                        'sort'             => ($index + 1) * 10,
                    ]);
                }

                return $template;
            });

            $created += $template !== null ? 1 : 0;
        }

        return $created;
    }
}
