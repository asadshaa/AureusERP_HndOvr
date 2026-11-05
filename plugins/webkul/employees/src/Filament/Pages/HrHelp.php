<?php

namespace Webkul\Employee\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Webkul\Support\Enums\NavigationGroup;

/**
 * HR help: one short how-to per HR screen (what it is for, the usual steps,
 * who approves what), written from how the screens and services behave.
 * Every signed-in user may open it; the "Help" button of each HR screen
 * (Webkul\Support\Filament\Actions\HelpAction) links to its section by
 * anchor (#my-leave, #leave-requests, ...).
 */
class HrHelp extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $slug = 'hr-help';

    protected static ?int $navigationSort = 90;

    protected string $view = 'employees::filament.pages.hr-help';

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Help;
    }

    public static function getNavigationLabel(): string
    {
        return 'HR how-to guide';
    }

    public function getTitle(): string
    {
        return 'HR how-to guide';
    }

    public function getSubheading(): ?string
    {
        return 'What each HR screen is for, how to do the usual task there, and who approves what. Every screen has a Help button that opens its section here.';
    }

    /**
     * The guide, in groups of screens.
     *
     * @return array<int, array{title: string, topics: array<int, array{id: string, title: string, purpose: string, steps: array<int, string>, approvals?: string, note?: string}>}>
     */
    public function getGroups(): array
    {
        return [
            [
                'title'  => 'For everyone',
                'topics' => [
                    [
                        'id'      => 'check-in',
                        'title'   => 'Check in and My Attendance',
                        'purpose' => 'Record when you start and finish work, and see your month: days present, late days, leave days, hours worked and your grace balance.',
                        'steps'   => [
                            'Open Check in (or My Attendance) on your phone or computer when you arrive.',
                            'Press Check in and allow the browser to use your location. You must be at your workplace.',
                            'Press Check out when you leave. Breaks between a check-out and the next check-in stay breaks.',
                            'If a time is wrong, press Request correction next to that day. If you forgot a whole day, use Request attendance for a missed day (up to 30 days back).',
                        ],
                        'approvals' => 'Corrections and missed days go to your line manager. Once approved, the attendance record changes and HR sees it in the register.',
                        'note'      => 'If check-in is refused because of your location, the message says why. Try again near the workplace or ask HR.',
                    ],
                    [
                        'id'      => 'my-leave',
                        'title'   => 'My Time Off (asking for leave)',
                        'purpose' => 'Ask for leave and follow your requests from waiting to approved.',
                        'steps'   => [
                            'Open My Time Off and press New.',
                            'Choose the leave type. Your balance for that type is shown right away.',
                            'Pick the dates (or Half day, or Short leave with from-to hours) and add a short reason.',
                            'Save, then press Submit for approval on the request. Until you submit, nobody is asked to approve it.',
                            'You get a notification when it is approved, refused or returned for changes.',
                        ],
                        'approvals' => 'Your line manager approves first, then HR. With no line manager, it goes straight to HR.',
                        'note'      => 'Leave year: 25 days = Annual 12, Sick 8, Casual 5. Only Annual Leave carries forward (up to 6 days). People who join during the year get a share for the months left. A Half day does not count as leave and nothing is taken from your balance. Short leave: at most 3 hours, twice a month. A request waiting for approval already counts against your balance; a refused, withdrawn or cancelled one gives the days back.',
                    ],
                    [
                        'id'      => 'my-allocations',
                        'title'   => 'My Allocation (your leave balance)',
                        'purpose' => 'The days you were given for each leave type this year.',
                        'steps'   => [
                            'You do not need to ask for your yearly leave: it is added automatically when you join and every 1 January.',
                            'Open a line to see the dates it covers and any days carried forward.',
                        ],
                        'approvals' => 'Extra days you ask for here need approval before you can use them.',
                    ],
                    [
                        'id'      => 'my-requests',
                        'title'   => 'Employee Requests (loans, advances, letters, overtime, claims)',
                        'purpose' => 'Ask HR for money or paperwork, and follow each request until it is decided.',
                        'steps'   => [
                            'Open Employee Requests and press Submit (or Save Draft to finish later).',
                            'Choose the type: loan, salary advance, HR letter, overtime, claim or attendance correction. The form shows only the fields that type needs.',
                            'Fill in the amount in PKR, the months, or the letter details, and attach receipts if you have them.',
                            'Submit. The list shows who has to act next in Current Approver.',
                            'If a request is returned for changes, edit it and press Resubmit. You can Withdraw a request that is still waiting.',
                        ],
                        'approvals' => 'Loans: line manager, then HR (then finance where the company has that step). Salary advance and overtime: line manager, then HR. HR letters: HR. Claims follow the company\'s claims chain.',
                        'note'      => 'An approved loan or advance is taken from salary on the months shown; HR marks them as deducted after payroll. An HR letter appears here to download once HR issues it.',
                    ],
                    [
                        'id'      => 'my-profile',
                        'title'   => 'My profile',
                        'purpose' => 'Your own details, password and preferences.',
                        'steps'   => [
                            'Open your profile from the menu under your name.',
                            'Update your contact details and save.',
                            'To change your CNIC, passport or bank account, press Request Sensitive Data Update. HR checks and approves it before it changes.',
                        ],
                    ],
                    [
                        'id'      => 'my-appraisals',
                        'title'   => 'My appraisals',
                        'purpose' => 'Your self review and, once shared, your final rating.',
                        'steps'   => [
                            'When HR starts an appraisal cycle, your appraisal appears here.',
                            'Open it, fill in your self review before the deadline and submit it.',
                            'Your manager then writes their review. You see the result when HR closes the cycle.',
                        ],
                    ],
                    [
                        'id'      => 'my-tasks',
                        'title'   => 'My tasks',
                        'purpose' => 'Onboarding and exit checklist tasks given to you.',
                        'steps'   => [
                            'Open a task to read what is needed and by when.',
                            'Mark it done when it is finished. HR follows progress on Onboarding & exits.',
                        ],
                    ],
                ],
            ],
            [
                'title'  => 'For HR and line managers',
                'topics' => [
                    [
                        'id'      => 'approvals',
                        'title'   => 'Approval Queue (everything waiting for you)',
                        'purpose' => 'One list of leave, attendance corrections, employee requests and other approvals that need your decision.',
                        'steps'   => [
                            'The menu badge counts the requests you can act on now. The oldest waiting request is at the top.',
                            'Press View to see the details, the amount and the decision history.',
                            'Press Approve (with an optional note), Reject (with a reason) or Return to requester (say what to change).',
                            'When the last step approves, the request is final and the employee is told.',
                        ],
                        'approvals' => 'A request moves step by step, usually line manager then HR. You only see Approve on a request when it is your step.',
                    ],
                    [
                        'id'      => 'employees',
                        'title'   => 'Employees',
                        'purpose' => 'The staff list: work details, private details, documents, leave, attendance and requests for each person.',
                        'steps'   => [
                            'Press New employee. Fill in name, job, department, line manager and joining date. The Employee ID is given automatically if left empty.',
                            'On Private information add CNIC (12345-1234567-1), phone and emergency contact.',
                            'On Settings link a user account so the person can log in, and set their device ID if attendance comes from a fingerprint device.',
                            'Save. Their yearly leave is added automatically, prorated from the joining date.',
                            'Later changes to CNIC, bank account or salary: use Request sensitive change on the employee. It needs approval before it is saved.',
                        ],
                        'approvals' => 'Sensitive changes (identity, bank, salary) are approved by the sensitive-data custodian.',
                    ],
                    [
                        'id'      => 'documents',
                        'title'   => 'Documents',
                        'purpose' => 'Follow up employee documents: expired, expiring soon, waiting to be checked, or missing.',
                        'steps'   => [
                            'Upload a document from the employee\'s Documents tab (type, number, issue and expiry dates, file).',
                            'Use this list and its filters to find what is expired or expiring.',
                            'HR is reminded 30 days and 7 days before a document expires, so add expiry dates when you know them.',
                        ],
                    ],
                    [
                        'id'      => 'today-attendance',
                        'title'   => 'Today\'s attendance',
                        'purpose' => 'Who is in, late, absent or on leave today, with problems at the top.',
                        'steps'   => [
                            'Open it in the morning. Change the date to look at another day.',
                            'Look at late and absent people first; leave and holidays are already taken into account.',
                            'Download (Excel) gives the same list.',
                        ],
                        'approvals' => 'HR sees the whole company; a line manager sees only their own team.',
                    ],
                    [
                        'id'      => 'attendance-register',
                        'title'   => 'Attendance Register',
                        'purpose' => 'Every check-in and check-out, from phones and devices, with the location check result.',
                        'steps'   => [
                            'Use the filters for a person, a day or Needs review.',
                            'Open a record to see its times and where it was made.',
                            'Fix wrong times through a correction request so the change is approved and kept in the history.',
                        ],
                    ],
                    [
                        'id'      => 'device-import',
                        'title'   => 'Device import (fingerprint machine files)',
                        'purpose' => 'Bring punches from a biometric device into attendance.',
                        'steps'   => [
                            'Make sure each employee has their device ID on their profile (Settings tab).',
                            'Upload the CSV or Excel export from the device and choose how its dates are written.',
                            'Check the preview: rows with problems and days to review are counted per employee.',
                            'Press Import. Download the row report if you need to fix the file.',
                            'An import done by mistake can be undone from the list of imports.',
                        ],
                    ],
                    [
                        'id'      => 'attendance-report',
                        'title'   => 'Attendance Report and Payroll input',
                        'purpose' => 'The monthly sheet: worked against required hours, late days, leave and penalties, and the file for payroll.',
                        'steps'   => [
                            'Pick the company and month.',
                            'Check the totals, then download the Excel workbook.',
                            'On Payroll input, download the payroll file. After payroll is paid, press Mark instalments as deducted to close that month\'s loan and advance deductions.',
                        ],
                    ],
                    [
                        'id'      => 'shift-roster',
                        'title'   => 'Shift roster',
                        'purpose' => 'Who works which shift each day, by week.',
                        'steps'   => [
                            'Create shifts and patterns once (the Shifts and Patterns buttons).',
                            'Press Assign roster, choose the pattern, the employees or a department, and the start date.',
                            'Click a day of one person to change it: another shift, a day off, or a swap with a colleague. Give a reason.',
                            'Download (Excel) to share the roster.',
                        ],
                        'approvals' => 'HR can change everyone; a line manager only their own team. Locked (finalised) months cannot be changed.',
                    ],
                    [
                        'id'      => 'leave-requests',
                        'title'   => 'Time Off management (leave requests)',
                        'purpose' => 'Leave requests of the people you manage, and requests HR files for someone.',
                        'steps'   => [
                            'The menu badge shows how many requests wait for a decision.',
                            'Open the row menu on a request and press Approve, Refuse (give a reason) or Return to requester.',
                            'To file leave for someone, press New, choose the employee, then Submit for approval.',
                            'To cancel leave that is already approved, use Cancel leave on it. Approved leave cannot be deleted.',
                        ],
                        'approvals' => 'Line manager first, then HR. Approving at the last step makes the leave final; refusing gives the days back to the balance.',
                    ],
                    [
                        'id'      => 'leave-allocations',
                        'title'   => 'Allocations',
                        'purpose' => 'How many days of each leave type every employee has this year.',
                        'steps'   => [
                            'Yearly allocations are created automatically on 1 January and when someone joins: Annual 12, Sick 8, Casual 5.',
                            'Press New only to give extra days (for example compensation for working a holiday).',
                            'Approve an allocation to make its days usable.',
                        ],
                    ],
                    [
                        'id'      => 'employee-requests',
                        'title'   => 'Employee Requests (HR view)',
                        'purpose' => 'Loans, advances, letters, overtime, claims and attendance corrections of the people you manage.',
                        'steps'   => [
                            'Filter by Status: Pending approval shows what is waiting.',
                            'Approve, Reject or Return to requester from the row. For overtime you can lower the minutes, not raise them.',
                            'For an HR letter, press Generate letter (PDF) or Attach letter with a signed copy. The employee can then download it.',
                            'For an approved loan or advance, Instalments opens the deduction schedule on the employee.',
                        ],
                    ],
                    [
                        'id'      => 'appraisals',
                        'title'   => 'Appraisals (performance cycles)',
                        'purpose' => 'Run a review round: self review, manager review, result.',
                        'steps'   => [
                            'Press New cycle. Choose the template, the period, who takes part and the two deadlines.',
                            'Press Launch. Everyone taking part gets their appraisal and a notification.',
                            'Follow progress on Overview. Managers write their reviews from Team appraisals.',
                            'Press Close & share results. Employees then see their result.',
                        ],
                        'approvals' => 'People without a line manager are reviewed by HR.',
                    ],
                ],
            ],
            [
                'title'  => 'Recruitment',
                'topics' => [
                    [
                        'id'      => 'job-positions',
                        'title'   => 'Job positions',
                        'purpose' => 'The openings you hire for, with their description, salary range, screening questions and CV keywords.',
                        'steps'   => [
                            'Create the job position and fill in the description, location and requirements.',
                            'Add screening questions and CV keywords; applications are scored with them.',
                            'Press Submit for approval. Once approved, press Publish to put it on the careers website; the Job ads tab helps you post it elsewhere.',
                            'Press Close job when the post is filled so it comes off the website.',
                        ],
                    ],
                    [
                        'id'      => 'applicants',
                        'title'   => 'Applicants',
                        'purpose' => 'Everyone who applied, by job and stage.',
                        'steps'   => [
                            'Applications arrive from the careers website by themselves, or add one with New.',
                            'Sort by CV score and open the best first. "Not qualified" means a screening question was failed.',
                            'Schedule interviews from the applicant\'s Interviews tab; invitations are sent automatically.',
                            'Move the stage forward, or Refuse (a polite email is sent and future interviews are cancelled).',
                            'When hired, use Create employee.',
                        ],
                    ],
                    [
                        'id'      => 'job-offers',
                        'title'   => 'Job offers',
                        'purpose' => 'Offers made to candidates and where each one stands.',
                        'steps'   => [
                            'Make an offer from the applicant (Make offer). It is saved as a draft.',
                            'Submit it for approval.',
                            'Once approved, press Generate letter, then Send to candidate. The candidate accepts or declines through their link.',
                            'When accepted, press Create employee.',
                        ],
                        'approvals' => 'Hiring manager, then HR, then the CEO when the salary is above the company threshold.',
                    ],
                ],
            ],
        ];
    }
}
