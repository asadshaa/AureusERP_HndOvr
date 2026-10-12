<?php

namespace Webkul\Support\Filament\Pages;

use App\Filament\Widgets\EmployeeDashboardOverviewWidget;
use Carbon\Carbon;
use Exception;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Services\Attendance\AttendanceScheduleResolver;
use Webkul\Employee\Services\Attendance\GeofencedAttendanceService;
use Webkul\Employee\Services\EmployeeSensitiveChangeService;
use Webkul\Partner\Models\BankAccount;
use Webkul\Support\Filament\Clusters\Settings;
use Webkul\Support\Services\ApprovalEngine;

class Profile extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'support::pages.profile';

    protected static ?string $cluster = Settings::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = null;

    public ?array $profileData = [];

    public ?array $passwordData = [];

    public function mount(): void
    {
        $this->fillForms();
    }

    protected function getForms(): array
    {
        return [
            'editProfileForm',
        ];
    }

    protected function getSchemas(): array
    {
        $schemas = [];

        if (Filament::hasMultiFactorAuthentication()) {
            $schemas[] = 'multiFactorAuthenticationSchema';
        }

        return $schemas;
    }

    public function editProfileForm(Schema $schema): Schema
    {
        $user = $this->getUser();
        $employee = $user->employee ?? Employee::where('user_id', $user->id)->first();

        $sections = [
            Section::make(__('support::filament/pages/profile.information_section'))
                ->description(__('support::filament/pages/profile.information_description'))
                ->icon('heroicon-o-user')
                ->schema([
                    FileUpload::make('avatar')
                        ->label(__('support::filament/pages/profile.fields.avatar'))
                        ->avatar()
                        ->directory('users/avatars')
                        ->visibility('public')
                        ->disk('public')
                        ->acceptedFileTypes(['image/jpeg', 'image/jpg', 'image/png', 'image/webp'])
                        ->maxSize(2048)
                        ->image()
                        ->imageEditor()
                        ->imageEditorAspectRatioOptions([
                            '1:1',
                        ])
                        ->columnSpanFull()
                        ->helperText(__('support::filament/pages/profile.fields.avatar').': '.__('support::filament/pages/profile.information_description'))
                        ->deletable(true)
                        ->downloadable(false),

                    Grid::make(2)
                        ->schema([
                            TextInput::make('name')
                                ->label(__('support::filament/pages/profile.fields.name'))
                                ->required()
                                ->maxLength(255)
                                ->autocomplete('name')
                                ->validationAttribute(__('support::filament/pages/profile.fields.name'))
                                ->rules(['required', 'string', 'max:255'])
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, Set $set) {
                                    $set('name', trim($state));
                                }),

                            TextInput::make('email')
                                ->label(__('support::filament/pages/profile.fields.email'))
                                ->email()
                                ->required()
                                ->maxLength(255)
                                ->unique(table: 'users', column: 'email', ignoreRecord: true)
                                ->autocomplete('email')
                                ->validationAttribute(__('support::filament/pages/profile.fields.email'))
                                ->rules(['required', 'email', 'max:255'])
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, Set $set) {
                                    $set('email', strtolower(trim($state)));
                                }),

                            Select::make('language')
                                ->label(__('support::filament/pages/profile.fields.language'))
                                ->options(collect(config('app.supported_locales', []))
                                    ->mapWithKeys(fn ($meta, $code) => [
                                        $code => ($meta['native'] ?? $code).' ('.($meta['label'] ?? $code).')',
                                    ])
                                    ->all())
                                ->default(config('app.locale'))
                                ->native(false)
                                ->searchable()
                                ->selectablePlaceholder(false)
                                ->helperText(__('support::filament/pages/profile.fields.language_helper'))
                                ->columnSpanFull(),
                        ]),
                ]),
        ];

        if ($employee) {
            $sections[] = Section::make('Employment Profile (ERP Record)')
                ->description('Your official employee records and organization placement in the ERP system.')
                ->icon('heroicon-o-identification')
                ->collapsible()
                ->schema([
                    Grid::make(3)
                        ->schema([
                            TextInput::make('employee_number')
                                ->label('Employee ID / Code')
                                ->disabled()
                                ->dehydrated(false),

                            TextInput::make('job_title')
                                ->label('Job Position / Title')
                                ->disabled()
                                ->dehydrated(false),

                            TextInput::make('department')
                                ->label('Department')
                                ->disabled()
                                ->dehydrated(false),

                            TextInput::make('line_manager')
                                ->label('Reporting Line Manager')
                                ->disabled()
                                ->dehydrated(false),

                            TextInput::make('work_location')
                                ->label('Work Location')
                                ->disabled()
                                ->dehydrated(false),

                            TextInput::make('joining_date')
                                ->label('Date of Joining')
                                ->disabled()
                                ->dehydrated(false),
                        ]),
                ]);

            $sections[] = Section::make('Personal & Emergency Contact Details')
                ->description('Update your direct contact information. Changes saved here will immediately reflect upon your ERP employee records.')
                ->icon('heroicon-o-phone')
                ->collapsible()
                ->schema([
                    Grid::make(2)
                        ->schema([
                            TextInput::make('work_phone')
                                ->label('Work Phone')
                                ->tel()
                                ->maxLength(50),

                            TextInput::make('mobile_phone')
                                ->label('Mobile Phone')
                                ->tel()
                                ->maxLength(50),

                            TextInput::make('private_email')
                                ->label('Personal / Private Email')
                                ->email()
                                ->maxLength(255),

                            TextInput::make('emergency_contact')
                                ->label('Emergency Contact Name')
                                ->maxLength(255),

                            TextInput::make('emergency_relationship')
                                ->label('Emergency Relationship')
                                ->placeholder('e.g. Spouse, Parent, Sibling')
                                ->maxLength(100),

                            TextInput::make('emergency_phone')
                                ->label('Emergency Contact Phone')
                                ->tel()
                                ->maxLength(50),
                        ]),
                ]);
        }

        return $schema
            ->components($sections)
            ->model($user)
            ->statePath('profileData')
            ->operation('edit');
    }

    public function editPasswordForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('support::filament/pages/profile.password.section'))
                    ->description(__('support::filament/pages/profile.password.description'))
                    ->icon('heroicon-o-lock-closed')
                    ->schema([
                        TextInput::make('current_password')
                            ->label(__('support::filament/pages/profile.password.current'))
                            ->password()
                            ->revealable()
                            ->required()
                            ->autocomplete('current-password')
                            ->validationAttribute(__('support::filament/pages/profile.password.current'))
                            ->currentPassword()
                            ->helperText(__('support::filament/pages/profile.password.current-helper')),

                        TextInput::make('password')
                            ->label(__('support::filament/pages/profile.password.new'))
                            ->password()
                            ->revealable()
                            ->required()
                            ->rule(Password::default()->min(6))
                            ->autocomplete('new-password')
                            ->validationAttribute(__('support::filament/pages/profile.password.new'))
                            ->live(debounce: 500)
                            ->confirmed()
                            ->helperText(__('support::filament/pages/profile.password.helper'))
                            ->different('current_password')
                            ->dehydrateStateUsing(fn ($state): ?string => $state ? Hash::make($state) : null),

                        TextInput::make('password_confirmation')
                            ->label(__('support::filament/pages/profile.password.confirm'))
                            ->password()
                            ->revealable()
                            ->required()
                            ->dehydrated(false)
                            ->autocomplete('new-password')
                            ->validationAttribute(__('support::filament/pages/profile.password.confirm'))
                            ->same('password'),
                    ]),
            ])
            ->model($this->getUser())
            ->statePath('passwordData')
            ->operation('edit');
    }

    public function updateProfile(): mixed
    {
        try {
            $this->editProfileForm->validate();

            $data = $this->editProfileForm->getState();
            $user = $this->getUser();

            $previousLanguage = $user->language ?? app()->getLocale();

            if (array_key_exists('avatar', $data) && $user->partner) {
                if (
                    $user->avatar
                    && $data['avatar'] !== $user->avatar
                ) {
                    Storage::disk('public')->delete($user->avatar);
                }

                $user->partner->avatar = $data['avatar'];
                $user->partner->save();
            }

            $fill = [
                'name'  => trim($data['name']),
                'email' => strtolower(trim($data['email'])),
            ];

            if (array_key_exists('language', $data) && $data['language']) {
                $supported = array_keys(config('app.supported_locales', []));

                if (in_array($data['language'], $supported, true)) {
                    $fill['language'] = $data['language'];
                }
            }

            $user->fill($fill);

            $user->save();

            $employee = $user->employee ?? Employee::where('user_id', $user->id)->first();
            if ($employee) {
                $empFill = [
                    'name'       => trim($data['name']),
                    'work_email' => strtolower(trim($data['email'])),
                ];

                if (array_key_exists('work_phone', $data)) {
                    $empFill['work_phone'] = $data['work_phone'];
                }
                if (array_key_exists('mobile_phone', $data)) {
                    $empFill['mobile_phone'] = $data['mobile_phone'];
                }
                if (array_key_exists('private_email', $data)) {
                    $empFill['private_email'] = $data['private_email'];
                }
                if (array_key_exists('emergency_contact', $data)) {
                    $empFill['emergency_contact'] = $data['emergency_contact'];
                }
                if (array_key_exists('emergency_relationship', $data)) {
                    $empFill['emergency_relationship'] = $data['emergency_relationship'];
                }
                if (array_key_exists('emergency_phone', $data)) {
                    $empFill['emergency_phone'] = $data['emergency_phone'];
                }

                $employee->update($empFill);
            }

            $languageChanged = isset($fill['language']) && $fill['language'] !== $previousLanguage;

            if ($languageChanged) {
                app()->setLocale($fill['language']);

                session()->put('locale', $fill['language']);
            }

            $this->fillProfileForm();

            $this->dispatch('profile-updated');

            Notification::make()
                ->title(__('support::filament/pages/profile.notification.success.title'))
                ->body(__('support::filament/pages/profile.notification.success.body'))
                ->success()
                ->duration(3000)
                ->send();

            if ($languageChanged) {
                return redirect(static::getUrl());
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            Notification::make()
                ->title(__('support::filament/pages/profile.notification.error.title'))
                ->body(__('support::filament/pages/profile.notification.error.body'))
                ->danger()
                ->duration(5000)
                ->send();
        }

        return null;
    }

    public function updatePassword(): mixed
    {
        try {
            $this->editPasswordForm->validate();

            $data = $this->editPasswordForm->getState();
            $user = $this->getUser();

            if (Hash::check($this->passwordData['password'], $user->password)) {
                throw ValidationException::withMessages([
                    'passwordData.password' => [__('support::filament/pages/profile.password.errors.same-as-current')],
                ]);
            }

            $user->password = $data['password'];
            $user->save();

            $this->editPasswordForm->fill([
                'current_password'      => '',
                'password'              => '',
                'password_confirmation' => '',
            ]);

            $this->dispatch('password-updated');

            Notification::make()
                ->title(__('support::filament/pages/profile.password.notification.success.title'))
                ->body(__('support::filament/pages/profile.password.notification.success.body'))
                ->success()
                ->duration(3000)
                ->send();

            if (request()->hasSession()) {
                request()->session()->invalidate();
                request()->session()->regenerateToken();
            }

            return redirect()->to(filament()->getCurrentOrDefaultPanel()->getLoginUrl());
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            Notification::make()
                ->title(__('support::filament/pages/profile.password.notification.error.title'))
                ->body(__('support::filament/pages/profile.password.notification.error.body'))
                ->danger()
                ->duration(5000)
                ->send();
        }

        return null;
    }

    protected function getUser(): Authenticatable&Model
    {
        $user = Filament::auth()->user();

        if (! $user instanceof Model) {
            throw new Exception('The authenticated user object must be an Eloquent model to allow the profile page to update it.');
        }

        return $user;
    }

    protected function fillForms(): void
    {
        $this->fillProfileForm();
        $this->fillPasswordForm();
    }

    protected function fillProfileForm(): void
    {
        $user = $this->getUser();

        $userData = $user->only(['name', 'email', 'avatar', 'language']);

        $userData['avatar'] = $user->partner?->avatar ?? $user->avatar;

        if (empty($userData['language'])) {
            $userData['language'] = app()->getLocale();
        }

        $employee = $user->employee ?? Employee::with(['department', 'job', 'parent', 'workLocation'])->where('user_id', $user->id)->first();
        if ($employee) {
            $userData['employee_number'] = $employee->employee_number ?? ('EMP-'.str_pad($employee->id, 4, '0', STR_PAD_LEFT));
            $userData['job_title'] = $employee->job_title ?? $employee->job?->name ?? '—';
            $userData['department'] = $employee->department?->name ?? '—';
            $userData['line_manager'] = $employee->parent?->name ?? 'Not Assigned';
            $userData['work_location'] = $employee->workLocation?->name ?? 'Head Office';
            $userData['joining_date'] = $employee->joining_date ? Carbon::parse($employee->joining_date)->format('d M Y') : '—';

            $userData['work_phone'] = $employee->work_phone;
            $userData['mobile_phone'] = $employee->mobile_phone;
            $userData['private_email'] = $employee->private_email;
            $userData['emergency_contact'] = $employee->emergency_contact;
            $userData['emergency_relationship'] = $employee->emergency_relationship;
            $userData['emergency_phone'] = $employee->emergency_phone;
        }

        $this->editProfileForm->fill($userData);
    }

    protected function fillPasswordForm(): void
    {
        $this->editPasswordForm->fill([
            'current_password'      => '',
            'password'              => '',
            'password_confirmation' => '',
        ]);
    }

    public function getTitle(): string
    {
        return __('support::filament/pages/profile.title');
    }

    public function getHeading(): string
    {
        return __('support::filament/pages/profile.heading');
    }

    public function getSubheading(): ?string
    {
        return __('support::filament/pages/profile.subheading');
    }

    protected function getUpdateProfileFormActions(): array
    {
        return [
            Action::make('updateProfile')
                ->label(__('support::filament/pages/profile.actions.save'))
                ->color('primary')
                ->action('updateProfile'),
        ];
    }

    protected function getUpdatePasswordFormActions(): array
    {
        return [
            Action::make('updatePassword')
                ->label(__('support::filament/pages/profile.password.section'))
                ->color('warning')
                ->action('updatePassword'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('updatePassword')
                ->label(__('support::filament/pages/profile.password.section'))
                ->icon('heroicon-o-lock-closed')
                ->color('warning')
                ->modalHeading(__('support::filament/pages/profile.password.section'))
                ->modalDescription(__('support::filament/pages/profile.password.description'))
                ->modalIcon('heroicon-o-lock-closed')
                ->modalSubmitActionLabel(__('support::filament/pages/profile.password.section'))
                ->fillForm([
                    'current_password'      => '',
                    'password'              => '',
                    'password_confirmation' => '',
                ])
                ->schema([
                    TextInput::make('current_password')
                        ->label(__('support::filament/pages/profile.password.current'))
                        ->password()
                        ->revealable()
                        ->required()
                        ->autocomplete('current-password')
                        ->validationAttribute(__('support::filament/pages/profile.password.current'))
                        ->currentPassword()
                        ->helperText(__('support::filament/pages/profile.password.current-helper')),

                    TextInput::make('password')
                        ->label(__('support::filament/pages/profile.password.new'))
                        ->password()
                        ->revealable()
                        ->required()
                        ->rule(Password::default()->min(6))
                        ->autocomplete('new-password')
                        ->validationAttribute(__('support::filament/pages/profile.password.new'))
                        ->live(debounce: 500)
                        ->confirmed()
                        ->helperText(__('support::filament/pages/profile.password.helper'))
                        ->different('current_password')
                        ->dehydrateStateUsing(fn ($state): ?string => $state ? Hash::make($state) : null),

                    TextInput::make('password_confirmation')
                        ->label(__('support::filament/pages/profile.password.confirm'))
                        ->password()
                        ->revealable()
                        ->required()
                        ->dehydrated(false)
                        ->autocomplete('new-password')
                        ->validationAttribute(__('support::filament/pages/profile.password.confirm'))
                        ->same('password'),
                ])
                ->action(function (Action $action, array $data): void {
                    try {
                        $user = $this->getUser();

                        if (Hash::check($data['password'], $user->password)) {
                            throw ValidationException::withMessages([
                                'data.password' => [__('support::filament/pages/profile.password.errors.same-as-current')],
                            ]);
                        }

                        $user->password = $data['password'];
                        $user->save();

                        Notification::make()
                            ->title(__('support::filament/pages/profile.password.notification.success.title'))
                            ->body(__('support::filament/pages/profile.password.notification.success.body'))
                            ->success()
                            ->duration(3000)
                            ->send();

                        if (request()->hasSession()) {
                            request()->session()->invalidate();
                            request()->session()->regenerateToken();
                        }

                        $action->cancel();

                        redirect()->to(filament()->getCurrentOrDefaultPanel()->getLoginUrl());
                    } catch (ValidationException $e) {
                        throw $e;
                    } catch (Exception $e) {
                        Notification::make()
                            ->title(__('support::filament/pages/profile.password.notification.error.title'))
                            ->body(__('support::filament/pages/profile.password.notification.error.body'))
                            ->danger()
                            ->duration(5000)
                            ->send();
                    }
                }),
            Action::make('requestSensitiveChange')
                ->label('Request Sensitive Data Update')
                ->icon('heroicon-o-shield-check')
                ->color('primary')
                ->visible(fn (): bool => ($this->getUser()->employee ?? Employee::where('user_id', $this->getUser()->id)->first()) !== null)
                ->modalHeading('Request Sensitive Data Update')
                ->modalDescription('Changes to official identification, passport, or bank details are subject to internal HR verification and approval.')
                ->modalIcon('heroicon-o-shield-check')
                ->modalSubmitActionLabel('Submit for HR Approval')
                ->fillForm(function (): array {
                    $employee = $this->getUser()->employee ?? Employee::where('user_id', $this->getUser()->id)->first();

                    return [
                        'identification_id' => $employee?->identification_id,
                        'passport_id'       => $employee?->passport_id,
                        'bank_account_id'   => $employee?->bank_account_id,
                        'reason'            => '',
                    ];
                })
                ->schema([
                    TextInput::make('identification_id')
                        ->label('National ID / CNIC')
                        ->placeholder('e.g. 42101-1234567-1')
                        ->maxLength(50),
                    TextInput::make('passport_id')
                        ->label('Passport Number')
                        ->placeholder('e.g. PK1234567')
                        ->maxLength(50),
                    Select::make('bank_account_id')
                        ->label('Bank Account')
                        ->options(fn () => BankAccount::query()->pluck('account_number', 'id'))
                        ->placeholder('Select or search existing bank account')
                        ->searchable()
                        ->preload(),
                    Textarea::make('reason')
                        ->label('Reason / Justification for Change')
                        ->placeholder('Explain why you are requesting to update your sensitive information (e.g., CNIC renewal, new salary bank account).')
                        ->required()
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->action(function (Action $action, array $data): void {
                    try {
                        $user = $this->getUser();
                        $employee = $user->employee ?? Employee::where('user_id', $user->id)->firstOrFail();

                        $changes = [];
                        if (array_key_exists('identification_id', $data) && $data['identification_id'] !== $employee->identification_id) {
                            $changes['identification_id'] = $data['identification_id'];
                        }
                        if (array_key_exists('passport_id', $data) && $data['passport_id'] !== $employee->passport_id) {
                            $changes['passport_id'] = $data['passport_id'];
                        }
                        if (array_key_exists('bank_account_id', $data) && $data['bank_account_id'] != $employee->bank_account_id) {
                            $changes['bank_account_id'] = $data['bank_account_id'];
                        }

                        if (empty($changes)) {
                            Notification::make()
                                ->title('No Changes Detected')
                                ->body('Please provide at least one modified value to submit a change request.')
                                ->warning()
                                ->send();

                            return;
                        }

                        $req = app(EmployeeSensitiveChangeService::class)->submit(
                            $employee,
                            $user,
                            $changes,
                            $data['reason'] ?? null,
                        );

                        Notification::make()
                            ->title('Sensitive Data Update Request Submitted')
                            ->body('Your request has been routed to HR for review: '.app(ApprovalEngine::class)->describeCurrentApprover($req))
                            ->success()
                            ->duration(6000)
                            ->send();
                    } catch (Exception $e) {
                        Notification::make()
                            ->title('Failed to Submit Request')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    protected function getViewData(): array
    {
        $user = $this->getUser();
        $employee = $user->employee ?? Employee::where('user_id', $user->id)->first();
        $leaveSummary = null;
        $attendanceSummary = null;

        if ($employee) {
            $companyId = (int) ($employee->company_id ?? $user->default_company_id ?? 1);
            $endOfYear = Carbon::now()->endOfYear();

            $leaveSummary = EmployeeDashboardOverviewWidget::calculateCanonicalLeaves($employee, $companyId, $endOfYear);

            // Attendance & time-ins analysis for employee profile
            $timezone = app(AttendanceScheduleResolver::class)->timezoneFor($employee);
            $today = Carbon::today($timezone);

            $todayState = null;
            try {
                $todayState = app(GeofencedAttendanceService::class)->todayState($user);
            } catch (\Throwable) {
                // Ignore if service cannot resolve shift state
            }

            $todayRecord = AttendanceRecord::query()
                ->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $today->toDateString())
                ->first();

            $recentRecords = AttendanceRecord::query()
                ->where('employee_id', $employee->id)
                ->orderByDesc('attendance_date')
                ->take(7)
                ->get()
                ->map(function (AttendanceRecord $r) use ($timezone): array {
                    $dateStr = $r->attendance_date ? Carbon::parse($r->attendance_date)->format('D, d M') : '—';
                    $checkInStr = $r->check_in ? Carbon::parse($r->check_in)->timezone($timezone)->format('h:i A') : '—';
                    $checkOutStr = $r->check_out ? Carbon::parse($r->check_out)->timezone($timezone)->format('h:i A') : '—';
                    $workedHours = $r->worked_hours !== null ? number_format((float) $r->worked_hours, 1).'h' : '—';

                    $statusValue = (string) ($r->status ?? 'present');
                    $statusLabel = match ($statusValue) {
                        'present'  => 'Present',
                        'half_day' => 'Half Day',
                        'late'     => 'Late',
                        'absent'   => 'Absent',
                        'leave'    => 'On Leave',
                        'holiday'  => 'Holiday',
                        'remote'   => 'Remote',
                        default    => ucfirst(str_replace('_', ' ', $statusValue)),
                    };

                    $statusColor = match ($statusValue) {
                        'present', 'remote' => 'success',
                        'half_day', 'late'  => 'warning',
                        'absent'            => 'danger',
                        'leave', 'holiday'  => 'info',
                        default             => 'gray',
                    };

                    return [
                        'id'           => $r->id,
                        'date'         => $dateStr,
                        'check_in'     => $checkInStr,
                        'check_out'    => $checkOutStr,
                        'worked_hours' => $workedHours,
                        'status_label' => $statusLabel,
                        'status_color' => $statusColor,
                        'late_minutes' => (int) ($r->late_minutes ?? 0),
                    ];
                })
                ->all();

            $monthStart = $today->copy()->startOfMonth();
            $monthRecords = AttendanceRecord::query()
                ->where('employee_id', $employee->id)
                ->whereDate('attendance_date', '>=', $monthStart->toDateString())
                ->whereDate('attendance_date', '<=', $today->toDateString())
                ->get();

            $daysPresent = $monthRecords->whereIn('status', ['present', 'half_day', 'remote'])->count();
            $totalHours = (float) $monthRecords->sum('worked_hours');

            $attendanceSummary = [
                'today_state'    => $todayState,
                'today_record'   => $todayRecord,
                'recent_records' => $recentRecords,
                'days_present'   => $daysPresent,
                'total_hours'    => round($totalHours, 1),
                'checkin_url'    => url('/admin/employees/my-attendance'),
            ];
        }

        return [
            'user'              => $user,
            'employee'          => $employee,
            'leaveSummary'      => $leaveSummary,
            'attendanceSummary' => $attendanceSummary,
        ];
    }

    public function multiFactorAuthenticationSchema(Schema $schema): Schema
    {
        $user = Filament::auth()->user();

        return $schema
            ->components([
                Section::make(__('filament-panels::auth/pages/edit-profile.multi_factor_authentication.label'))
                    ->schema(collect(Filament::getMultiFactorAuthenticationProviders())
                        ->sort(fn (MultiFactorAuthenticationProvider $multiFactorAuthenticationProvider): int => $multiFactorAuthenticationProvider->isEnabled($user) ? 0 : 1)
                        ->map(fn (MultiFactorAuthenticationProvider $multiFactorAuthenticationProvider): Component => Group::make($multiFactorAuthenticationProvider->getManagementSchemaComponents())
                            ->statePath($multiFactorAuthenticationProvider->getId()))
                        ->all()),
            ]);
    }
}
