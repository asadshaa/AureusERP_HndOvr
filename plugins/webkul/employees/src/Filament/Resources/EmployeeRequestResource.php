<?php

namespace Webkul\Employee\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Webkul\Employee\Filament\Resources\EmployeeRequestResource\Pages\ManageEmployeeRequests;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Support\Enums\NavigationGroup;
use Webkul\Support\Services\ApprovalEngine;

class EmployeeRequestResource extends Resource
{
    protected static ?string $model = EmployeeRequest::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?int $navigationSort = 13;

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Employee;
    }

    public static function getNavigationLabel(): string
    {
        return 'Employee Requests';
    }

    /**
     * getEloquentQuery() below already scopes to exactly what this user is
     * allowed to see (their own reports, or everything for HR/finance
     * roles) -- filtering that same scoped query to pending_approval gives
     * a badge that's automatically correct for whoever is looking at it,
     * without duplicating the hierarchy/role logic here.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', 'pending_approval')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function isFinanceUser(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->hasAnyRole([
            'Admin',
            'Super Admin',
            'accountant',
            'Accountant',
            'accounting_manager',
            'Accounting_manager',
            'controller',
            'Controller',
            'tax_officer',
            'Tax_officer',
            'finance_operator',
            'Finance_operator',
            'vp_finance',
            'Vp_finance',
            'cfo',
            'Cfo',
        ]) || $user->can('hr_process_financial_requests');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(static::formComponents());
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 3])
                    ->schema([
                        Group::make([
                            Section::make('Request & Attendance Details')
                                ->schema([
                                    TextEntry::make('requestType.name')
                                        ->label('Request Type')
                                        ->badge()
                                        ->color(fn (EmployeeRequest $record): string => match ($record->requestType?->category) {
                                            'attendance_correction' => 'info',
                                            'financial'             => 'warning',
                                            default                 => 'primary',
                                        })
                                        ->icon('heroicon-o-tag'),
                                    TextEntry::make('title')
                                        ->label('Title')
                                        ->icon('heroicon-o-document-text'),
                                    TextEntry::make('day_of_week')
                                        ->label('Day of the Week')
                                        ->getStateUsing(function (EmployeeRequest $record): ?string {
                                            $payload = (array) ($record->payload ?? []);
                                            if (! empty($payload['day_of_week'])) {
                                                return $payload['day_of_week'];
                                            }
                                            $dateStr = $payload['attendance_date'] ?? null;
                                            if (! $dateStr && isset($payload['attendance_record_id'])) {
                                                $att = AttendanceRecord::find($payload['attendance_record_id']);
                                                $dateStr = $att?->attendance_date?->toDateString();
                                            }

                                            return $dateStr ? Carbon::parse($dateStr)->format('l') : $record->created_at?->format('l');
                                        })
                                        ->badge()
                                        ->color('primary')
                                        ->icon('heroicon-o-calendar-days'),
                                    TextEntry::make('target_date')
                                        ->label('Attendance / Event Date')
                                        ->getStateUsing(function (EmployeeRequest $record): ?string {
                                            $payload = (array) ($record->payload ?? []);
                                            $dateStr = $payload['attendance_date'] ?? null;
                                            if (! $dateStr && isset($payload['attendance_record_id'])) {
                                                $att = AttendanceRecord::find($payload['attendance_record_id']);
                                                $dateStr = $att?->attendance_date?->toDateString();
                                            }
                                            if ($dateStr) {
                                                return Carbon::parse($dateStr)->format('d F Y (l)');
                                            }

                                            return $record->created_at?->format('d F Y (l)');
                                        })
                                        ->icon('heroicon-o-calendar'),
                                    TextEntry::make('check_in_comparison')
                                        ->label('Check-In Time')
                                        ->getStateUsing(function (EmployeeRequest $record): ?string {
                                            $payload = (array) ($record->payload ?? []);
                                            if (! isset($payload['requested']['check_in'])) {
                                                return null;
                                            }
                                            $orig = ! empty($payload['original']['check_in'])
                                                ? Carbon::parse($payload['original']['check_in'])->format('H:i:s (d M)')
                                                : 'None (Missed)';
                                            $req = Carbon::parse($payload['requested']['check_in'])->format('H:i:s (d M)');

                                            return ($payload['kind'] ?? '') === 'attendance_missing_day'
                                                ? "Requested: {$req}"
                                                : "Original: {$orig} → Requested: {$req}";
                                        })
                                        ->visible(fn (EmployeeRequest $record): bool => isset($record->payload['requested']['check_in']))
                                        ->icon('heroicon-o-arrow-right-end-on-rectangle'),
                                    TextEntry::make('check_out_comparison')
                                        ->label('Check-Out Time')
                                        ->getStateUsing(function (EmployeeRequest $record): ?string {
                                            $payload = (array) ($record->payload ?? []);
                                            if (! array_key_exists('check_out', $payload['requested'] ?? [])) {
                                                return null;
                                            }
                                            $orig = ! empty($payload['original']['check_out'])
                                                ? Carbon::parse($payload['original']['check_out'])->format('H:i:s (d M)')
                                                : 'None';
                                            $req = ! empty($payload['requested']['check_out'])
                                                ? Carbon::parse($payload['requested']['check_out'])->format('H:i:s (d M)')
                                                : 'None';

                                            return ($payload['kind'] ?? '') === 'attendance_missing_day'
                                                ? "Requested: {$req}"
                                                : "Original: {$orig} → Requested: {$req}";
                                        })
                                        ->visible(fn (EmployeeRequest $record): bool => isset($record->payload['requested']))
                                        ->icon('heroicon-o-arrow-left-start-on-rectangle'),
                                    TextEntry::make('description')
                                        ->label('Reason / Employee Note')
                                        ->placeholder('No reason provided')
                                        ->icon('heroicon-o-chat-bubble-bottom-center-text')
                                        ->columnSpanFull(),
                                ])
                                ->columns(2),

                            Section::make('Financial Details')
                                ->visible(fn (EmployeeRequest $record): bool => (bool) $record->requestType?->is_financial)
                                ->schema([
                                    TextEntry::make('billed_amount')->label('Claim/Budget')->money(fn ($record) => $record->currency?->code ?? 'PKR'),
                                    TextEntry::make('tax_deduction_rate')->label('Tax Rate')->suffix('%')->placeholder('0%'),
                                    TextEntry::make('income_tax_deduction')->label('Income Tax Deduction')->money(fn ($record) => $record->currency?->code ?? 'PKR'),
                                    TextEntry::make('sales_tax_deduction')->label('Sales Tax Deduction')->money(fn ($record) => $record->currency?->code ?? 'PKR'),
                                    TextEntry::make('amount')->label('Net Payable Amount')->money(fn ($record) => $record->currency?->code ?? 'PKR')->weight(FontWeight::Bold),
                                    TextEntry::make('nature_of_expense')->label('Nature of Expense')->placeholder('—'),
                                ])
                                ->columns(2),
                        ])->columnSpan(2),

                        Group::make([
                            Section::make('Who Sent The Request')
                                ->schema([
                                    TextEntry::make('employee.name')
                                        ->label('Target Employee')
                                        ->icon('heroicon-o-user')
                                        ->weight(FontWeight::Bold),
                                    TextEntry::make('employee.department.name')
                                        ->label('Department')
                                        ->icon('heroicon-o-building-office')
                                        ->placeholder('—'),
                                    TextEntry::make('employee_job_title')
                                        ->label('Job Title')
                                        ->getStateUsing(fn (EmployeeRequest $record): ?string => $record->employee?->job_title ?? $record->employee?->job?->name)
                                        ->icon('heroicon-o-briefcase')
                                        ->placeholder('—'),
                                    TextEntry::make('requester_display')
                                        ->label('Sent By')
                                        ->getStateUsing(function (EmployeeRequest $record): string {
                                            $requester = $record->requester;
                                            if (! $requester) {
                                                return 'System';
                                            }
                                            if ($record->requested_by && (int) $record->requested_by === (int) $record->employee?->user_id) {
                                                return "{$requester->name} (Employee Self)";
                                            }

                                            return "{$requester->name} (On behalf of employee)";
                                        })
                                        ->icon('heroicon-o-paper-airplane'),
                                    TextEntry::make('submitted_at')
                                        ->label('Date & Time Submitted')
                                        ->dateTime('d M Y, h:i A (l)')
                                        ->placeholder('Not submitted')
                                        ->icon('heroicon-o-clock'),
                                ]),

                            Section::make('Approval & Routing Status')
                                ->schema([
                                    TextEntry::make('status')
                                        ->label('Status')
                                        ->badge()
                                        ->color(fn (string $state): string => match ($state) {
                                            'approved'         => 'success',
                                            'rejected'         => 'danger',
                                            'pending_approval' => 'warning',
                                            default            => 'gray',
                                        }),
                                    TextEntry::make('routing_info')
                                        ->label('Routing / Next Approver')
                                        ->getStateUsing(function (EmployeeRequest $record): string {
                                            if ($record->status === 'approved') {
                                                return 'Approved';
                                            }
                                            if ($record->status === 'rejected') {
                                                return 'Rejected (Reason: '.($record->rejection_reason ?? '—').')';
                                            }
                                            if ($record->approvalRequest) {
                                                return app(ApprovalEngine::class)->describeCurrentApprover($record->approvalRequest);
                                            }

                                            return 'Draft';
                                        })
                                        ->badge()
                                        ->color('info')
                                        ->icon('heroicon-o-arrows-pointing-in'),
                                    TextEntry::make('line_manager_info')
                                        ->label('Line Manager Status')
                                        ->getStateUsing(function (EmployeeRequest $record): string {
                                            $parentId = $record->employee?->parent_id;
                                            if (! $parentId) {
                                                return 'No Line Manager assigned (Handled by HR)';
                                            }
                                            $manager = Employee::find($parentId);

                                            return $manager ? "Line Manager: {$manager->name}" : 'No Line Manager assigned';
                                        })
                                        ->icon('heroicon-o-user-group'),
                                    TextEntry::make('approved_at')
                                        ->label('Approved At')
                                        ->dateTime('d M Y, h:i A')
                                        ->visible(fn (EmployeeRequest $record): bool => filled($record->approved_at))
                                        ->icon('heroicon-o-check-circle'),
                                    TextEntry::make('rejection_reason')
                                        ->label('Rejection Reason')
                                        ->visible(fn (EmployeeRequest $record): bool => filled($record->rejection_reason))
                                        ->icon('heroicon-o-x-circle'),
                                ]),
                        ])->columnSpan(1),
                    ]),
            ]);
    }

    /**
     * Shared between the resource's own Create/Edit form and the "Submit"
     * header action (Section 8) so both stay in sync without duplicating
     * the claim-specific fields (billed amount / tax / bank details /
     * nature of expense) twice. Every field beyond the original generic
     * set is conditionally visible+required on the selected request type's
     * `is_financial` flag, not a hard-coded category/type name -- a
     * non-financial request (e.g. Attendance Time Change) still gets the
     * original plain form.
     *
     * @return array<int, Component>
     */
    protected static function formComponents(): array
    {
        $user = Auth::user();
        $companyId = (int) $user?->default_company_id;
        $visibleEmployeeIds = $user ? app(HrHierarchyService::class)->visibleEmployeeIds($user, $companyId) : collect();

        $isFinancial = fn (Get $get): bool => (bool) static::selectedRequestType($get)?->is_financial;
        $isAttendance = fn (Get $get): bool => static::selectedRequestType($get)?->category === 'attendance_correction';
        $canSeeBankDetails = function (Get $get) use ($user): bool {
            if (! $user) {
                return false;
            }
            if ((int) $get('employee_id') === (int) $user->employee?->id) {
                return true;
            }

            return $user->can(HrPermissions::ViewSensitiveEmployeeData);
        };

        return [
            Hidden::make('company_id')->default($companyId),
            Hidden::make('requested_by')->default(fn (): ?int => Auth::id()),
            Hidden::make('status')->default('draft'),
            Section::make('Request Type')->columns(2)->schema([
                Select::make('employee_id')
                    ->relationship('employee', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('company_id', $companyId)->whereIn('id', $visibleEmployeeIds))
                    ->default(fn (): ?int => Auth::user()?->employee?->id)
                    ->required()->searchable()->preload()->live(),
                Select::make('request_type_id')
                    ->label('Approval Type')
                    ->relationship('requestType', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('company_id', $companyId)->where('is_active', true))
                    ->required()->searchable()->preload()->live()
                    ->afterStateUpdated(function (Set $set, $state, Get $get) {
                        $set('nature_of_expense', null);
                        if (! $state) {
                            return;
                        }
                        $type = EmployeeRequestType::find($state);
                        if ($type && $type->category === 'attendance_correction') {
                            $set('currency_id', null);
                            $set('amount', null);
                            $set('billed_amount', null);
                            $set('tax_deduction_rate', null);
                            $set('income_tax_deduction', null);
                            $set('sales_tax_deduction', null);

                            $date = $get('payload.attendance_date') ?: now()->toDateString();
                            $set('payload.attendance_date', $date);
                            $carbon = Carbon::parse($date);
                            $set('payload.day_of_week', $carbon->format('l'));
                            $set('payload.formatted_date', $carbon->format('d M Y'));

                            $empId = $get('employee_id');
                            if ($empId) {
                                $existing = AttendanceRecord::query()
                                    ->where('employee_id', $empId)
                                    ->where('attendance_date', $date)
                                    ->first();
                                if ($existing) {
                                    $set('payload.attendance_record_id', $existing->id);
                                    $set('payload.original.check_in', $existing->check_in?->toDateTimeString());
                                    $set('payload.original.check_out', $existing->check_out?->toDateTimeString());
                                    if ($existing->check_in) {
                                        $set('payload.requested_check_in_time', $existing->check_in->format('H:i'));
                                    }
                                    if ($existing->check_out) {
                                        $set('payload.requested_check_out_time', $existing->check_out->format('H:i'));
                                    }
                                }
                            }
                        }
                    }),
                Placeholder::make('approval_type_display')
                    ->label('Approval Category')
                    ->content(fn (Get $get): string => $isFinancial($get) ? 'Claims Approval' : ($isAttendance($get) ? 'Attendance Correction' : ($get('request_type_id') ? 'Standard Approval' : '—')))
                    ->visible(fn (Get $get): bool => filled($get('request_type_id'))),
                Select::make('nature_of_expense')
                    ->label(fn (Get $get): string => 'What is the nature of expense'.(($name = static::selectedRequestType($get)?->name) ? " for {$name}?" : '?'))
                    ->options(fn (Get $get): array => array_combine(
                        $natures = static::selectedRequestType($get)?->getExpenseNatures() ?? [],
                        $natures,
                    ))
                    ->visible(fn (Get $get): bool => (static::selectedRequestType($get)?->getExpenseNatures() ?? []) !== [])
                    ->required(fn (Get $get): bool => (static::selectedRequestType($get)?->getExpenseNatures() ?? []) !== [])
                    ->searchable(),
            ]),
            TextInput::make('title')
                ->maxLength(255)
                ->columnSpanFull()
                ->placeholder(fn (Get $get): string => $isAttendance($get) ? 'e.g. Attendance time change for Sunday (optional -- auto-generated if left blank)' : 'Request title (optional -- auto-generated if left blank)'),
            Textarea::make('description')
                ->label(fn (Get $get): string => $isAttendance($get) ? 'Reason for attendance adjustment (optional)' : 'Description / Notes (optional)')
                ->columnSpanFull(),

            Section::make('Attendance Details')
                ->columns(2)
                ->visible($isAttendance)
                ->schema([
                    Select::make('payload.kind')
                        ->label('Adjustment Type')
                        ->options([
                            'time_change'            => 'Adjust Times on Existing Record',
                            'attendance_missing_day' => 'Missed Entire Day (Create Missing Attendance)',
                        ])
                        ->default('time_change')
                        ->live()
                        ->required($isAttendance),
                    DatePicker::make('payload.attendance_date')
                        ->label('Attendance Date')
                        ->native(false)
                        ->default(now()->toDateString())
                        ->live()
                        ->required($isAttendance)
                        ->afterStateUpdated(function (Set $set, ?string $state, Get $get): void {
                            if (! $state) {
                                return;
                            }
                            $carbon = Carbon::parse($state);
                            $set('payload.day_of_week', $carbon->format('l'));
                            $set('payload.formatted_date', $carbon->format('d M Y'));

                            $empId = $get('employee_id');
                            if ($empId) {
                                $existing = AttendanceRecord::query()
                                    ->where('employee_id', $empId)
                                    ->where('attendance_date', $state)
                                    ->first();
                                if ($existing) {
                                    $set('payload.attendance_record_id', $existing->id);
                                    $set('payload.original.check_in', $existing->check_in?->toDateTimeString());
                                    $set('payload.original.check_out', $existing->check_out?->toDateTimeString());
                                    if ($existing->check_in) {
                                        $set('payload.requested_check_in_time', $existing->check_in->format('H:i'));
                                    }
                                    if ($existing->check_out) {
                                        $set('payload.requested_check_out_time', $existing->check_out->format('H:i'));
                                    }
                                } else {
                                    $set('payload.attendance_record_id', null);
                                    $set('payload.original.check_in', null);
                                    $set('payload.original.check_out', null);
                                }
                            }
                        }),
                    Placeholder::make('day_of_week_display')
                        ->label('Day of the Week')
                        ->content(function (Get $get): string {
                            $date = $get('payload.attendance_date');

                            return $date ? Carbon::parse($date)->format('l') : '—';
                        })
                        ->visible(fn (Get $get): bool => filled($get('payload.attendance_date'))),
                    Placeholder::make('existing_times_display')
                        ->label('Existing Recorded Times')
                        ->content(function (Get $get): string {
                            $origIn = $get('payload.original.check_in');
                            $origOut = $get('payload.original.check_out');
                            if ($origIn || $origOut) {
                                $inStr = $origIn ? Carbon::parse($origIn)->format('H:i') : 'None';
                                $outStr = $origOut ? Carbon::parse($origOut)->format('H:i') : 'None';

                                return "Check-In: {$inStr} | Check-Out: {$outStr}";
                            }

                            return 'No attendance record found on this date (select "Missed Entire Day" if creating missing attendance).';
                        })
                        ->visible(fn (Get $get): bool => ($get('payload.kind') ?? 'time_change') === 'time_change' && filled($get('payload.attendance_date'))),
                    TimePicker::make('payload.requested_check_in_time')
                        ->label('Requested Check-In Time')
                        ->seconds(false)
                        ->required($isAttendance)
                        ->formatStateUsing(function ($state, $record, Get $get) {
                            if ($state) {
                                return $state;
                            }
                            $full = $record?->payload['requested']['check_in'] ?? $get('payload.requested.check_in');

                            return $full ? Carbon::parse($full)->format('H:i') : null;
                        }),
                    TimePicker::make('payload.requested_check_out_time')
                        ->label('Requested Check-Out Time')
                        ->seconds(false)
                        ->formatStateUsing(function ($state, $record, Get $get) {
                            if ($state) {
                                return $state;
                            }
                            $full = $record?->payload['requested']['check_out'] ?? $get('payload.requested.check_out');

                            return $full ? Carbon::parse($full)->format('H:i') : null;
                        }),
                ]),

            Select::make('currency_id')
                ->relationship('currency', 'name')
                ->default(fn (): ?int => Auth::user()?->defaultCompany?->currency_id)
                ->searchable()->preload()
                ->visible($isFinancial)
                ->required($isFinancial),
            Section::make('Attachments')
                ->visible(fn (Get $get): bool => ! $isAttendance($get))
                ->schema([
                    FileUpload::make('attachments')
                        ->label('Supporting Documents / Receipts')
                        ->multiple()
                        ->disk('local')
                        ->directory('employees/requests')
                        ->visibility('private')
                        ->columnSpanFull(),
                ]),
            KeyValue::make('payload')
                ->label('Additional request details')->columnSpanFull()
                ->visible(fn (Get $get): bool => ! $isFinancial($get) && ! $isAttendance($get)),

            Section::make('Financial Details')->columns(2)
                ->visible($isFinancial)
                ->schema([
                    TextInput::make('billed_amount')
                        ->label('Claim/budget')
                        ->numeric()->minValue(0)
                        ->required($isFinancial)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get) => static::recalculateNetPayment($set, $get)),
                    TextInput::make('tax_deduction_rate')
                        ->label('Tax deduction rate (%)')
                        ->numeric()->minValue(0)->maxValue(100)->suffix('%')
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get) => static::recalculateNetPayment($set, $get)),
                    TextInput::make('income_tax_deduction')
                        ->label('Deduction amount of income tax')
                        ->numeric()->minValue(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get) => static::recalculateNetPayment($set, $get)),
                    TextInput::make('sales_tax_deduction')
                        ->label('Deduction amount of sales tax')
                        ->numeric()->minValue(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get) => static::recalculateNetPayment($set, $get)),
                    TextInput::make('amount')
                        ->label('Net payment')
                        ->numeric()->minValue(0)
                        ->required($isFinancial)
                        ->readOnly($isFinancial)
                        ->helperText('Claim/budget minus tax deductions -- calculated automatically.')
                        ->columnSpanFull(),
                ]),

            Section::make('Bank Details')->columns(3)
                ->visible(fn (Get $get): bool => $isFinancial($get) && $canSeeBankDetails($get))
                ->schema([
                    TextInput::make('account_title')->label('Account Title')->required($isFinancial)->maxLength(255),
                    TextInput::make('iban')->label('IBAN')->required($isFinancial)->maxLength(50),
                    TextInput::make('bank_name')->label('Bank Name')->required($isFinancial)->maxLength(255),
                ]),
        ];
    }

    protected static function selectedRequestType(Get $get): ?EmployeeRequestType
    {
        $id = $get('request_type_id');

        return $id ? EmployeeRequestType::find($id) : null;
    }

    /**
     * Net payment ("amount") is always billed_amount minus both tax
     * deductions, recalculated on every relevant keystroke rather than left
     * for the user to add up -- the income-tax suggestion from the rate is
     * a convenience only and never overwrites a value already typed
     * directly into that field.
     */
    protected static function recalculateNetPayment(Set $set, Get $get): void
    {
        $billed = (float) ($get('billed_amount') ?? 0);
        $rate = $get('tax_deduction_rate');
        if (filled($rate) && blank($get('income_tax_deduction'))) {
            $set('income_tax_deduction', round($billed * ((float) $rate / 100), 4));
        }
        $incomeTax = (float) ($get('income_tax_deduction') ?? 0);
        $salesTax = (float) ($get('sales_tax_deduction') ?? 0);
        $set('amount', round($billed - $incomeTax - $salesTax, 4));
    }

    public static function formatAttendancePayload(array $data): array
    {
        if (isset($data['payload']) && is_array($data['payload'])) {
            $payload = $data['payload'];
            $dateStr = $payload['attendance_date'] ?? null;

            if ($dateStr) {
                $carbonDate = Carbon::parse($dateStr);
                $payload['day_of_week'] = $carbonDate->format('l');
                $payload['formatted_date'] = $carbonDate->format('d M Y');

                $inTime = $payload['requested_check_in_time'] ?? null;
                $outTime = $payload['requested_check_out_time'] ?? null;

                if ($inTime) {
                    $payload['requested']['check_in'] = Carbon::parse("{$dateStr} {$inTime}")->toDateTimeString();
                } elseif (isset($payload['requested']['check_in']) && strlen($payload['requested']['check_in']) <= 8) {
                    $payload['requested']['check_in'] = Carbon::parse("{$dateStr} {$payload['requested']['check_in']}")->toDateTimeString();
                }

                if ($outTime) {
                    $outCarbon = Carbon::parse("{$dateStr} {$outTime}");
                    if ($inTime && $outCarbon->lt(Carbon::parse("{$dateStr} {$inTime}"))) {
                        $outCarbon->addDay();
                    }
                    $payload['requested']['check_out'] = $outCarbon->toDateTimeString();
                } elseif (isset($payload['requested']['check_out']) && strlen($payload['requested']['check_out']) <= 8) {
                    $outCarbon = Carbon::parse("{$dateStr} {$payload['requested']['check_out']}");
                    if (isset($payload['requested']['check_in']) && $outCarbon->lt(Carbon::parse($payload['requested']['check_in']))) {
                        $outCarbon->addDay();
                    }
                    $payload['requested']['check_out'] = $outCarbon->toDateTimeString();
                }
            }

            $data['payload'] = $payload;
        }

        if (blank($data['title'] ?? null)) {
            $typeName = isset($data['request_type_id'])
                ? EmployeeRequestType::find($data['request_type_id'])?->name
                : 'Employee Request';
            $datePart = $data['payload']['formatted_date'] ?? now()->format('d M Y');
            $data['title'] = "{$typeName} - {$datePart}";
        }

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('reference')->searchable()->placeholder('Draft'),
            TextColumn::make('employee.name')
                ->label('Employee')
                ->searchable()
                ->sortable()
                ->description(function (EmployeeRequest $record): ?string {
                    if ($record->requested_by && (int) $record->requested_by !== (int) $record->employee?->user_id) {
                        $by = $record->requester?->name ?? 'User #'.$record->requested_by;

                        return "Sent by {$by}";
                    }

                    return null;
                }),
            TextColumn::make('requestType.name')
                ->label('Request type')
                ->searchable()
                ->badge()
                ->color(fn (EmployeeRequest $record): string => match ($record->requestType?->category) {
                    'attendance_correction' => 'info',
                    'financial'             => 'warning',
                    default                 => 'gray',
                }),
            TextColumn::make('target_day_and_date')
                ->label('Day & Date')
                ->getStateUsing(function (EmployeeRequest $record): ?string {
                    $payload = (array) ($record->payload ?? []);
                    $dateStr = $payload['attendance_date'] ?? null;
                    if (! $dateStr && isset($payload['attendance_record_id'])) {
                        $att = AttendanceRecord::find($payload['attendance_record_id']);
                        $dateStr = $att?->attendance_date?->toDateString();
                    }
                    if ($dateStr) {
                        $carbon = Carbon::parse($dateStr);
                        $day = $payload['day_of_week'] ?? $carbon->format('l');

                        return $carbon->format('d M Y').' ('.$day.')';
                    }

                    return $record->created_at?->format('d M Y (l)');
                })
                ->badge(fn ($state): bool => filled($state))
                ->color('gray'),
            TextColumn::make('details_summary')
                ->label('Details / Times')
                ->getStateUsing(function (EmployeeRequest $record): string {
                    $payload = (array) ($record->payload ?? []);
                    if (isset($payload['requested']['check_in'])) {
                        $reqIn = $payload['requested']['check_in'] ? Carbon::parse($payload['requested']['check_in'])->format('H:i') : '—';
                        $reqOut = ! empty($payload['requested']['check_out']) ? Carbon::parse($payload['requested']['check_out'])->format('H:i') : '—';
                        $origIn = ! empty($payload['original']['check_in']) ? Carbon::parse($payload['original']['check_in'])->format('H:i') : '—';
                        $origOut = ! empty($payload['original']['check_out']) ? Carbon::parse($payload['original']['check_out'])->format('H:i') : '—';
                        if (($payload['kind'] ?? '') === 'attendance_missing_day') {
                            return "Missed Day [In: {$reqIn} | Out: {$reqOut}]";
                        }

                        return "In: {$origIn} → {$reqIn} | Out: {$origOut} → {$reqOut}";
                    }
                    if ($record->nature_of_expense) {
                        return $record->nature_of_expense;
                    }

                    return $record->title ?? '—';
                })
                ->description(fn (EmployeeRequest $record): ?string => $record->description ? Str::limit($record->description, 40) : null)
                ->wrap(),
            TextColumn::make('billed_amount')->label('Claim/budget')->money(fn (EmployeeRequest $record): string => $record->currency?->code ?? 'PKR')->placeholder('—')->sortable()->toggleable(),
            TextColumn::make('amount')->label('Net payment')->money(fn (EmployeeRequest $record): string => $record->currency?->code ?? 'PKR')->placeholder('—')->sortable()->toggleable(),
            TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                'approved' => 'success', 'rejected' => 'danger', 'pending_approval' => 'warning', default => 'gray',
            }),
            TextColumn::make('current_approver')
                ->label('Current Approver')
                ->getStateUsing(function (EmployeeRequest $record): string {
                    if ($record->status === 'approved') {
                        return 'Approved';
                    }
                    if ($record->status === 'rejected') {
                        return 'Rejected';
                    }
                    if ($record->approvalRequest) {
                        return app(ApprovalEngine::class)->describeCurrentApprover($record->approvalRequest);
                    }

                    return 'Draft';
                })
                ->limit(35),
            TextColumn::make('submitted_at')->dateTime()->placeholder('Not submitted')->toggleable(),
        ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    'draft'    => 'Draft', 'pending_approval' => 'Pending approval',
                    'approved' => 'Approved', 'rejected' => 'Rejected',
                ]),
            ])->recordActions([
                ViewAction::make()->modalHeading('Employee Request Details'),
                Action::make('submit')
                    ->icon('heroicon-o-paper-airplane')->color('primary')->requiresConfirmation()
                    ->visible(fn (EmployeeRequest $record): bool => in_array($record->status, ['draft', 'rejected'], true))
                    ->action(function (EmployeeRequest $record): void {
                        try {
                            $request = app(EmployeeRequestService::class)->submit($record, Auth::user());
                            Notification::make()->success()
                                ->title('Employee request submitted for approval')
                                ->body(app(ApprovalEngine::class)->describeCurrentApprover($request))
                                ->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Could not submit')->body($e->getMessage())->send();
                        }
                    }),
                Action::make('adjust_tax')
                    ->label('Review & Edit Tax')
                    ->icon('heroicon-o-calculator')
                    ->color('warning')
                    ->visible(fn (EmployeeRequest $record): bool => $record->status === 'pending_approval'
                        && static::isFinanceUser(Auth::user())
                        && (bool) $record->requestType?->is_financial
                    )
                    ->fillForm(fn (EmployeeRequest $record): array => [
                        'billed_amount'        => $record->billed_amount,
                        'tax_deduction_rate'   => $record->tax_deduction_rate,
                        'income_tax_deduction' => $record->income_tax_deduction,
                        'sales_tax_deduction'  => $record->sales_tax_deduction,
                        'amount'               => $record->amount,
                    ])
                    ->schema([
                        TextInput::make('billed_amount')
                            ->label('Claim/budget')
                            ->numeric()
                            ->readOnly(),
                        TextInput::make('tax_deduction_rate')
                            ->label('Tax deduction rate (%)')
                            ->numeric()->minValue(0)->maxValue(100)->suffix('%')
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, Get $get): void {
                                $billed = (float) ($get('billed_amount') ?? 0);
                                $rate = $get('tax_deduction_rate');
                                if (filled($rate)) {
                                    $set('income_tax_deduction', round($billed * ((float) $rate / 100), 4));
                                }
                                $inc = (float) ($get('income_tax_deduction') ?? 0);
                                $sal = (float) ($get('sales_tax_deduction') ?? 0);
                                $set('amount', round($billed - $inc - $sal, 4));
                            }),
                        TextInput::make('income_tax_deduction')
                            ->label('Deduction amount of income tax')
                            ->numeric()->minValue(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, Get $get): void {
                                $billed = (float) ($get('billed_amount') ?? 0);
                                $inc = (float) ($get('income_tax_deduction') ?? 0);
                                $sal = (float) ($get('sales_tax_deduction') ?? 0);
                                $set('amount', round($billed - $inc - $sal, 4));
                            }),
                        TextInput::make('sales_tax_deduction')
                            ->label('Deduction amount of sales tax')
                            ->numeric()->minValue(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, Get $get): void {
                                $billed = (float) ($get('billed_amount') ?? 0);
                                $inc = (float) ($get('income_tax_deduction') ?? 0);
                                $sal = (float) ($get('sales_tax_deduction') ?? 0);
                                $set('amount', round($billed - $inc - $sal, 4));
                            }),
                        TextInput::make('amount')
                            ->label('Net payment')
                            ->numeric()
                            ->readOnly()
                            ->helperText('Claim/budget minus tax deductions -- calculated automatically.'),
                    ])
                    ->action(function (EmployeeRequest $record, array $data): void {
                        $billed = (float) ($record->billed_amount ?? 0);
                        $incomeTax = (float) ($data['income_tax_deduction'] ?? 0);
                        $salesTax = (float) ($data['sales_tax_deduction'] ?? 0);
                        $net = round($billed - $incomeTax - $salesTax, 4);

                        $record->update([
                            'tax_deduction_rate'   => $data['tax_deduction_rate'] ?? null,
                            'income_tax_deduction' => $incomeTax,
                            'sales_tax_deduction'  => $salesTax,
                            'amount'               => $net,
                        ]);

                        if ($record->approval_request_id) {
                            $record->approvalRequest?->update([
                                'amount' => (string) $net,
                            ]);
                        }

                        Notification::make()
                            ->success()
                            ->title('Tax deductions updated')
                            ->body("Net payment recalculated to {$net}")
                            ->send();
                    }),
                Action::make('approve_request')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (EmployeeRequest $record): bool => $record->status === 'pending_approval'
                        && $record->approvalRequest !== null
                        && Auth::user() !== null
                        && app(ApprovalEngine::class)->canAct($record->approvalRequest, Auth::user())
                    )
                    ->schema([
                        Textarea::make('reason')->label('Approval note'),
                    ])
                    ->action(function (EmployeeRequest $record, array $data): void {
                        try {
                            app(EmployeeRequestService::class)->approve($record, Auth::user(), $data['reason'] ?? null);
                            Notification::make()->success()->title('Request approved successfully')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Could not approve')->body($e->getMessage())->send();
                        }
                    }),
                Action::make('reject_request')
                    ->label('Reject')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (EmployeeRequest $record): bool => $record->status === 'pending_approval'
                        && $record->approvalRequest !== null
                        && Auth::user() !== null
                        && app(ApprovalEngine::class)->canAct($record->approvalRequest, Auth::user())
                    )
                    ->schema([
                        Textarea::make('reason')->label('Rejection reason')->required(),
                    ])
                    ->action(function (EmployeeRequest $record, array $data): void {
                        try {
                            app(EmployeeRequestService::class)->reject($record, Auth::user(), (string) $data['reason']);
                            Notification::make()->success()->title('Request rejected')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Could not reject')->body($e->getMessage())->send();
                        }
                    }),
                Action::make('refresh_approval')
                    ->label('Refresh approval')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (EmployeeRequest $record): bool => $record->approval_request_id !== null && $record->status === 'pending_approval')
                    ->action(function (EmployeeRequest $record): void {
                        app(EmployeeRequestService::class)->synchronize($record);
                        Notification::make()->success()->title('Approval status refreshed')->send();
                    }),
                EditAction::make()
                    ->visible(fn (EmployeeRequest $record): bool => in_array($record->status, ['draft', 'rejected'], true)
                        || ($record->status === 'pending_approval' && static::isFinanceUser(Auth::user()))
                    )
                    ->mutateFormDataUsing(fn (array $data): array => static::formatAttendancePayload($data))
                    ->after(function (EmployeeRequest $record): void {
                        if ($record->approval_request_id && $record->status === 'pending_approval') {
                            $record->approvalRequest?->update([
                                'amount' => (string) $record->amount,
                            ]);
                        }
                    }),
                DeleteAction::make()->visible(fn (EmployeeRequest $record): bool => $record->status === 'draft'),
            ])->headerActions([
                CreateAction::make()
                    ->label('Save Draft')
                    ->mutateFormDataUsing(fn (array $data): array => static::formatAttendancePayload($data)),
                Action::make('submit_new')
                    ->label('Submit')
                    ->icon('heroicon-o-paper-airplane')->color('primary')
                    ->schema(fn (): array => static::formComponents())
                    ->action(function (array $data): void {
                        $data = static::formatAttendancePayload($data);
                        $record = EmployeeRequest::query()->create($data);
                        try {
                            $request = app(EmployeeRequestService::class)->submit($record, Auth::user());
                            Notification::make()->success()
                                ->title('Request submitted for approval')
                                ->body(app(ApprovalEngine::class)->describeCurrentApprover($request))
                                ->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Saved as draft -- could not submit')->body($e->getMessage())->send();
                        }
                    }),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        if (! $user) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        $companyId = (int) $user->default_company_id;

        $withRelations = [
            'employee.department',
            'employee.job',
            'employee.parent',
            'requester',
            'requestType',
            'currency',
            'approvalRequest.workflow.steps',
            'approvalRequest.decisions.actor',
        ];

        if (
            $user->hasRole([
                'Admin',
                'Super Admin',
                'hr',
                'hr_manager',
                'hr manager',
                'hr_ops_manager',
                'hr ops manager',
                'hr operations manager',
                'hr_administrator',
                'hr administrator',
                'human resources',
                'human resources manager',
            ])
            || $user->can('hr_view_all_records')
            || $user->can('hr_manage_attendance')
            || $user->can('hr_manage_employee_requests')
            || $user->can('hr_approve_leave')
            || static::isFinanceUser($user)
        ) {
            return parent::getEloquentQuery()->where('company_id', $companyId)->with($withRelations);
        }

        $visible = app(HrHierarchyService::class)->visibleEmployeeIds($user, $companyId);

        // A step can be pinned to one specific named user (approver_user_id) rather
        // than a role -- e.g. the claims hierarchy's Level 1/3/4 approvers. Someone
        // who is only the named approver on a pending step (not a manager, not HR,
        // not Finance) still needs to be able to SEE the request in order to act on
        // it, even though it falls outside their normal reporting-tree visibility.
        $pendingOnMe = DB::table('support_approval_requests as ar')
            ->join('support_approval_steps as s', function ($join) {
                $join->on('s.workflow_id', '=', 'ar.workflow_id')
                    ->on('s.sequence', '=', 'ar.current_step_sequence');
            })
            ->where('ar.status', 'pending')
            ->where('s.approver_user_id', $user->id)
            ->pluck('ar.id');

        // Once a request is fully approved/rejected it no longer has a "current
        // step" for anyone to be pinned to, so $pendingOnMe alone would make it
        // vanish even for someone who actually approved/rejected a step on it --
        // keep it visible to them afterwards too, using the real decision record.
        $decidedByMe = DB::table('support_approval_decisions')
            ->where('actor_id', $user->id)
            ->pluck('request_id');

        return parent::getEloquentQuery()->where('company_id', $companyId)
            ->with($withRelations)
            ->where(fn (Builder $query) => $query
                ->whereIn('employee_id', $visible)
                ->orWhereIn('approval_request_id', $pendingOnMe)
                ->orWhereIn('approval_request_id', $decidedByMe));
    }

    public static function getPages(): array
    {
        return ['index' => ManageEmployeeRequests::route('/')];
    }
}
